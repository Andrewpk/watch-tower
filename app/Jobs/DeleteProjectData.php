<?php

namespace App\Jobs;

use App\Models\Project;
use App\Watch\TelemetryPruner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Finishes deleting a project that `ProjectAdminController::destroy` has
 * already hidden (`deleting_at` is set, so the default `Project` scope no
 * longer returns it).
 *
 * Telemetry is deleted in chunks by {@see TelemetryPruner::purge()}, so no single
 * statement locks a large project's tables, and only then is the project row
 * removed. Every step is idempotent, so a retry after a failure simply
 * continues where the previous attempt stopped.
 *
 * On a real queue each run is time-boxed to {@see self::RUN_BUDGET_SECONDS}. A
 * project too big to purge within that dispatches a fresh job (with fresh
 * attempts) and exits, and only the run that finds nothing left deletes the
 * project row. This keeps a run well under the queue's `retry_after`, so the
 * queue never hands a still-running job to a second worker.
 *
 * On the `sync` connection (a {@see SyncJob}, which is also what `dispatchSync()`
 * uses) there is no `retry_after`, and a continuation could not run anyway
 * because it would find the overlap lock held by the job that dispatched it.
 * So the job ignores both budgets and purges to completion in a single run,
 * which just makes the request that triggered it take longer. A job with no
 * queue job attached, such as one whose `handle()` is called directly, is
 * treated the same way.
 *
 * The job does nothing for a project that is not marked as deleting, so it can
 * never remove a live project.
 */
class DeleteProjectData implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds of purging per run, leaving headroom below {@see self::$timeout}
     * for the chunk in flight when the budget runs out.
     */
    public const RUN_BUDGET_SECONDS = 60;

    /**
     * Seconds to wait before a continuation job runs, so the finishing run has
     * released its overlap lock first.
     */
    private const CONTINUATION_DELAY_SECONDS = 5;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Must stay below the queue connection's `retry_after` (90 seconds by
     * default for the database, redis and beanstalkd queues). A job that runs
     * longer is handed to another worker while it is still running, and each
     * hand-off counts as an attempt.
     */
    public int $timeout = 80;

    /**
     * @param  int|null  $maxChunks  most chunks to delete per run, besides the time budget; null for no limit. Ignored on the sync connection.
     */
    public function __construct(
        public readonly string $projectId,
        public readonly ?int $maxChunks = null,
    ) {}

    /**
     * Keep two jobs for the same project from running at once.
     *
     * A duplicate waits and is released back to the queue instead of deleting
     * the same chunks concurrently. The lock outlives one run but expires on
     * its own if a worker dies.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->projectId))->releaseAfter(30)->expireAfter(120),
        ];
    }

    public function handle(TelemetryPruner $pruner): void
    {
        $project = Project::withoutGlobalScope('notDeleting')->find($this->projectId);

        if (! $project || $project->deleting_at === null) {
            return;
        }

        $result = $this->isTimeBoxed()
            ? $pruner->purge($project->id, $this->maxChunks, microtime(true) + self::RUN_BUDGET_SECONDS)
            : $pruner->purge($project->id);

        if (! $result['finished']) {
            self::dispatch($this->projectId, $this->maxChunks)->delay(self::CONTINUATION_DELAY_SECONDS);

            return;
        }

        $project->delete();
    }

    /**
     * Whether this run is on a real queue and must stay under its `retry_after`.
     *
     * False for the sync connection and when no queue job is attached.
     */
    private function isTimeBoxed(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }
}
