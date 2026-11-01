<?php

use App\Models\Project;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Watch\Databases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The migration that sums existing query durations into `traces.db_time_ms`.
 */
function backfillDbTimeMigration(): Migration
{
    return require glob(database_path('migrations/*_backfill_db_time_ms_on_traces_table.php'))[0];
}

/**
 * Run a migration the way the migrator does for a telemetry migration: with the telemetry connection as default.
 */
function runOnTelemetry(Migration $migration, string $direction): void
{
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection(Databases::TELEMETRY);

    try {
        $migration->{$direction}();
    } finally {
        DB::setDefaultConnection($default);
    }
}

function traceWithQueries(Project $project, float $dbTimeMs, array $durations): Trace
{
    $trace = Trace::factory()->create(['project_id' => $project->id, 'db_time_ms' => $dbTimeMs]);

    foreach ($durations as $duration) {
        TraceQuery::factory()->create(['project_id' => $project->id, 'trace_id' => $trace->id, 'sql' => 'select 1', 'duration_ms' => $duration, 'occurred_at' => now()]);
    }

    return $trace;
}

it('sums query durations into traces that have none recorded', function () {
    $project = Project::factory()->create();
    $trace = traceWithQueries($project, 0, [2, 3]);

    runOnTelemetry(backfillDbTimeMigration(), 'up');

    expect($trace->fresh()->db_time_ms)->toBe(5.0);
});

it('keeps a db_time_ms that is already set', function () {
    $trace = traceWithQueries(Project::factory()->create(), 40, [2, 3]);

    runOnTelemetry(backfillDbTimeMigration(), 'up');

    expect($trace->fresh()->db_time_ms)->toBe(40.0);
});

it('leaves traces without queries at zero and does not mix up traces', function () {
    $project = Project::factory()->create();
    $without = traceWithQueries($project, 0, []);
    $first = traceWithQueries($project, 0, [1.5]);
    $second = traceWithQueries($project, 0, [4]);

    runOnTelemetry(backfillDbTimeMigration(), 'up');

    expect($without->fresh()->db_time_ms)->toBe(0.0)
        ->and($first->fresh()->db_time_ms)->toBe(1.5)
        ->and($second->fresh()->db_time_ms)->toBe(4.0);
});

it('has nothing to undo when rolled back', function () {
    $trace = traceWithQueries(Project::factory()->create(), 0, [2, 3]);
    $migration = backfillDbTimeMigration();

    runOnTelemetry($migration, 'up');
    runOnTelemetry($migration, 'down');

    expect(dbTable('traces')->where('id', $trace->id)->value('db_time_ms'))->toEqual(5);
});
