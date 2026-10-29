<?php

namespace App\Watch;

use Carbon\CarbonImmutable;
use Cron\CronExpression;

/**
 * Translates raw Nightwatch wire records (as produced by the SDK sensors and
 * forwarded by agent.phar) into the {type, id, data} envelope that
 * EventStore consumes.
 *
 * Returns null for record types we don't (yet) persist.
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
        $traceId = (string) ($r['trace_id'] ?? '');

        return [
            'type' => 'request',
            'id' => $traceId,
            'data' => [
                'request_id' => $traceId,
                'method' => (string) ($r['method'] ?? 'GET'),
                'uri' => (string) ($r['route_path'] ?? $r['url'] ?? '/'),
                'status_code' => $this->intOrNull($r['status_code'] ?? null),
                'user_id' => $this->stringOrNull($r['user'] ?? null),
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                'db_queries_count' => (int) ($r['queries'] ?? 0),
                'memory_used_kb' => $this->bytesToKilobytes($r['peak_memory_usage'] ?? null),
                'memory_peak_kb' => $this->bytesToKilobytes($r['peak_memory_usage'] ?? null),
                'environment' => $this->stringOrNull($r['deploy'] ?? null),
                'hostname' => $this->stringOrNull($r['server'] ?? null),
                'ip_address' => $this->stringOrNull($r['ip'] ?? null),
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
            'id' => (string) ($r['job_id'] ?? ''),
            'data' => [
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
                'class' => (string) ($r['name'] ?? 'UnknownJob'),
                'queue' => $this->stringOrNull($r['queue'] ?? null),
                'connection' => $this->stringOrNull($r['connection'] ?? null),
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                'status' => 'queued',
                'dispatched_at' => $this->timestampToIso($r['timestamp'] ?? null),
                'environment' => $this->stringOrNull($r['deploy'] ?? null),
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
            'id' => (string) ($r['trace_id'] ?? ''),
            'data' => [
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
                'level' => (string) ($r['level'] ?? 'info'),
                'message' => (string) ($r['message'] ?? ''),
                'source_type' => $this->stringOrNull($r['execution_source'] ?? null),
                'source_label' => $this->stringOrNull($r['execution_preview'] ?? null),
                'user_name' => $this->stringOrNull($r['user'] ?? null),
                'context' => $this->decodeMaybeJson($r['context'] ?? null),
                'environment' => $this->stringOrNull($r['deploy'] ?? null),
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
            'id' => (string) ($r['_group'] ?? ''),
            'data' => [
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
                'key' => (string) ($r['key'] ?? ''),
                'store' => $this->stringOrNull($r['store'] ?? null),
                'operation' => $this->normalizeCacheOperation($r['type'] ?? null),
                'succeeded' => $this->cacheSucceeded($r),
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                'environment' => $this->stringOrNull($r['deploy'] ?? null),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}|null
     */
    private function translateJobAttempt(array $r): ?array
    {
        return null;
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
        $traceId = (string) ($r['trace_id'] ?? '');
        $name = $this->limit($r['name'] ?? null, 255);
        $exitCode = $this->intOrNull($r['exit_code'] ?? null);
        ['arguments' => $arguments, 'options' => $options] = $this->splitCommandLine($r['command'] ?? null, $name);

        return [
            'type' => 'command',
            'id' => $traceId,
            'data' => [
                'request_id' => $this->stringOrNull($traceId),
                'command' => $name,
                'arguments' => $arguments,
                'options' => $options,
                'status' => $exitCode === null || $exitCode === 0 ? 'completed' : 'failed',
                'exit_code' => $exitCode,
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                'environment' => $this->limit($r['deploy'] ?? null, 50),
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
        $traceId = (string) ($r['trace_id'] ?? '');
        $status = $this->limit($r['status'] ?? null, 50) ?? 'processed';

        return [
            'type' => 'scheduled-task',
            'id' => $traceId,
            'data' => [
                'request_id' => $this->stringOrNull($traceId),
                'task' => $this->limit($r['name'] ?? null, 255),
                'task_hash' => $this->scheduledTaskHash($r),
                'schedule' => $this->limit($r['cron'] ?? null, 255),
                'next_run_at' => $this->nextRunAt($r),
                'status' => $status,
                'duration_ms' => $status === 'skipped' ? null : $this->microsToMillis($r['duration'] ?? null),
                'environment' => $this->limit($r['deploy'] ?? null, 50),
                'occurred_at' => $this->timestampToIso($r['timestamp'] ?? null),
            ],
        ];
    }

    /**
     * Split a raw argv string (`app:sync-users --force 42`) into positional arguments and `-` prefixed options.
     * The leading command name is dropped. Both lists are null when empty.
     *
     * @return array{arguments: list<string>|null, options: list<string>|null}
     */
    private function splitCommandLine(mixed $line, ?string $name): array
    {
        $tokens = is_string($line) ? preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) : [];

        if ($tokens !== [] && $tokens !== false && $tokens[0] === $name) {
            array_shift($tokens);
        }

        $arguments = [];
        $options = [];

        foreach ($tokens ?: [] as $token) {
            if (str_starts_with($token, '-')) {
                $options[] = $token;
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
        $key = implode(',', [(string) ($r['name'] ?? ''), (string) ($r['cron'] ?? ''), (string) ($r['timezone'] ?? '')]);
        $repeatSeconds = (int) ($r['repeat_seconds'] ?? 0);

        return sha1($repeatSeconds > 0 ? $key.','.$repeatSeconds : $key);
    }

    /**
     * The next time the task is due after this run, as a UTC ISO string; null when the cron expression or timezone
     * cannot be evaluated. Sub-minute tasks (`repeat_seconds`) are due again one interval after this run.
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
                return $from->addSeconds($repeatSeconds)->utc()->toIso8601String();
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
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}|null
     */
    private function translateMail(array $r): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}|null
     */
    private function translateNotification(array $r): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}|null
     */
    private function translateOutgoingRequest(array $r): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}|null
     */
    private function translateUser(array $r): ?array
    {
        return null;
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
        if ($value === null || $value === '') {
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
