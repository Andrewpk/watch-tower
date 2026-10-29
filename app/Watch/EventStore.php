<?php

namespace App\Watch;

use App\Models\CacheEvent;
use App\Models\EndUser;
use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\EventOccurrence;
use App\Models\LogEntry;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\Trace;
use App\Models\TraceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventStore
{
    /**
     * Every event type the store accepts; shared with the legacy ingest request validation.
     *
     * @var list<string>
     */
    public const TYPES = ['request', 'query', 'exception', 'event', 'job', 'job-attempt', 'log', 'cache-event', 'command', 'scheduled-task', 'mail', 'notification', 'outgoing-request', 'user'];

    public function __construct(private readonly Fingerprinter $fingerprinter) {}

    /**
     * Persist a single ingested event for a project.
     *
     * @param  array{type: string, id?: string, data: array<string, mixed>}  $event
     */
    public function store(Project $project, array $event): void
    {
        match ($event['type']) {
            'request' => $this->storeRequest($project, $event['data']),
            'query' => $this->storeQuery($project, $event['data']),
            'exception' => $this->storeException($project, $event['data']),
            'event' => $this->storeEvent($project, $event['data']),
            'job' => $this->storeJob($project, $event['data']),
            'job-attempt' => $this->storeJobAttempt($project, $event['data']),
            'log' => $this->storeLog($project, $event['data']),
            'cache-event' => $this->storeCacheEvent($project, $event['data']),
            'command' => $this->storeCommand($project, $event['data']),
            'scheduled-task' => $this->storeScheduledTask($project, $event['data']),
            'mail' => $this->storeMail($project, $event['data']),
            'notification' => $this->storeNotification($project, $event['data']),
            'outgoing-request' => $this->storeOutgoingRequest($project, $event['data']),
            'user' => $this->storeUser($project, $event['data']),
            default => null,
        };
    }

    /**
     * Persist the request record, filling in the stub trace if child records arrived first.
     *
     * The trace is keyed on (project, correlation id), so a request can never touch another project's trace.
     * `has_errors` and `has_slow_queries` are only ever raised: a request record that does not report them must not
     * undo what the child records already flagged. The same goes for `user_name` and `user_email`, which a `user`
     * record may have filled in already. When the request carries a user but no details were known while storing it,
     * the end user is read again afterwards, see `completeUserDetails()`.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeRequest(Project $project, array $data): void
    {
        $correlationId = (string) ($data['request_id'] ?? $data['correlation_id'] ?? '');

        if ($correlationId === '') {
            return;
        }

        $userIdentifier = $this->stringOrNull($data['user_id'] ?? null);
        $userDetails = $this->userDetails($project, $userIdentifier, $data);

        $values = [
            'method' => (string) ($data['method'] ?? 'GET'),
            'uri' => (string) ($data['uri'] ?? '/'),
            'status_code' => $this->intOrNull($data['status_code'] ?? null),
            'user_identifier' => $userIdentifier,
            ...$userDetails,
            'duration_ms' => $this->intOrNull($data['duration_ms'] ?? null),
            'db_queries_count' => (int) ($data['db_queries_count'] ?? 0),
            'db_time_ms' => (int) ($data['db_time_ms'] ?? 0),
            'memory_used_kb' => $this->intOrNull($data['memory_used_kb'] ?? null),
            'memory_peak_kb' => $this->intOrNull($data['memory_peak_kb'] ?? null),
            'environment' => $this->stringOrNull($data['environment'] ?? null),
            'release_version' => $this->stringOrNull($data['release'] ?? null),
            'hostname' => $this->stringOrNull($data['hostname'] ?? null),
            'ip_address' => $this->stringOrNull($data['ip_address'] ?? null),
            'user_agent' => $this->stringOrNull($data['user_agent'] ?? null),
            'headers' => $data['headers'] ?? null,
            'request_data' => $data['request_data'] ?? null,
            'response_data' => $data['response_data'] ?? null,
            'is_stub' => false,
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? $data['timestamp'] ?? null),
            ...array_filter([
                'has_errors' => (bool) ($data['has_errors'] ?? false),
                'has_slow_queries' => (bool) ($data['has_slow_queries'] ?? false),
            ]),
        ];

        $trace = Trace::firstOrCreate(
            ['project_id' => $project->id, 'correlation_id' => $correlationId],
            $values,
        );

        if (! $trace->wasRecentlyCreated) {
            $trace->fill($values)->save();
        }

        if ($userIdentifier !== null && $userDetails === []) {
            $this->completeUserDetails(Trace::class, $project, $trace->id, $userIdentifier);
        }
    }

    /**
     * Persist a query against its trace, which is created as a stub if the request record has not arrived yet.
     *
     * A query without any correlation id has no trace to belong to (`trace_id` is required), so it is the only case
     * that is dropped, and it is logged rather than discarded silently.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeQuery(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        if (! $trace) {
            Log::warning('Dropped a query record that carries no correlation id.', ['project_id' => $project->id]);

            return;
        }

        TraceQuery::create([
            'project_id' => $project->id,
            'trace_id' => $trace->id,
            'query_type' => $this->detectQueryType((string) ($data['sql'] ?? '')),
            'sql' => (string) ($data['sql'] ?? ''),
            'bindings' => $data['bindings'] ?? null,
            'connection_name' => $this->stringOrNull($data['connection'] ?? null),
            'duration_ms' => (float) ($data['duration_ms'] ?? 0),
            'row_count' => $this->intOrNull($data['row_count'] ?? null),
            'is_n_plus_one' => (bool) ($data['is_n_plus_one'] ?? false),
            'n_plus_one_group' => $this->stringOrNull($data['n_plus_one_group'] ?? null),
            'is_slow' => (bool) ($data['is_slow'] ?? false),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeException(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        $fingerprint = $this->fingerprinter->forException($data);
        $exceptionClass = (string) ($data['class'] ?? $data['exception_class'] ?? 'Exception');
        $message = $this->stringOrNull($data['message'] ?? null);
        $file = $this->stringOrNull($data['file'] ?? null);
        $line = $this->intOrNull($data['line'] ?? null);
        $occurredAt = $this->parseTimestamp($data['occurred_at'] ?? null);
        $userIdentifier = $this->stringOrNull($data['user_id'] ?? null);
        $userDetails = $this->userDetails($project, $userIdentifier, $data);

        $occurrence = DB::transaction(function () use ($project, $trace, $fingerprint, $exceptionClass, $message, $file, $line, $occurredAt, $data, $userIdentifier, $userDetails) {
            $group = ErrorGroup::where('project_id', $project->id)
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($group) {
                $this->recordRecurrence($group, $occurredAt);
            } else {
                $nextDisplayNumber = (int) (ErrorGroup::where('project_id', $project->id)->max('display_number') ?? 0) + 1;

                $group = ErrorGroup::create([
                    'project_id' => $project->id,
                    'fingerprint' => $fingerprint,
                    'display_number' => $nextDisplayNumber,
                    'exception_class' => $exceptionClass,
                    'first_message' => $message,
                    'first_file' => $file,
                    'first_line' => $line,
                    'total_count' => 1,
                    'first_occurrence_at' => $occurredAt,
                    'last_occurrence_at' => $occurredAt,
                    'status' => 'unresolved',
                ]);
            }

            $occurrence = ErrorOccurrence::create([
                'project_id' => $project->id,
                'trace_id' => $trace?->id,
                'error_group_id' => $group->id,
                'exception_class' => $exceptionClass,
                'message' => $message,
                'stacktrace' => $data['trace'] ?? $data['stacktrace'] ?? [],
                'fingerprint' => $fingerprint,
                'user_identifier' => $userIdentifier,
                'user_name' => $userDetails['user_name'] ?? null,
                'user_email' => $userDetails['user_email'] ?? null,
                'file' => $file,
                'line' => $line,
                'environment' => $this->stringOrNull($data['environment'] ?? null),
                'release_version' => $this->stringOrNull($data['release'] ?? null),
                'context' => $data['context'] ?? null,
                'occurred_at' => $occurredAt,
            ]);

            if ($trace) {
                $trace->forceFill(['has_errors' => true])->save();
            }

            return $occurrence;
        });

        if ($userIdentifier !== null && $userDetails === []) {
            $this->completeUserDetails(ErrorOccurrence::class, $project, $occurrence->id, $userIdentifier);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeEvent(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        EventOccurrence::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'event_class' => (string) ($data['class'] ?? $data['event_class'] ?? 'UnknownEvent'),
            'fired_by' => $this->stringOrNull($data['fired_by'] ?? null),
            'duration_ms' => $this->intOrNull($data['duration_ms'] ?? null),
            'listeners_count' => (int) ($data['listeners_count'] ?? 0),
            'payload' => $data['payload'] ?? null,
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeJob(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        QueueJobRun::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'job_class' => (string) ($data['class'] ?? $data['job_class'] ?? 'UnknownJob'),
            'queue' => $this->stringOrNull($data['queue'] ?? null),
            'connection' => $this->stringOrNull($data['connection'] ?? null),
            'dispatched_at' => $this->parseTimestamp($data['dispatched_at'] ?? null, allowNull: true),
            'started_at' => $this->parseTimestamp($data['started_at'] ?? null, allowNull: true),
            'completed_at' => $this->parseTimestamp($data['completed_at'] ?? null, allowNull: true),
            'failed_at' => $this->parseTimestamp($data['failed_at'] ?? null, allowNull: true),
            'duration_ms' => $this->intOrNull($data['duration_ms'] ?? null),
            'attempts' => (int) ($data['attempts'] ?? 0),
            'status' => (string) ($data['status'] ?? 'pending'),
            'payload' => $data['payload'] ?? null,
            'exception' => $data['exception'] ?? null,
            'environment' => $this->stringOrNull($data['environment'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeCacheEvent(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        CacheEvent::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'key' => (string) ($data['key'] ?? ''),
            'store' => $this->stringOrNull($data['store'] ?? null),
            'operation' => (string) ($data['operation'] ?? 'unknown'),
            'succeeded' => (bool) ($data['succeeded'] ?? true),
            'duration_ms' => $this->intOrNull($data['duration_ms'] ?? null),
            'environment' => $this->stringOrNull($data['environment'] ?? null),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeJobAttempt(Project $project, array $data): void {}

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeCommand(Project $project, array $data): void {}

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeScheduledTask(Project $project, array $data): void {}

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeMail(Project $project, array $data): void {}

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeNotification(Project $project, array $data): void {}

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeOutgoingRequest(Project $project, array $data): void {}

    /**
     * Persist the name and email of an end user, and hand them to the traces and errors that were stored without.
     *
     * A `user` record is sent just before the `request` record it belongs to, but the two are processed by
     * independent queue jobs, so either can win. The two jobs cover each other: whichever comes second fills the
     * row. A request or exception stored first is filled here, and only when the end user is created, because
     * upstream sends a `user` record for every authenticated request and the backfill scans every retained row of
     * the user. One stored after the end user existed gets its details from `userDetails()`, or from
     * `completeUserDetails()` if the end user appeared in between.
     *
     * An existing end user is only updated by a record that is not older than the stored one, in a single
     * conditional UPDATE so that concurrent workers cannot move it backwards, and a missing value never erases a
     * stored one.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeUser(Project $project, array $data): void
    {
        $identifier = $this->stringOrNull($data['user_id'] ?? null);
        $name = $this->stringOrNull($data['name'] ?? null);
        $email = $this->stringOrNull($data['email'] ?? null);

        if ($identifier === null || ($name === null && $email === null)) {
            return;
        }

        $seenAt = $this->parseTimestamp($data['occurred_at'] ?? null);

        $user = EndUser::firstOrCreate(
            ['project_id' => $project->id, 'identifier' => $identifier],
            ['name' => $name, 'email' => $email, 'last_seen_at' => $seenAt],
        );

        if (! $user->wasRecentlyCreated) {
            EndUser::whereKey($user->id)
                ->where('last_seen_at', '<=', $seenAt)
                ->update([
                    ...array_filter(['name' => $name, 'email' => $email], fn (?string $value): bool => $value !== null),
                    'last_seen_at' => $seenAt,
                ]);

            return;
        }

        $details = array_filter(
            ['user_name' => $name, 'user_email' => $email],
            fn (?string $value): bool => $value !== null,
        );

        foreach ([Trace::class, ErrorOccurrence::class] as $model) {
            $model::where('project_id', $project->id)
                ->where('user_identifier', $identifier)
                ->whereNull('user_name')
                ->whereNull('user_email')
                ->update($details);
        }
    }

    /**
     * The `user_name` and `user_email` to store with a record, leaving out whichever is unknown.
     *
     * Details sent with the record win; otherwise the ones a `user` record stored for the user are used. Unknown
     * values are left out rather than set to null so that spreading the result into an update never erases details
     * a `user` record backfilled.
     *
     * @param  array<string, mixed>  $data
     * @return array{user_name?: string, user_email?: string}
     */
    private function userDetails(Project $project, ?string $userIdentifier, array $data): array
    {
        $name = $this->stringOrNull($data['user_name'] ?? null);
        $email = $this->stringOrNull($data['user_email'] ?? null);

        if ($userIdentifier !== null && $name === null && $email === null) {
            $user = EndUser::where('project_id', $project->id)->where('identifier', $userIdentifier)->first();

            $name = $user?->name;
            $email = $user?->email;
        }

        return array_filter(
            ['user_name' => $name, 'user_email' => $email],
            fn (?string $value): bool => $value !== null,
        );
    }

    /**
     * Fill the user details of one freshly stored trace or error occurrence if its end user turned up meanwhile.
     *
     * The record was stored without details because no end user existed when `userDetails()` looked. A `user`
     * record processed in the meantime may have missed the row when it backfilled, so look again now that the row
     * is stored. Only this row is touched, by primary key, and only if it still has no details.
     *
     * @param  class-string<Trace|ErrorOccurrence>  $model
     */
    private function completeUserDetails(string $model, Project $project, string $rowId, string $userIdentifier): void
    {
        $user = EndUser::where('project_id', $project->id)->where('identifier', $userIdentifier)->first();

        if ($user === null) {
            return;
        }

        $details = array_filter(
            ['user_name' => $user->name, 'user_email' => $user->email],
            fn (?string $value): bool => $value !== null,
        );

        if ($details === []) {
            return;
        }

        $model::whereKey($rowId)->whereNull('user_name')->whereNull('user_email')->update($details);
    }

    /**
     * Count another occurrence against an existing error group.
     *
     * The counter is bumped with a single atomic UPDATE and `last_occurrence_at`
     * never moves backwards, so out-of-order events cannot rewind it. A resolved
     * group is reopened and the regression is logged on its timeline only when
     * the occurrence happened after it was resolved; an older event that was
     * still queued when the fix shipped is counted but leaves the group
     * resolved. An ignored group keeps its status.
     */
    private function recordRecurrence(ErrorGroup $group, CarbonImmutable $occurredAt): void
    {
        $attributes = [];

        if ($group->last_occurrence_at === null || $occurredAt->greaterThan($group->last_occurrence_at)) {
            $attributes['last_occurrence_at'] = $occurredAt;
        }

        $regression = null;

        $isRegression = $group->status === 'resolved'
            && ($group->resolved_at === null || $occurredAt->greaterThan($group->resolved_at));

        if ($isRegression) {
            $regression = [
                'previous_status' => 'resolved',
                'resolved_at' => $group->resolved_at?->toIso8601String(),
                'resolved_by_user_id' => $group->resolved_by_user_id,
                'occurred_at' => $occurredAt->toIso8601String(),
            ];

            $attributes += [
                'status' => 'unresolved',
                'resolved_at' => null,
                'resolved_by_user_id' => null,
            ];
        }

        $group->increment('total_count', 1, $attributes);

        if ($regression !== null) {
            $group->comments()->create([
                'user_id' => null,
                'type' => 'regression',
                'body' => 'Reopened automatically: the error occurred again after it was resolved.',
                'meta' => $regression,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeLog(Project $project, array $data): void
    {
        $trace = $this->resolveTrace($project, $data);

        $context = $data['context'] ?? null;
        if ($context === [] || $context === '') {
            $context = null;
        }

        LogEntry::create([
            'project_id' => $project->id,
            'trace_id' => $trace?->id,
            'level' => (string) ($data['level'] ?? 'info'),
            'message' => (string) ($data['message'] ?? ''),
            'source_type' => $this->stringOrNull($data['source_type'] ?? null),
            'source_label' => $this->stringOrNull($data['source_label'] ?? null),
            'user_name' => $this->stringOrNull($data['user_name'] ?? null),
            'context' => is_array($context) ? $context : null,
            'environment' => $this->stringOrNull($data['environment'] ?? null),
            'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
        ]);
    }

    /**
     * Find the project's trace for a child record, creating a stub when the request record has not been ingested yet.
     *
     * Records are processed by independent queue jobs, so a child can arrive before its request. The stub is
     * flagged `is_stub` and carries placeholder request fields until `storeRequest` fills it in. Creation goes
     * through `firstOrCreate`, which catches the unique violation on (project_id, correlation_id) raised when a
     * concurrent job creates the same stub first and returns that row instead.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTrace(Project $project, array $data): ?Trace
    {
        $correlationId = $data['request_id'] ?? $data['correlation_id'] ?? null;

        if (! $correlationId) {
            return null;
        }

        return Trace::firstOrCreate(
            ['project_id' => $project->id, 'correlation_id' => (string) $correlationId],
            fn (): array => [
                'method' => '',
                'uri' => '',
                'is_stub' => true,
                'occurred_at' => $this->parseTimestamp($data['occurred_at'] ?? null),
            ],
        );
    }

    private function detectQueryType(string $sql): ?string
    {
        $trimmed = ltrim($sql);

        if ($trimmed === '') {
            return null;
        }

        $first = strtoupper(strtok($trimmed, " \t\n"));

        return in_array($first, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)
            ? $first
            : null;
    }

    private function parseTimestamp(mixed $value, bool $allowNull = false): ?CarbonImmutable
    {
        if (! $value) {
            return $allowNull ? null : CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return $allowNull ? null : CarbonImmutable::now();
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
