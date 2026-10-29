<?php

namespace App\Watch;

use Carbon\CarbonImmutable;

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
                'job_id' => $this->limit($r['job_id'] ?? null, 64),
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
                'class' => $this->limit($r['name'] ?? null, 255) ?? 'UnknownJob',
                'queue' => $this->limit($r['queue'] ?? null, 100),
                'connection' => $this->limit($r['connection'] ?? null, 100),
                'status' => 'queued',
                'user_id' => $this->limit($r['user'] ?? null, 255),
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
            'id' => (string) ($r['attempt_id'] ?? ''),
            'data' => [
                'job_id' => $this->limit($r['job_id'] ?? null, 64),
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
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
                'environment' => $this->stringOrNull($r['deploy'] ?? null),
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
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}|null
     */
    private function translateCommand(array $r): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{type: string, id: string, data: array<string, mixed>}|null
     */
    private function translateScheduledTask(array $r): ?array
    {
        return null;
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
