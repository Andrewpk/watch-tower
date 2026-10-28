<?php

namespace App\Jobs;

use App\Models\Project;
use App\Watch\TelemetryPruner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
 * Each run is time-boxed to {@see self::RUN_BUDGET_SECONDS}. A project too big
 * to purge within that dispatches a fresh job (with fresh attempts) and exits,
 * and only the run that finds nothing left deletes the project row. This keeps
 * a run well under the queue's `retry_after`, so the queue never hands a
 * still-running job to a second worker.
 *
 * The job does nothing for a project that is not marked as deleting, so it can
 * never remove a live project. Under the `sync` queue driver a continuation
 * cannot run inside the job that dispatched it (it finds the overlap lock held),
 * so very large projects need a real queue worker.
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
     * @param  int|null  $maxChunks  most chunks to delete per run, besides the time budget; null for no limit
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

        $result = $pruner->purge($project->id, $this->maxChunks, microtime(true) + self::RUN_BUDGET_SECONDS);

        if (! $result['finished']) {
            self::dispatch($this->projectId, $this->maxChunks)->delay(self::CONTINUATION_DELAY_SECONDS);

            return;
        }

        $project->delete();
    }
}
