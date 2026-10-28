<?php

namespace App\Watch;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Deletes per-project telemetry in bounded chunks using plain query-builder
 * statements, so no models are hydrated and no single statement (or lock)
 * grows with the size of the project.
 *
 * Each chunk is one `select id ... limit N` followed by one
 * `delete ... where id in (...)`, repeated until nothing is left.
 */
class TelemetryPruner
{
    /**
     * Per-project telemetry tables mapped to their event-time column.
     *
     * The order matters: children come before parents. `trace_queries` and
     * `event_occurrences` cascade from `traces`, and `error_occurrences` and
     * `queue_job_runs` null their `trace_id` when a trace goes, so `traces`
     * is last. `mail_sends`, `notification_sends`, `cache_events`,
     * `outgoing_requests` and `log_entries` store `trace_id` as a plain string
     * without a foreign key. `queue_job_runs` has no event-time column of its
     * own (only nullable lifecycle timestamps), so its ingest time
     * `created_at` is used. `metrics` rows are aged by `period_start`.
     *
     * @var array<string, string>
     */
    public const TIME_COLUMNS = [
        'trace_queries' => 'occurred_at',
        'event_occurrences' => 'occurred_at',
        'error_occurrences' => 'occurred_at',
        'queue_job_runs' => 'created_at',
        'command_runs' => 'occurred_at',
        'scheduled_task_runs' => 'occurred_at',
        'mail_sends' => 'occurred_at',
        'notification_sends' => 'occurred_at',
        'cache_events' => 'occurred_at',
        'outgoing_requests' => 'occurred_at',
        'log_entries' => 'occurred_at',
        'metrics' => 'period_start',
        'traces' => 'occurred_at',
    ];

    public function __construct(private readonly int $chunkSize = 5000) {}

    /**
     * Delete a project's telemetry older than the cutoff.
     *
     * `error_groups` are never touched here, only their occurrences.
     *
     * @return array<string, int> rows deleted per table
     */
    public function prune(string $projectId, CarbonInterface $before): array
    {
        $deleted = [];

        foreach (self::TIME_COLUMNS as $table => $timeColumn) {
            $deleted[$table] = $this->deleteInChunks($table, $projectId, $timeColumn, $before);
        }

        return $deleted;
    }

    /**
     * Count what {@see prune()} would delete, without deleting anything.
     *
     * @return array<string, int> rows that would be deleted per table
     */
    public function countPrunable(string $projectId, CarbonInterface $before): array
    {
        $counts = [];

        foreach (self::TIME_COLUMNS as $table => $timeColumn) {
            $counts[$table] = DB::table($table)
                ->where('project_id', $projectId)
                ->where($timeColumn, '<', $before)
                ->count();
        }

        return $counts;
    }

    /**
     * Delete every telemetry row of a project, error groups included.
     *
     * Used before removing the project row so that the final delete has
     * almost nothing left to cascade.
     *
     * The run can be time-boxed. It stops, unfinished, before starting a chunk
     * once `$maxChunks` non-empty chunks have been deleted or `$deadline`
     * (a `microtime(true)` timestamp) has passed. Every call that finds rows
     * deletes at least one chunk, so repeated calls always make progress.
     *
     * @param  int|null  $maxChunks  most chunks to delete in this call, or null for no limit
     * @param  float|null  $deadline  `microtime(true)` after which no new chunk starts, or null for no limit
     * @return array{deleted: array<string, int>, finished: bool} rows deleted per table, and whether nothing is left
     */
    public function purge(string $projectId, ?int $maxChunks = null, ?float $deadline = null): array
    {
        $deleted = [];
        $chunks = 0;

        foreach ([...array_keys(self::TIME_COLUMNS), 'error_groups'] as $table) {
            $deleted[$table] = 0;

            do {
                if (($maxChunks !== null && $chunks >= $maxChunks) || ($deadline !== null && microtime(true) >= $deadline)) {
                    return ['deleted' => $deleted, 'finished' => false];
                }

                [$selected, $removed] = $this->deleteChunk($table, $projectId);

                $deleted[$table] += $removed;
                $chunks += $selected > 0 ? 1 : 0;
            } while ($selected === $this->chunkSize);
        }

        return ['deleted' => $deleted, 'finished' => true];
    }

    private function deleteInChunks(string $table, string $projectId, string $timeColumn, CarbonInterface $before): int
    {
        $deleted = 0;

        do {
            [$selected, $removed] = $this->deleteChunk($table, $projectId, $timeColumn, $before);

            $deleted += $removed;
        } while ($selected === $this->chunkSize);

        return $deleted;
    }

    /**
     * Delete up to one chunk of a project's rows, optionally only those older than the cutoff.
     *
     * @return array{int, int} rows selected, rows deleted
     */
    private function deleteChunk(string $table, string $projectId, ?string $timeColumn = null, ?CarbonInterface $before = null): array
    {
        $ids = DB::table($table)
            ->where('project_id', $projectId)
            ->when($timeColumn !== null, fn ($query) => $query->where($timeColumn, '<', $before))
            ->limit($this->chunkSize)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return [0, 0];
        }

        return [$ids->count(), DB::table($table)->whereIn('id', $ids->all())->delete()];
    }
}
