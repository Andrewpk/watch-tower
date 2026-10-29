<?php

use App\Models\Project;
use App\Models\Trace;
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
        ->assertJson(['records_received' => 4, 'records_queued' => 4]);

    $trace = Trace::where('project_id', $this->project->id)->where('correlation_id', 'trace-valid-1')->sole();

    expect($trace->release_version)->toBe('v1.4.2')
        ->and($trace->environment)->toBeNull();
});
