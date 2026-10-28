<?php

use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\IssueComment;
use App\Models\Project;
use App\Watch\TelemetryPruner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-06-15 12:00:00');
});

/**
 * Rows a project owns across every pruned table, keyed by table name.
 *
 * @return array<string, int>
 */
function pruneTelemetryCounts(Project $project): array
{
    return collect(array_keys(TelemetryPruner::TIME_COLUMNS))
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->where('project_id', $project->id)->count()])
        ->all();
}

it('deletes rows older than the retention window from every telemetry table and keeps newer ones', function () {
    $project = Project::factory()->create(['retention_days' => 30]);

    seedTelemetry($project, now()->subDays(31));
    seedTelemetry($project, now()->subDays(29));

    $this->artisan('watch:prune')->assertSuccessful();

    expect(pruneTelemetryCounts($project))->each->toBe(1)
        ->and(pruneTelemetryCounts($project))->toHaveCount(13);

    expect(DB::table('trace_queries')->where('project_id', $project->id)->value('occurred_at'))
        ->toStartWith('2026-05-17');
});

it('uses each project\'s own retention_days', function () {
    $short = Project::factory()->create(['retention_days' => 7]);
    $long = Project::factory()->create(['retention_days' => 90]);

    seedTelemetry($short, now()->subDays(10));
    seedTelemetry($long, now()->subDays(10));

    $this->artisan('watch:prune')->assertSuccessful();

    expect(pruneTelemetryCounts($short))->each->toBe(0)
        ->and(pruneTelemetryCounts($long))->each->toBe(1);
});

it('only prunes the project given by --project', function () {
    $first = Project::factory()->create(['retention_days' => 7]);
    $second = Project::factory()->create(['retention_days' => 7]);

    seedTelemetry($first, now()->subDays(10));
    seedTelemetry($second, now()->subDays(10));

    $this->artisan('watch:prune', ['--project' => $first->slug])->assertSuccessful();

    expect(pruneTelemetryCounts($first))->each->toBe(0)
        ->and(pruneTelemetryCounts($second))->each->toBe(1);
});

it('fails for an unknown --project slug', function () {
    $this->artisan('watch:prune', ['--project' => 'nope'])
        ->expectsOutputToContain('No project with slug [nope].')
        ->assertFailed();
});

it('deletes nothing and reports counts per table with --dry-run', function () {
    $project = Project::factory()->create(['retention_days' => 30]);

    seedTelemetry($project, now()->subDays(40));
    seedTelemetry($project, now()->subDays(1));

    $this->artisan('watch:prune', ['--dry-run' => true])
        ->expectsTable(
            ['Table', 'Rows to delete'],
            collect(array_keys(TelemetryPruner::TIME_COLUMNS))->map(fn (string $table) => [$table, 1])->all(),
        )
        ->expectsOutputToContain('Dry run: 13 rows would be deleted.')
        ->assertSuccessful();

    expect(pruneTelemetryCounts($project))->each->toBe(2);
});

it('reports rows deleted per table', function () {
    $project = Project::factory()->create(['retention_days' => 30]);

    seedTelemetry($project, now()->subDays(40));

    $this->artisan('watch:prune')
        ->expectsTable(
            ['Table', 'Rows deleted'],
            collect(array_keys(TelemetryPruner::TIME_COLUMNS))->map(fn (string $table) => [$table, 1])->all(),
        )
        ->expectsOutputToContain('Deleted 13 rows.')
        ->assertSuccessful();
});

it('deletes in chunks until nothing old is left', function () {
    $project = Project::factory()->create(['retention_days' => 30]);

    foreach (range(1, 7) as $_) {
        DB::table('log_entries')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'level' => 'info',
            'message' => 'old',
            'occurred_at' => now()->subDays(45),
        ]);
    }

    DB::table('log_entries')->insert([
        'id' => (string) Str::uuid(),
        'project_id' => $project->id,
        'level' => 'info',
        'message' => 'recent',
        'occurred_at' => now()->subDay(),
    ]);

    DB::enableQueryLog();

    $this->artisan('watch:prune', ['--chunk' => 2])->assertSuccessful();

    $deletes = collect(DB::getQueryLog())->filter(fn (array $query) => str_starts_with($query['query'], 'delete from "log_entries"'));

    expect($deletes)->toHaveCount(4)
        ->and(DB::table('log_entries')->where('project_id', $project->id)->pluck('message')->all())->toBe(['recent']);
});

it('rejects a non-positive --chunk', function () {
    $this->artisan('watch:prune', ['--chunk' => 0])->assertFailed();
});

it('keeps error groups and their lifetime total_count when pruning occurrences', function () {
    $project = Project::factory()->create(['retention_days' => 30]);
    $group = ErrorGroup::factory()->create([
        'project_id' => $project->id,
        'total_count' => 12,
        'status' => 'resolved',
    ]);
    IssueComment::create(['error_group_id' => $group->id, 'type' => 'comment', 'body' => 'noted']);

    ErrorOccurrence::factory()->create([
        'project_id' => $project->id,
        'error_group_id' => $group->id,
        'occurred_at' => now()->subDays(60),
    ]);
    $recent = ErrorOccurrence::factory()->create([
        'project_id' => $project->id,
        'error_group_id' => $group->id,
        'occurred_at' => now()->subDay(),
    ]);

    $this->artisan('watch:prune')->assertSuccessful();

    expect(ErrorOccurrence::where('project_id', $project->id)->pluck('id')->all())->toBe([$recent->id]);

    $group->refresh();
    expect($group->total_count)->toBe(12)
        ->and($group->status)->toBe('resolved')
        ->and($group->comments()->count())->toBe(1);
});

it('removes old traces together with their queries and events', function () {
    $project = Project::factory()->create(['retention_days' => 30]);

    seedTelemetry($project, now()->subDays(31));

    $this->artisan('watch:prune')->assertSuccessful();

    expect(DB::table('traces')->count())->toBe(0)
        ->and(DB::table('trace_queries')->count())->toBe(0)
        ->and(DB::table('event_occurrences')->count())->toBe(0);
});
