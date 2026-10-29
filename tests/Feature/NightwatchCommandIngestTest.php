<?php

use App\Models\CommandRun;
use App\Models\Project;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Watch\Stats\CommandStats;
use App\Watch\Stats\ScheduledTaskStats;
use App\Watch\Stats\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->now = CarbonImmutable::now()->subMinutes(5);
    $this->range = TimeRange::fromLabel('1h');
});

function commandIngestRecord(array $overrides, CarbonImmutable $at): array
{
    return array_merge([
        'v' => 1,
        't' => 'command',
        'timestamp' => $at->getTimestamp() + 0.25,
        'deploy' => 'production',
        'server' => 'web-1.example.test',
        'trace_id' => (string) Str::uuid(),
        'name' => 'app:sync-users',
        'command' => 'app:sync-users --force 42',
        'exit_code' => 0,
        'duration' => 1_250_000,
    ], $overrides);
}

function scheduledTaskIngestRecord(array $overrides, CarbonImmutable $at): array
{
    return array_merge([
        'v' => 1,
        't' => 'scheduled-task',
        'timestamp' => $at->getTimestamp() + 0.25,
        'deploy' => 'production',
        'server' => 'web-1.example.test',
        'trace_id' => (string) Str::uuid(),
        'name' => 'php artisan app:prune',
        'cron' => '0 * * * *',
        'timezone' => 'UTC',
        'repeat_seconds' => 0,
        'status' => 'processed',
        'duration' => 2_000_000,
    ], $overrides);
}

it('stores command runs sent through the Nightwatch endpoint', function () {
    postNightwatchRecords([
        commandIngestRecord([], $this->now),
        commandIngestRecord(['exit_code' => 1, 'duration' => 500_000], $this->now),
    ], ingestToken($this->project->id))
        ->assertOk()
        ->assertJson(['records_received' => 2, 'records_queued' => 2]);

    $runs = CommandRun::where('project_id', $this->project->id)->orderBy('exit_code')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->command)->toBe('app:sync-users')
        ->and($runs[0]->status)->toBe('completed')
        ->and($runs[0]->exit_code)->toBe(0)
        ->and($runs[0]->duration_ms)->toBe(1250)
        ->and($runs[0]->arguments)->toBe(['42'])
        ->and($runs[0]->options)->toBe(['--force'])
        ->and($runs[0]->environment)->toBeNull()
        ->and($runs[1]->status)->toBe('failed')
        ->and($runs[1]->exit_code)->toBe(1)
        ->and($runs[1]->duration_ms)->toBe(500);

    $summary = (new CommandStats)->summary($this->project, $this->range, 'app:sync-users');

    expect($summary['totals'])->toBe(['total' => 2, 'successful' => 1, 'failed' => 1]);
});

it('stores scheduled task runs and exposes the task detail by hash', function () {
    postNightwatchRecords([
        scheduledTaskIngestRecord([], $this->now),
        scheduledTaskIngestRecord(['status' => 'failed'], $this->now),
        scheduledTaskIngestRecord(['status' => 'skipped', 'duration' => 0], $this->now),
    ], ingestToken($this->project->id))
        ->assertOk()
        ->assertJson(['records_received' => 3, 'records_queued' => 3]);

    $hash = sha1('php artisan app:prune,0 * * * *,UTC');
    $runs = ScheduledTaskRun::where('project_id', $this->project->id)->get();
    $processed = $runs->firstWhere('status', 'processed');

    expect($runs)->toHaveCount(3)
        ->and($runs->pluck('task_hash')->unique()->all())->toBe([$hash])
        ->and($runs->firstWhere('status', 'skipped')->duration_ms)->toBeNull()
        ->and($processed->task)->toBe('php artisan app:prune')
        ->and($processed->schedule)->toBe('0 * * * *')
        ->and($processed->duration_ms)->toBe(2000)
        ->and($processed->next_run_at)->not->toBeNull()
        ->and($processed->exit_code)->toBeNull();

    $detail = (new ScheduledTaskStats)->taskDetail($this->project, $this->range, $hash);

    expect($detail)->not->toBeNull()
        ->and($detail['task'])->toBe('php artisan app:prune')
        ->and($detail['totals'])->toBe(['total' => 3, 'processed' => 1, 'skipped' => 1, 'failed' => 1]);
});

it('does not touch another project when storing runs', function () {
    $other = Project::factory()->create();

    postNightwatchRecords([commandIngestRecord([], $this->now), scheduledTaskIngestRecord([], $this->now)], ingestToken($this->project->id))->assertOk();

    expect(CommandRun::where('project_id', $other->id)->count())->toBe(0)
        ->and(ScheduledTaskRun::where('project_id', $other->id)->count())->toBe(0);
});

it('renders the command detail page for an ingested command', function () {
    $this->withoutVite();
    postNightwatchRecords([commandIngestRecord([], $this->now)], ingestToken($this->project->id))->assertOk();

    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
        ->get(route('projects.commands.show', [$this->project, 'app:sync-users']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/commands/show')
            ->where('detail.command', 'app:sync-users')
            ->where('detail.totals.total', 1)
            ->where('detail.calls.0.exit_code', 0)
            ->where('detail.calls.0.duration_ms', 1250));
});

it('renders the scheduled task detail page for an ingested task', function () {
    $this->withoutVite();
    postNightwatchRecords([scheduledTaskIngestRecord([], $this->now)], ingestToken($this->project->id))->assertOk();

    $hash = sha1('php artisan app:prune,0 * * * *,UTC');

    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
        ->get(route('projects.scheduled-tasks.show', [$this->project, $hash]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/scheduled-tasks/show')
            ->where('detail.task', 'php artisan app:prune')
            ->where('detail.task_hash', $hash)
            ->where('detail.schedule', '0 * * * *')
            ->where('detail.totals.processed', 1)
            ->where('detail.runs.0.duration_ms', 2000));
});

it('replaces a malformed legacy task hash with the sha1 of the task', function () {
    Project::factory()->withApiKey('pk_test_task_hash')->create();
    $validHash = str_repeat('AbC123', 6).'abcd';

    $this->withHeader('Authorization', 'Bearer pk_test_task_hash')
        ->postJson('/api/ingest/sync', [
            'events' => [
                ['type' => 'scheduled-task', 'data' => ['task' => 'backup:run', 'task_hash' => 'not-a-valid-hash', 'status' => 'processed']],
                ['type' => 'scheduled-task', 'data' => ['task' => 'backup:valid', 'task_hash' => $validHash, 'status' => 'processed']],
                ['type' => 'scheduled-task', 'data' => ['task' => 'backup:none', 'status' => 'processed']],
            ],
        ])
        ->assertOk()
        ->assertJson(['events_stored' => 3]);

    expect(ScheduledTaskRun::where('task', 'backup:run')->value('task_hash'))->toBe(sha1('backup:run'))
        ->and(ScheduledTaskRun::where('task', 'backup:valid')->value('task_hash'))->toBe($validHash)
        ->and(ScheduledTaskRun::where('task', 'backup:none')->value('task_hash'))->toBe(sha1('backup:none'));
});
