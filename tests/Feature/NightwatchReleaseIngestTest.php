<?php

use App\Models\CacheEvent;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\Trace;
use App\Watch\Stats\CacheStats;
use App\Watch\Stats\TimeRange;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->token = ingestToken($this->project->id);
    $this->startedAt = CarbonImmutable::now()->subMinutes(5)->getTimestamp() + 0.25;
});

it('stores the Nightwatch deploy id as the release of a request and leaves the environment empty', function () {
    postNightwatchRecords([[
        'v' => 1,
        't' => 'request',
        'timestamp' => $this->startedAt,
        'deploy' => 'v1.4.2',
        'server' => 'web-1',
        'trace_id' => 'trace-release-1',
        'method' => 'GET',
        'route_path' => '/orders',
        'status_code' => 200,
        'duration' => 125_000,
    ]], $this->token)->assertOk()->assertJson(['records_queued' => 1]);

    $trace = Trace::where('project_id', $this->project->id)->sole();

    expect($trace->release_version)->toBe('v1.4.2')
        ->and($trace->environment)->toBeNull()
        ->and($trace->hostname)->toBe('web-1');
});

it('accepts a batch with malformed values and still stores the valid records', function () {
    postNightwatchRecords([
        [
            'v' => 1,
            't' => 'request',
            'timestamp' => $this->startedAt,
            'deploy' => ['x'],
            'server' => ['x'],
            'user' => ['x'],
            'trace_id' => ['x'],
            'method' => ['x'],
            'route_path' => ['x'],
        ],
        ['v' => 1, 't' => 'log', 'timestamp' => $this->startedAt, 'trace_id' => ['x'], 'level' => ['x'], 'message' => ['x']],
        ['v' => 1, 't' => 'cache-event', 'timestamp' => $this->startedAt, '_group' => ['x'], 'key' => ['x'], 'store' => ['x'], 'type' => ['x']],
        ['v' => 1, 't' => 'job-attempt', 'timestamp' => $this->startedAt, 'attempt_id' => 'attempt-1', 'job_id' => 'job-1', 'status' => 'failed', 'exception_preview' => ['x'], 'queue' => ['x'], 'user' => ['x']],
        [
            'v' => 1,
            't' => 'request',
            'timestamp' => $this->startedAt,
            'deploy' => 'v1.4.2',
            'trace_id' => 'trace-valid-1',
            'method' => 'GET',
            'route_path' => '/orders',
            'status_code' => 200,
        ],
    ], $this->token)
        ->assertOk()
        ->assertJson(['records_received' => 5, 'records_queued' => 5]);

    $trace = Trace::where('project_id', $this->project->id)->where('correlation_id', 'trace-valid-1')->sole();

    expect($trace->release_version)->toBe('v1.4.2')
        ->and($trace->environment)->toBeNull()
        ->and(QueueJobRun::where('project_id', $this->project->id)->where('job_id', 'job-1')->exists())->toBeTrue();
});

it('accepts a batch with unusable timestamps and still stores the other records', function () {
    postNightwatchRecords([
        ['v' => 1, 't' => 'request', 'timestamp' => 1.73e15, 'trace_id' => 'trace-bad-1', 'method' => 'GET', 'route_path' => '/a'],
        ['v' => 1, 't' => 'request', 'timestamp' => '1e15', 'trace_id' => 'trace-bad-2', 'method' => 'GET', 'route_path' => '/b'],
        ['v' => 1, 't' => 'job-attempt', 'timestamp' => 1.73e15, 'attempt_id' => 'attempt-2', 'job_id' => 'job-2', 'status' => 'processed', 'duration' => 1_000],
        ['v' => 1, 't' => 'request', 'timestamp' => $this->startedAt, 'trace_id' => 'trace-ok-1', 'method' => 'GET', 'route_path' => '/c', 'deploy' => 'v1.4.2'],
    ], $this->token)
        ->assertOk()
        ->assertJson(['records_received' => 4, 'records_queued' => 4]);

    expect(Trace::where('project_id', $this->project->id)->pluck('correlation_id')->sort()->values()->all())
        ->toBe(['trace-bad-1', 'trace-bad-2', 'trace-ok-1'])
        ->and(QueueJobRun::where('project_id', $this->project->id)->where('job_id', 'job-2')->exists())->toBeTrue();
});

it('stores a failed cache write and delete as failed operations that the cache failures count', function () {
    $cacheEvent = fn (string $group, string $key, string $type) => [
        'v' => 1,
        't' => 'cache-event',
        'timestamp' => $this->startedAt,
        '_group' => $group,
        'trace_id' => 'trace-cache-1',
        'key' => $key,
        'type' => $type,
        'duration' => 1_000,
    ];

    postNightwatchRecords([
        $cacheEvent('g1', 'users:1', 'write-failure'),
        $cacheEvent('g2', 'users:2', 'delete-failure'),
        $cacheEvent('g3', 'users:3', 'write'),
    ], $this->token)->assertOk()->assertJson(['records_queued' => 3]);

    $events = CacheEvent::where('project_id', $this->project->id)->orderBy('key')->get();

    expect($events->pluck('operation')->all())->toBe(['write', 'delete', 'write'])
        ->and($events->pluck('succeeded')->all())->toBe([false, false, true]);

    $failures = (new CacheStats)->failures($this->project, TimeRange::fromLabel('1h'));

    expect($failures['totals'])->toBe(['write' => 1, 'delete' => 1, 'total' => 2]);
});
