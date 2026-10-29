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
     * Timestamps are ISO 8601 strings.
     *
     * @return list<array{key: 'prune_overdue', last_run_at: string|null, threshold_hours: int}|array{key: 'queue_stalled', waiting_since: string, threshold_minutes: int}>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->pruneIsOverdue()) {
            $problems[] = [
                'key' => 'prune_overdue',
                'last_run_at' => $this->lastPrune()?->finished_at->toIso8601String(),
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
     * The last finished run of the retention prune, if there has been one.
     */
    public function lastPrune(): ?MaintenanceRun
    {
        return MaintenanceRun::query()->find(MaintenanceRun::TASK_PRUNE);
    }

    /**
     * Whether the prune should have finished by now but has not.
     *
     * Without a record, the prune only counts as overdue once the oldest
     * project is older than the threshold, so a fresh install gives no false
     * alarm. Without projects there is nothing to prune.
     */
    private function pruneIsOverdue(): bool
    {
        $threshold = now()->subHours($this->pruneOverdueHours());
        $lastPrune = $this->lastPrune();

        if ($lastPrune !== null) {
            return $lastPrune->finished_at->lt($threshold);
        }

        $oldestProject = Project::query()->min('created_at');

        return $oldestProject !== null && CarbonImmutable::parse($oldestProject)->lt($threshold);
    }

    /**
     * When the oldest job that no worker has claimed became available, if it
     * has waited longer than the threshold.
     *
     * Only the `database` queue driver can be inspected this way. The `id`
     * ordering is a primary key walk, so the check stays cheap on a long queue.
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
            ->where('available_at', '<', now()->subMinutes($this->queueStalledMinutes())->getTimestamp())
            ->orderBy('id')
            ->value('available_at');

        return $availableAt === null ? null : CarbonImmutable::createFromTimestamp((int) $availableAt);
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
