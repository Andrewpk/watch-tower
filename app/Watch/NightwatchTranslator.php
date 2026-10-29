<?php

namespace App\Watch;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

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
            'id' => (string) ($r['_group'] ?? ''),
            'data' => [
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
                'class' => $this->limit($this->withoutAnonymousSuffix($r['class'] ?? null), 255) ?? 'UnknownMailable',
                'mailer' => $this->limit($r['mailer'] ?? null, 50),
                'subject' => $this->limit($r['subject'] ?? null, 255),
                'recipients_count' => (int) ($r['to'] ?? 0) + (int) ($r['cc'] ?? 0) + (int) ($r['bcc'] ?? 0),
                'attachments_count' => (int) ($r['attachments'] ?? 0),
                'status' => ($r['failed'] ?? false) ? 'failed' : 'sent',
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                ...$this->sourceFields($r),
                'environment' => $this->limit($r['deploy'] ?? null, 50),
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
            'id' => (string) ($r['_group'] ?? ''),
            'data' => [
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
                'class' => $this->limit($this->withoutAnonymousSuffix($r['class'] ?? null), 255) ?? 'UnknownNotification',
                'channel' => $this->limit($this->channelName($r['channel'] ?? null), 50) ?? 'unknown',
                'status' => ($r['failed'] ?? false) ? 'failed' : 'sent',
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                ...$this->sourceFields($r),
                'environment' => $this->limit($r['deploy'] ?? null, 50),
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
            'id' => (string) ($r['_group'] ?? ''),
            'data' => [
                'request_id' => $this->stringOrNull($r['trace_id'] ?? null),
                'method' => strtoupper($this->limit($r['method'] ?? null, 10) ?? 'GET'),
                'host' => $this->limit($r['host'] ?? null, 255) ?? '',
                'url' => $this->limit($r['url'] ?? null, 2048) ?? '',
                'status_code' => $this->boundedInt($r['status_code'] ?? null, 65535),
                'duration_ms' => $this->microsToMillis($r['duration'] ?? null),
                'request_size_bytes' => $this->boundedInt($r['request_size'] ?? null, 4294967295),
                'response_size_bytes' => $this->boundedInt($r['response_size'] ?? null, 4294967295),
                ...$this->sourceFields($r),
                'environment' => $this->limit($r['deploy'] ?? null, 50),
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
