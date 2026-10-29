<?php

use App\Models\MaintenanceRun;
use App\Models\Project;
use App\Watch\MaintenanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The migration that creates `maintenance_runs` and seeds the prune baseline.
 */
function maintenanceRunsMigration(): Migration
{
    return require glob(database_path('migrations/*_create_maintenance_runs_table.php'))[0];
}

beforeEach(function () {
    Schema::dropIfExists('maintenance_runs');
    $this->travelTo('2026-06-15 12:00:00');
    maintenanceRunsMigration()->up();
});

it('seeds a prune baseline with no recorded run', function () {
    $run = MaintenanceRun::query()->sole();

    expect($run->task)->toBe(MaintenanceRun::TASK_PRUNE)
        ->and($run->started_at->toDateTimeString())->toBe('2026-06-15 12:00:00')
        ->and($run->finished_at->toDateTimeString())->toBe('2026-06-15 12:00:00')
        ->and($run->rows_deleted)->toBeNull()
        ->and($run->hasRecordedRun())->toBeFalse();
});

it('does not warn an upgraded install about a prune that never ran until a day has passed', function () {
    Project::factory()->create(['created_at' => now()->subMonths(6)]);

    expect(app(MaintenanceStatus::class)->problems())->toBe([]);

    $this->travelTo(now()->addHours(26));

    expect(app(MaintenanceStatus::class)->problems())->toBe([
        ['key' => 'prune_overdue', 'last_run_at' => null, 'threshold_hours' => 25],
    ]);
});

it('drops the table on rollback', function () {
    maintenanceRunsMigration()->down();

    expect(Schema::hasTable('maintenance_runs'))->toBeFalse();
});
