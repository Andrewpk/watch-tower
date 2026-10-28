<?php

namespace App\Watch;

use App\Models\Project;

/**
 * Decides whether an ingested event is stored, based on the project's
 * `sampling_rate`.
 *
 * The decision is deterministic: the event's trace/correlation id is hashed
 * into [0, 1) and kept when it falls below the rate, so a request and all of
 * its children (which carry the same `request_id`) are kept or dropped
 * together, and a retried job reaches the same decision.
 *
 * Rules, in order:
 *  - exceptions are never sampled out, whatever the rate;
 *  - rate >= 1 keeps everything and rate <= 0 keeps nothing else;
 *  - an event with no usable id is kept. Without an id there is nothing stable
 *    to hash, and a random draw would break the request/children grouping and
 *    make the decision irreproducible. Keeping errs on the side of retained data.
 */
class Sampler
{
    /**
     * @param  array{type?: string, id?: string, data?: array<string, mixed>}  $event
     */
    public function shouldKeep(Project $project, array $event): bool
    {
        if (($event['type'] ?? null) === 'exception') {
            return true;
        }

        $rate = (float) $project->sampling_rate;

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        $id = $this->samplingId($event);

        if ($id === null) {
            return true;
        }

        return self::bucket($id) < $rate;
    }

    /**
     * Map an id to a stable position in [0, 1).
     */
    public static function bucket(string $id): float
    {
        return hexdec(substr(hash('xxh3', $id), 0, 8)) / 0x100000000;
    }

    /**
     * The first non-empty of `data.request_id`, `data.correlation_id` and `id`.
     *
     * @param  array{type?: string, id?: string, data?: array<string, mixed>}  $event
     */
    private function samplingId(array $event): ?string
    {
        $candidates = [
            $event['data']['request_id'] ?? null,
            $event['data']['correlation_id'] ?? null,
            $event['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }
}
