<?php

namespace App\Watch;

use App\Models\MaintenanceRun;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Detects background work that has stopped, for the warning shown to super
 * admins.
 *
 * Two things fail silently when nothing runs next to the web server: the
 * scheduler (retention is never enforced and the database grows without
 * bound) and the queue worker (ingested events are never stored).
 */
class MaintenanceStatus
{
    /**
     * Problems that need attention; empty when all is well.
     *
     * Timestamps are ISO 8601 strings. `last_run_at` is null when no real
     * prune has been recorded yet.
     *
     * @return list<array{key: 'prune_overdue', last_run_at: string|null, threshold_hours: int}|array{key: 'queue_stalled', waiting_since: string, threshold_minutes: int}>
     */
    public function problems(): array
    {
        $problems = [];
        $lastPrune = $this->lastPrune();

        if ($this->pruneIsOverdue($lastPrune)) {
            $problems[] = [
                'key' => 'prune_overdue',
                'last_run_at' => $lastPrune?->hasRecordedRun() ? $lastPrune->finished_at->toIso8601String() : null,
                'threshold_hours' => $this->pruneOverdueHours(),
            ];
        }

        $waitingSince = $this->queueWaitingSince();

        if ($waitingSince !== null) {
            $problems[] = [
                'key' => 'queue_stalled',
                'waiting_since' => $waitingSince->toIso8601String(),
                'threshold_minutes' => $this->queueStalledMinutes(),
            ];
        }

        return $problems;
    }

    /**
     * The last recorded run of the retention prune. After a fresh migration
     * this is the baseline row, see {@see MaintenanceRun::hasRecordedRun()}.
     */
    public function lastPrune(): ?MaintenanceRun
    {
        return MaintenanceRun::query()->find(MaintenanceRun::TASK_PRUNE);
    }

    /**
     * Whether the last prune started longer ago than the threshold.
     *
     * The start time is used because the prune is daily: a run that takes an
     * hour must not push the next warning an hour later. Without projects there
     * is nothing to prune. Without any record (the row was deleted), the prune
     * only counts as overdue once the oldest project is older than the
     * threshold.
     */
    private function pruneIsOverdue(?MaintenanceRun $lastPrune): bool
    {
        $threshold = now()->subHours($this->pruneOverdueHours());
        $oldestProject = Project::query()->min('created_at');

        if ($oldestProject === null) {
            return false;
        }

        if ($lastPrune !== null) {
            return $lastPrune->started_at->lt($threshold);
        }

        return CarbonImmutable::parse($oldestProject)->lt($threshold);
    }

    /**
     * When the first job that no worker has claimed became available, if it
     * has waited longer than the threshold.
     *
     * Only the `database` queue driver can be inspected this way. Ordering by
     * the primary key stops at the first unclaimed job, so the check stays
     * cheap on a long queue. Jobs mostly become available in id order, so the
     * first one is the oldest in practice.
     */
    private function queueWaitingSince(): ?CarbonImmutable
    {
        $connection = (string) config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return null;
        }

        $availableAt = DB::connection(config("queue.connections.{$connection}.connection"))
            ->table((string) config("queue.connections.{$connection}.table", 'jobs'))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->orderBy('id')
            ->value('available_at');

        if ($availableAt === null) {
            return null;
        }

        $waitingSince = CarbonImmutable::createFromTimestamp((int) $availableAt);

        return $waitingSince->lt(now()->subMinutes($this->queueStalledMinutes())) ? $waitingSince : null;
    }

    private function pruneOverdueHours(): int
    {
        return max(1, (int) config('watch.maintenance.prune_overdue_hours'));
    }

    private function queueStalledMinutes(): int
    {
        return max(1, (int) config('watch.maintenance.queue_stalled_minutes'));
    }
}
