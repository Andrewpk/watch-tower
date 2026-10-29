<?php

namespace App\Console\Commands;

use App\Models\MaintenanceRun;
use App\Models\Project;
use App\Watch\TelemetryPruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Enforces each project's `retention_days` by deleting older telemetry.
 *
 * For every project, rows whose event time is older than `now() - retention_days`
 * are deleted from every per-project telemetry table (see
 * {@see TelemetryPruner::TIME_COLUMNS}), in chunks, children before parents.
 *
 * Error groups are never deleted, only their occurrences are. A group's
 * `total_count` therefore stays a lifetime count and can be larger than the
 * number of occurrences still retained. Issue comments and statuses survive
 * with their group.
 *
 * A finished run over every project (not a dry run, not `--project`) is
 * recorded in `maintenance_runs`. The admin warning for a scheduler that is
 * not running reads that record.
 */
#[Signature('watch:prune
    {--project= : Only prune the project with this slug}
    {--dry-run : Report how many rows would be deleted without deleting anything}
    {--chunk=5000 : Maximum rows deleted per query}')]
#[Description('Delete telemetry older than each project\'s retention_days')]
class PruneTelemetry extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            $this->components->error('The --chunk option must be at least 1.');

            return self::FAILURE;
        }

        $slug = $this->option('project');
        $dryRun = (bool) $this->option('dry-run');

        $projects = Project::query()
            ->when($slug, fn ($query) => $query->where('slug', $slug))
            ->orderBy('name')
            ->get();

        if ($slug && $projects->isEmpty()) {
            $this->components->error("No project with slug [{$slug}].");

            return self::FAILURE;
        }

        $startedAt = now();
        $pruner = new TelemetryPruner($chunkSize);
        $total = 0;

        foreach ($projects as $project) {
            $cutoff = now()->subDays($project->retention_days);

            $perTable = $dryRun
                ? $pruner->countPrunable($project->id, $cutoff)
                : $pruner->prune($project->id, $cutoff);

            $this->components->info(sprintf(
                '%s (%s): retention %d days, cutoff %s',
                $project->name,
                $project->slug,
                $project->retention_days,
                $cutoff->toDateTimeString(),
            ));

            $this->table(
                ['Table', $dryRun ? 'Rows to delete' : 'Rows deleted'],
                collect($perTable)->map(fn (int $count, string $table) => [$table, $count])->values()->all(),
            );

            $total += array_sum($perTable);
        }

        if (! $dryRun && ! $slug) {
            MaintenanceRun::recordFinished(MaintenanceRun::TASK_PRUNE, $startedAt, $total);
        }

        $this->components->info($dryRun
            ? "Dry run: {$total} rows would be deleted."
            : "Deleted {$total} rows.");

        return self::SUCCESS;
    }
}
