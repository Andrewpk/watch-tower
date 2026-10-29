<?php

namespace App\Watch;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Str;

/**
 * Translates raw Nightwatch wire records (as produced by the SDK sensors and
 * forwarded by agent.phar) into the {type, id, data} envelope that
 * EventStore consumes.
 *
 * Returns null for record types we don't (yet) persist.
 *
 * The wire `deploy` is the deployment id (`nightwatch.deployment`), not an environment name: it is stored as the
 * release on requests and exceptions, and the wire carries no environment name, so no translation sets one.
 *
 * Every string is cut to its column length and non-scalar values are dropped, so one malformed record cannot
 * fail the whole batch.
 */
class NightwatchTranslator
{
    /**
     * @param  array<string, mixed>  $record
     * @return array{type: string, id: string, data: array<string, mixed>}|null
     */
    public function translate(array $record): ?array
    {
        $type = $record['t'] ?? null;

        return match ($type) {
            'request' => $this->translateRequest($record),
            'query' => $this->translateQuery($record),
            'exception' => $this->translateException($record),
            'queued-job' => $this->translateQueuedJob($record),
            'job-attempt' => $this->translateJobAttempt($record),
            'log' => $this->translateLog($record),
            'cache-event' => $this->translateCacheEvent($record),
            'command' => $this->translateCommand($record),
            'scheduled-task' => $this->translateScheduledTask($record),
            'mail' => $this->translateMail($record),
            'notification' => $this->translateNotification($record),
            'outgoing-request' => $this->translateOutgoingRequest($record),
            'user' => $this->translateUser($record),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateRequest(array $r): array
    {
        $traceId = $this->limit($r['trace_id'] ?? null, 255) ?? '';

        return [
            'type' => 'request',
            'id' => $traceId,
            'data' => [
                'request_id' => $traceId,
                'method' => strtoupper($this->limit($r['method'] ?? null, 10) ?? 'GET'),
                'uri' => mb_substr($this->scalarString($r['route_path'] ?? $r['url'] ?? '/'), 0, 2048),
                'status_code' => $this->boundedInt($r['status_code'] ?? null, 65535),
                'user_id' => $this->limit($r['user'] ?? null, 255),
                'duration_ms' => $this->unsignedDurationMs($r['duration'] ?? null),
                'db_queries_count' => (int) ($r['queries'] ?? 0),
                'memory_used_kb' => $this->bytesToKilobytes($r['peak_memory_usage'] ?? null),
                'memory_peak_kb' => $this->bytesToKilobytes($r['peak_memory_usage'] ?? null),
                'release' => $this->limit($r['deploy'] ?? null, 255),
                'hostname' => $this->limit($r['server'] ?? null, 255),
                'ip_address' => $this->limit($r['ip'] ?? null, 45),
                'headers' => $this->decodeMaybeJson($r['headers'] ?? null),
                'request_data' => $this->decodeMaybeJson($r['payload'] ?? null),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateQuery(array $r): array
    {
        return [
            'type' => 'query',
            'id' => (string) ($r['_group'] ?? ''),
            'data' => [
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
                'sql' => (string) ($r['sql'] ?? ''),
                'connection' => $this->stringOrNull($r['connection'] ?? null),
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateException(array $r): array
    {
        return [
            'type' => 'exception',
            'id' => (string) ($r['_group'] ?? ''),
            'data' => [
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
                'class' => (string) ($r['class'] ?? 'Exception'),
                'message' => (string) ($r['message'] ?? ''),
                'file' => (string) ($r['file'] ?? ''),
                'line' => $this->intOrNull($r['line'] ?? null),
                'trace' => $this->decodeMaybeJson($r['trace'] ?? null),
                'user_id' => $this->stringOrNull($r['user'] ?? null),
                'environment' => $this->stringOrNull($r['deploy'] ?? null),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateQueuedJob(array $r): array
    {
        return [
            'type' => 'job',
            'id' => $this->scalarString($r['job_id'] ?? null),
            'data' => [
                'job_id' => $this->limit($r['job_id'] ?? null, 64),
                'request_id' => $this->limit($r['trace_id'] ?? null, 255),
                'class' => $this->limit($r['name'] ?? null, 255) ?? 'UnknownJob',
                'queue' => $this->limit($r['queue'] ?? null, 100),
                'connection' => $this->limit($r['connection'] ?? null, 100),
                'status' => 'queued',
                'user_id' => $this->limit($r['user'] ?? null, 255),
                'dispatched_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateLog(array $r): array
    {
        return [
            'type' => 'log',
            'id' => $this->scalarString($r['trace_id'] ?? null),
            'data' => [
                'request_id' => $this->limit($r['trace_id'] ?? null, 255),
                'level' => $this->limit($r['level'] ?? null, 20) ?? 'info',
                'message' => $this->scalarString($r['message'] ?? null),
                'source_type' => $this->limit($r['execution_source'] ?? null, 50),
                'source_label' => $this->limit($r['execution_preview'] ?? null, 255),
                'user_name' => $this->limit($r['user'] ?? null, 255),
                'context' => $this->decodeMaybeJson($r['context'] ?? null),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateCacheEvent(array $r): array
    {
        return [
            'type' => 'cache-event',
            'id' => $this->scalarString($r['_group'] ?? null),
            'data' => [
                'request_id' => $this->limit($r['trace_id'] ?? null, 255),
                'key' => $this->limit($r['key'] ?? null, 255) ?? '',
                'store' => $this->limit($r['store'] ?? null, 50),
                'operation' => mb_substr($this->normalizeCacheOperation($r['type'] ?? null), 0, 20),
                'succeeded' => $this->cacheSucceeded($r),
                'duration_ms' => $this->unsignedDurationMs($r['duration'] ?? null),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * One attempt of a job. `duration` is the attempt's own run time, so it becomes the job run's duration; the
     * finish time is derived from the start time plus that duration.
     *
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateJobAttempt(array $r): array
    {
        $status = match ($r['status'] ?? null) {
            'processed' => 'completed',
            'released' => 'released',
            'failed' => 'failed',
            default => $this->limit($r['status'] ?? null, 50) ?? 'unknown',
        };

        $finishedAt = $this->timestampPlusMicrosToIso($r['timestamp'] ?? null, $r['duration'] ?? null);
        $preview = $this->stringOrNull($r['exception_preview'] ?? null);

        return [
            'type' => 'job-attempt',
            'id' => $this->scalarString($r['attempt_id'] ?? null),
            'data' => [
                'job_id' => $this->limit($r['job_id'] ?? null, 64),
                'request_id' => $this->limit($r['trace_id'] ?? null, 255),
                'class' => $this->limit($r['name'] ?? null, 255) ?? 'UnknownJob',
                'queue' => $this->limit($r['queue'] ?? null, 100),
                'connection' => $this->limit($r['connection'] ?? null, 100),
                'status' => $status,
                'attempts' => (int) ($r['attempt'] ?? 1),
                'started_at' => $this->timestampToIso($r['timestamp'] ?? null),
                'completed_at' => $status === 'completed' ? $finishedAt : null,
                'failed_at' => $status === 'failed' ? $finishedAt : null,
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                'exception' => $preview === null ? null : ['message' => $preview],
                'user_id' => $this->limit($r['user'] ?? null, 255),
            ],
        ];
    }

    /**
     * Add a duration in microseconds to a Unix timestamp and format the result; a missing duration adds nothing.
     */
    private function timestampPlusMicrosToIso(mixed $timestamp, mixed $micros): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        return $this->timestampToIso((float) $timestamp + ((int) $micros) / 1_000_000);
    }

    /**
     * Artisan command run, keyed on its trace id. A command is `completed` only for exit code 0; the raw argv is
     * split into arguments and options on a best-effort basis, and the command output is not on the wire.
     *
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateCommand(array $r): array
    {
        $traceId = $this->scalarString($r['trace_id'] ?? null);
        $name = $this->limit($r['name'] ?? null, 255);
        $exitCode = $this->intOrNull($r['exit_code'] ?? null);
        ['arguments' => $arguments, 'options' => $options] = $this->splitCommandLine($r['command'] ?? null, $name);

        return [
            'type' => 'command',
            'id' => $traceId,
            'data' => [
                'request_id' => $this->limit($r['trace_id'] ?? null, 255),
                'command' => $name,
                'arguments' => $arguments,
                'options' => $options,
                'status' => $exitCode === null || $exitCode === 0 ? 'completed' : 'failed',
                'exit_code' => $exitCode,
                'duration_ms' => $this->unsignedDurationMs($r['duration'] ?? null),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * Scheduled task run, keyed on its trace id (every run gets a new one). The task is identified by a 40-character
     * sha1 over its name, cron expression, timezone and repeat interval, which is what the task detail route accepts.
     * A skipped run never executed, so it has no duration. Exit code and output are not on the wire.
     *
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateScheduledTask(array $r): array
    {
        $traceId = $this->scalarString($r['trace_id'] ?? null);
        $status = $this->limit($r['status'] ?? null, 50) ?? 'processed';

        return [
            'type' => 'scheduled-task',
            'id' => $traceId,
            'data' => [
                'request_id' => $this->limit($r['trace_id'] ?? null, 255),
                'task' => $this->limit($r['name'] ?? null, 255),
                'task_hash' => $this->scheduledTaskHash($r),
                'schedule' => $this->limit($r['cron'] ?? null, 255),
                'next_run_at' => $this->nextRunAt($r),
                'status' => $status,
                'duration_ms' => $status === 'skipped' ? null : $this->unsignedDurationMs($r['duration'] ?? null),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * Split a raw argv string (`app:sync-users --force 42`) into positional arguments and `-` prefixed options.
     * The first non-option token equal to the command name is dropped, so a leading global option such as
     * `--env=staging app:sync-users` still works. Both lists are null when empty.
     *
     * Upstream joins argv with spaces and loses quoting, so the value of `--opt value` lands in the arguments.
     *
     * @return array{arguments: list<string>|null, options: list<string>|null}
     */
    private function splitCommandLine(mixed $line, ?string $name): array
    {
        $tokens = is_string($line) ? preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) : [];

        $arguments = [];
        $options = [];
        $nameDropped = false;

        foreach ($tokens ?: [] as $token) {
            if (str_starts_with($token, '-')) {
                $options[] = $token;
            } elseif (! $nameDropped && $token === $name) {
                $nameDropped = true;
            } else {
                $arguments[] = $token;
            }
        }

        return [
            'arguments' => $arguments === [] ? null : $arguments,
            'options' => $options === [] ? null : $options,
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function scheduledTaskHash(array $r): string
    {
        $key = implode(',', [
            $this->scalarString($r['name'] ?? null),
            $this->scalarString($r['cron'] ?? null),
            $this->scalarString($r['timezone'] ?? null),
        ]);
        $repeatSeconds = (int) ($r['repeat_seconds'] ?? 0);

        return sha1($repeatSeconds > 0 ? $key.','.$repeatSeconds : $key);
    }

    /**
     * The next time the task is due after this run, as a UTC ISO string; null when the cron expression or timezone
     * cannot be evaluated. A sub-minute task (`repeat_seconds`) repeats only inside the minute its cron matched, so
     * it is due again one interval later when that stays within the same minute, and otherwise at the next cron match.
     *
     * @param  array<string, mixed>  $r
     */
    private function nextRunAt(array $r): ?string
    {
        $timestamp = $r['timestamp'] ?? null;
        $cron = $r['cron'] ?? null;

        if (! is_numeric($timestamp) || ! is_string($cron) || $cron === '') {
            return null;
        }

        try {
            $from = CarbonImmutable::createFromTimestamp((float) $timestamp);
            $repeatSeconds = (int) ($r['repeat_seconds'] ?? 0);

            if ($repeatSeconds > 0) {
                $repeat = $from->addSeconds($repeatSeconds);

                if ($repeat->startOfMinute()->equalTo($from->startOfMinute())) {
                    return $repeat->utc()->toIso8601String();
                }
            }

            $timezone = is_string($r['timezone'] ?? null) && $r['timezone'] !== '' ? $r['timezone'] : null;

            return CarbonImmutable::instance((new CronExpression($cron))->getNextRunDate($from, 0, false, $timezone))
                ->utc()
                ->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Microseconds to milliseconds, clamped to the unsigned 32-bit range of the `duration_ms` columns (a
     * long-running daemon command would otherwise overflow them on MySQL).
     */
    private function unsignedDurationMs(mixed $value): ?int
    {
        $millis = $this->microsToMillis($value);

        return $millis === null ? null : max(0, min($millis, 4_294_967_295));
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * `to`, `cc`, `bcc` and `attachments` are counts on the wire, not lists. The sensor never reports a failed
     * send, so the status is only ever `failed` if a future agent starts setting the flag.
     *
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateMail(array $r): array
    {
        return [
            'type' => 'mail',
            'id' => $this->scalarString($r['_group'] ?? null),
            'data' => [
                'request_id' => $this->limit($r['trace_id'] ?? null, 255),
                'class' => $this->limit($this->withoutAnonymousSuffix($r['class'] ?? null), 255) ?? 'UnknownMailable',
                'mailer' => $this->limit($r['mailer'] ?? null, 50),
                'subject' => $this->limit($r['subject'] ?? null, 255),
                'recipients_count' => (int) ($r['to'] ?? 0) + (int) ($r['cc'] ?? 0) + (int) ($r['bcc'] ?? 0),
                'attachments_count' => (int) ($r['attachments'] ?? 0),
                'status' => ($r['failed'] ?? false) ? 'failed' : 'sent',
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                ...$this->sourceFields($r),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateNotification(array $r): array
    {
        return [
            'type' => 'notification',
            'id' => $this->scalarString($r['_group'] ?? null),
            'data' => [
                'request_id' => $this->limit($r['trace_id'] ?? null, 255),
                'class' => $this->limit($this->withoutAnonymousSuffix($r['class'] ?? null), 255) ?? 'UnknownNotification',
                'channel' => $this->limit($this->channelName($r['channel'] ?? null), 50) ?? 'unknown',
                'status' => ($r['failed'] ?? false) ? 'failed' : 'sent',
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                ...$this->sourceFields($r),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * The wire `timestamp` is the start of the request, which is what `occurred_at` records.
     *
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}
     */
    private function translateOutgoingRequest(array $r): array
    {
        return [
            'type' => 'outgoing-request',
            'id' => $this->scalarString($r['_group'] ?? null),
            'data' => [
                'request_id' => $this->limit($r['trace_id'] ?? null, 255),
                'method' => strtoupper($this->limit($r['method'] ?? null, 10) ?? 'GET'),
                'host' => $this->limit($r['host'] ?? null, 255) ?? '',
                'url' => $this->limit($r['url'] ?? null, 2048) ?? '',
                'status_code' => $this->boundedInt($r['status_code'] ?? null, 65535),
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                'request_size_bytes' => $this->boundedInt($r['request_size'] ?? null, 4294967295),
                'response_size_bytes' => $this->boundedInt($r['response_size'] ?? null, 4294967295),
                ...$this->sourceFields($r),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * The execution context (what ran when the record was captured) shared by mail, notification and outgoing
     * request records, cut to the `source_*` column lengths.
     *
     * @param  array<string, mixed>  $r
     * @return array{source_type: string|null, source_id: string|null, source_label: string|null}
     */
    private function sourceFields(array $r): array
    {
        return [
            'source_type' => $this->limit($r['execution_source'] ?? null, 50),
            'source_id' => $this->limit($r['execution_id'] ?? null, 255),
            'source_label' => $this->limit($r['execution_preview'] ?? null, 255),
        ];
    }

    /**
     * Cut an anonymous class name at its NUL byte, which is followed by the server file path.
     */
    private function withoutAnonymousSuffix(mixed $value): mixed
    {
        if (is_string($value) && str_contains($value, "@anonymous\0")) {
            return Str::before($value, "\0");
        }

        return $value;
    }

    /**
     * A notification channel is whatever `via()` returned: a short name like `mail`, or a fully qualified class
     * name, which is reduced to its base name so the 50 character cut does not chop off the identifying end.
     */
    private function channelName(mixed $value): mixed
    {
        if (is_string($value) && str_contains($value, '\\')) {
            return class_basename($value);
        }

        return $value;
    }

    /**
     * An integer for an unsigned column: null when the value is not numeric or falls outside 0..$max, which
     * would otherwise fail the insert under MySQL strict mode.
     */
    private function boundedInt(mixed $value, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int >= 0 && $int <= $max ? $int : null;
    }

    /**
     * Translate a `user` record into the details of an end user.
     *
     * `id` is the same value the other records carry in their `user` field, `name` and `username` are the display
     * name and (by default) the email address. Returns null when the record has no id to attach the details to.
     *
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array{user_id: string, name: string|null, email: string|null, occurred_at: string|null}}|null
     */
    private function translateUser(array $r): ?array
    {
        $userId = $this->limit($r['id'] ?? null, 255);

        if ($userId === null) {
            return null;
        }

        return [
            'type' => 'user',
            'id' => $userId,
            'data' => [
                'user_id' => $userId,
                'name' => $this->limit($r['name'] ?? null, 255),
                'email' => $this->limit($r['username'] ?? null, 255),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    private function normalizeCacheOperation(mixed $value): string
    {
        $op = is_string($value) ? strtolower($value) : '';

        return match ($op) {
            'hit', 'miss', 'write', 'delete' => $op,
            'forget' => 'delete',
            default => $op === '' ? 'unknown' : $op,
        };
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function cacheSucceeded(array $r): bool
    {
        if (array_key_exists('succeeded', $r)) {
            return (bool) $r['succeeded'];
        }

        $op = is_string($r['type'] ?? null) ? strtolower($r['type']) : '';

        return ! in_array($op, ['write-failed', 'forget-failed', 'failed'], true);
    }

    private function microsToMillis(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(((int) $value) / 1000);
    }

    private function bytesToKilobytes(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(((int) $value) / 1024);
    }

    private function timestampToIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((float) $value)->toIso8601String();
    }

    private function decodeMaybeJson(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Stringify a scalar and cut it to the column length; non-scalars become null.
     */
    private function limit(mixed $value, int $length): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = $this->stringOrNull($value);

        return $string === null ? null : mb_substr($string, 0, $length);
    }
}
