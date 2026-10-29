<?php

use App\Models\CommandRun;
use App\Models\Project;
use App\Models\Trace;
use App\Models\User;
use App\Watch\Stats\RequestStats;
use App\Watch\Stats\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->project = Project::factory()->withApiKey('pk_test_stages')->create();
    $this->now = CarbonImmutable::now()->subMinutes(5);
    $this->range = TimeRange::fromLabel('1h');
    $this->traceId = (string) Str::uuid();
});

function stageIngestRequest(string $traceId, CarbonImmutable $at, array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'request',
        'timestamp' => $at->getTimestamp() + 0.25,
        'deploy' => 'v1.4.2',
        'server' => 'web-1.example.test',
        'trace_id' => $traceId,
        'method' => 'GET',
        'url' => 'https://example.com/orders/7',
        'route_path' => '/orders/{order}',
        'route_name' => 'orders.show',
        'route_methods' => ['GET', 'HEAD'],
        'route_domain' => '',
        'route_action' => 'App\\Http\\Controllers\\OrderController@show',
        'status_code' => 200,
        'request_size' => 512,
        'response_size' => 2048,
        'duration' => 10_000,
        'bootstrap' => 1_234,
        'before_middleware' => 250,
        'action' => 5_000,
        'render' => 2_000,
        'after_middleware' => 500,
        'sending' => 16,
        'terminating' => 1_000,
    ], $overrides);
}

function stageSyncRequest(Project $project, string $traceId, array $data): void
{
    test()->withHeader('Authorization', 'Bearer pk_test_stages')
        ->postJson('/api/ingest/sync', [
            'events' => [['type' => 'request', 'data' => ['request_id' => $traceId, 'method' => 'GET', 'uri' => '/orders/{order}', ...$data]]],
        ])
        ->assertOk();
}

it('stores the stages, sizes and route details of a request record', function () {
    postNightwatchRecords([stageIngestRequest($this->traceId, $this->now)], ingestToken($this->project->id))
        ->assertOk()
        ->assertJson(['records_received' => 1, 'records_queued' => 1]);

    $trace = Trace::sole()->fresh();

    expect($trace->bootstrap_ms)->toBe(1.234)
        ->and($trace->before_middleware_ms)->toBe(0.25)
        ->and($trace->action_ms)->toBe(5.0)
        ->and($trace->render_ms)->toBe(2.0)
        ->and($trace->after_middleware_ms)->toBe(0.5)
        ->and($trace->sending_ms)->toBe(0.016)
        ->and($trace->terminating_ms)->toBe(1.0)
        ->and($trace->request_size_bytes)->toBe(512)
        ->and($trace->response_size_bytes)->toBe(2048)
        ->and($trace->route_name)->toBe('orders.show')
        ->and($trace->route_methods)->toBe('GET|HEAD')
        ->and($trace->route_domain)->toBeNull()
        ->and($trace->route_action)->toBe('App\\Http\\Controllers\\OrderController@show');
});

it('fills the stub trace of an earlier query with the request details', function () {
    postNightwatchRecords([
        ['v' => 1, 't' => 'query', 'timestamp' => $this->now->getTimestamp() + 0.1, '_group' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'trace_id' => $this->traceId, 'sql' => 'select 1', 'duration' => 100, 'connection' => 'mysql'],
    ], ingestToken($this->project->id))->assertOk();

    expect(Trace::sole()->is_stub)->toBeTrue();

    postNightwatchRecords([stageIngestRequest($this->traceId, $this->now)], ingestToken($this->project->id))->assertOk();

    $trace = Trace::sole();

    expect($trace->is_stub)->toBeFalse()
        ->and($trace->action_ms)->toBe(5.0)
        ->and($trace->route_name)->toBe('orders.show')
        ->and($trace->response_size_bytes)->toBe(2048);
});

it('keeps the stored details when a legacy request record for the trace carries none', function () {
    postNightwatchRecords([stageIngestRequest($this->traceId, $this->now)], ingestToken($this->project->id))->assertOk();

    stageSyncRequest($this->project, $this->traceId, ['status_code' => 200]);

    $trace = Trace::sole();

    expect($trace->action_ms)->toBe(5.0)
        ->and($trace->request_size_bytes)->toBe(512)
        ->and($trace->route_name)->toBe('orders.show')
        ->and($trace->route_methods)->toBe('GET|HEAD');
});

it('stores null for array, negative or huge values on the legacy endpoint', function () {
    stageSyncRequest($this->project, $this->traceId, [
        'route_name' => ['x'],
        'route_methods' => [5, ['x']],
        'route_domain' => ['x'],
        'route_action' => ['x'],
        'request_size_bytes' => -1,
        'response_size_bytes' => 99_999_999_999,
        'bootstrap_ms' => ['x'],
        'before_middleware_ms' => 'abc',
        'action_ms' => -4,
        'render_ms' => '1e400',
        'sending_ms' => null,
    ]);

    $trace = Trace::sole();

    expect($trace->route_name)->toBeNull()
        ->and($trace->route_methods)->toBeNull()
        ->and($trace->route_domain)->toBeNull()
        ->and($trace->route_action)->toBeNull()
        ->and($trace->request_size_bytes)->toBeNull()
        ->and($trace->response_size_bytes)->toBeNull()
        ->and($trace->bootstrap_ms)->toBeNull()
        ->and($trace->before_middleware_ms)->toBeNull()
        ->and($trace->action_ms)->toBe(0.0)
        ->and($trace->render_ms)->toBeNull()
        ->and($trace->sending_ms)->toBeNull();
});

