<?php

namespace App\Jobs;

use App\Models\Project;
use App\Watch\TelemetryPruner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finishes deleting a project that `ProjectAdminController::destroy` has
 * already hidden (`deleting_at` is set, so the default `Project` scope no
 * longer returns it).
 *
 * Telemetry is deleted in chunks by {@see TelemetryPruner::purge()}, so no single
 * statement locks a large project's tables, and only then is the project row
 * removed. Every step is idempotent, so a retry after a failure or timeout
 * simply continues where the previous attempt stopped.
 *
 * The job does nothing for a project that is not marked as deleting, so it can
 * never remove a live project.
 */
class DeleteProjectData implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 3600;

    public function __construct(public readonly string $projectId) {}

    public function handle(TelemetryPruner $pruner): void
    {
        $project = Project::withoutGlobalScope('notDeleting')->find($this->projectId);

        if (! $project || $project->deleting_at === null) {
            return;
        }

        $pruner->purge($project->id);

        $project->delete();
    }
}
