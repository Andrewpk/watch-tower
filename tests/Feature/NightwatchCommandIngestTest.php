<?php

use App\Models\CommandRun;
use App\Models\Project;
use App\Models\ScheduledTaskRun;
use App\Watch\Stats\CommandStats;
use App\Watch\Stats\ScheduledTaskStats;
use App\Watch\Stats\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

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
        ->and($runs[0]->environment)->toBe('production')
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