it('stores the three stages of a command record', function () {
    postNightwatchRecords([[
        'v' => 1,
        't' => 'command',
        'timestamp' => $this->now->getTimestamp() + 0.25,
        'trace_id' => $this->traceId,
        'name' => 'app:sync-users',
        'command' => 'app:sync-users',
        'exit_code' => 0,
        'duration' => 9_000,
        'bootstrap' => 1_500,
        'action' => 7_000,
        'terminating' => 500,
    ]], ingestToken($this->project->id))->assertOk();

    $run = CommandRun::sole()->fresh();

    expect($run->bootstrap_ms)->toBe(1.5)
        ->and($run->action_ms)->toBe(7.0)
        ->and($run->terminating_ms)->toBe(0.5);
});

it('returns the route name and action with each route', function () {
    postNightwatchRecords([stageIngestRequest($this->traceId, $this->now)], ingestToken($this->project->id))->assertOk();

    $routes = (new RequestStats)->routes($this->project, $this->range);

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['route_name'])->toBe('orders.show')
        ->and($routes[0]['route_action'])->toBe('App\\Http\\Controllers\\OrderController@show');
});

it('finds a route by its name', function () {
    postNightwatchRecords([stageIngestRequest($this->traceId, $this->now)], ingestToken($this->project->id))->assertOk();

    $stats = new RequestStats;

    $matched = $stats->routes($this->project, $this->range, null, 'orders.show');

    expect($matched)->toHaveCount(1)
        ->and($matched[0]['p95_ms'])->not->toBeNull()
        ->and($stats->routes($this->project, $this->range, null, 'invoices.show'))->toBeEmpty();
});

it('averages the stages and sizes in the route detail', function () {
    postNightwatchRecords([
        stageIngestRequest($this->traceId, $this->now),
        stageIngestRequest((string) Str::uuid(), $this->now, ['action' => 3_000, 'request_size' => 1_024, 'response_size' => 4_096]),
        stageIngestRequest((string) Str::uuid(), $this->now, ['bootstrap' => null, 'request_size' => null]),
    ], ingestToken($this->project->id))->assertOk();

    $detail = (new RequestStats)->routeDetail($this->project, $this->range, 'GET', '/orders/{order}');

    expect($detail['route'])->toBe([
        'name' => 'orders.show',
        'action' => 'App\\Http\\Controllers\\OrderController@show',
        'domain' => null,
    ])
        ->and($detail['stages']['action'])->toEqualWithDelta((5.0 + 3.0) / 2, 0.0001)
        ->and($detail['stages']['bootstrap'])->toEqualWithDelta(1.234, 0.0001)
        ->and($detail['stages']['sending'])->toBe(0.016)
        ->and($detail['sizes']['avg_request_bytes'])->toEqualWithDelta(768.0, 0.0001)
        ->and($detail['sizes']['avg_response_bytes'])->toEqualWithDelta((2048 + 4096 + 2048) / 3, 0.0001)
        ->and($detail['recent'])->toHaveCount(3)
        ->and(collect($detail['recent'])->pluck('stages.terminating')->all())->toBe([1.0, 1.0, 1.0]);
});

it('averages the stages only over requests that carry stage data', function () {
    postNightwatchRecords([stageIngestRequest($this->traceId, $this->now)], ingestToken($this->project->id))->assertOk();
    stageSyncRequest($this->project, (string) Str::uuid(), ['occurred_at' => $this->now->toIso8601String()]);

    $detail = (new RequestStats)->routeDetail($this->project, $this->range, 'GET', '/orders/{order}');

    expect($detail['recent'])->toHaveCount(2)
        ->and($detail['stages']['bootstrap'])->toEqualWithDelta(1.234, 0.0001)
        ->and($detail['stages']['action'])->toEqualWithDelta(5.0, 0.0001)
        ->and($detail['stages']['terminating'])->toEqualWithDelta(1.0, 0.0001);
});

it('stores the route methods of a legacy request as an upper-case joined string', function () {
    stageSyncRequest($this->project, $this->traceId, ['route_methods' => ['get', 'head', '', 5, ['x']]]);

    expect(Trace::sole()->route_methods)->toBe('GET|HEAD');
});

it('returns null stages for a route without stage data', function () {
    stageSyncRequest($this->project, $this->traceId, ['occurred_at' => $this->now->toIso8601String()]);

    $detail = (new RequestStats)->routeDetail($this->project, $this->range, 'GET', '/orders/{order}');

    expect($detail['stages'])->each->toBeNull()
        ->and($detail['sizes'])->toBe(['avg_request_bytes' => null, 'avg_response_bytes' => null])
        ->and($detail['route']['name'])->toBeNull()
        ->and($detail['recent'][0]['stages'])->each->toBeNull();
});

it('renders the stage breakdown on the requests page', function () {
    $this->withoutVite();
    postNightwatchRecords([stageIngestRequest($this->traceId, $this->now)], ingestToken($this->project->id))->assertOk();

    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
        ->get(route('projects.requests.index', [
            $this->project,
            'route_method' => 'GET',
            'route_uri' => '/orders/{order}',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/requests/index')
            ->where('routes.0.route_name', 'orders.show')
            ->where('routeDetail.stages.action', 5)
            ->where('routeDetail.route.name', 'orders.show')
            ->where('routeDetail.sizes.avg_response_bytes', 2048)
            ->where('routeDetail.recent.0.stages.bootstrap', 1.234));
});
