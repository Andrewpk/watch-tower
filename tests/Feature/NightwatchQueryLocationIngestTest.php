<?php

use App\Models\CommandRun;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\ScheduledTaskRun;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Models\User;
use App\Watch\Stats\QueryStats;
use App\Watch\Stats\RequestStats;
use App\Watch\Stats\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->token = ingestToken($this->project->id);
    $this->now = CarbonImmutable::now()->subMinutes(5);
    $this->range = TimeRange::fromLabel('1h');
    $this->send = fn (array ...$records) => postNightwatchRecords($records, $this->token)->assertOk();
});

function locationIngestQuery(CarbonImmutable $at, string $traceId, array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'query',
        'timestamp' => $at->getTimestamp() + 0.25,
        '_group' => md5('orders'),
        'trace_id' => $traceId,
        'sql' => 'select * from `orders` where `user_id` = ?',
        'duration' => 2_000,
        'connection' => 'mysql',
        'file' => 'app/Http/Controllers/OrderController.php',
        'line' => 42,
        'connection_type' => 'read',
    ], $overrides);
}

function locationIngestRequest(CarbonImmutable $at, string $traceId, array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'request',
        'timestamp' => $at->getTimestamp() + 0.25,
        'deploy' => 'v1.4.2',
        'server' => 'web-1',
        'trace_id' => $traceId,
        'method' => 'GET',
        'route_path' => '/orders/{order}',
        'url' => 'https://example.com/orders/7',
        'status_code' => 200,
        'duration' => 125_000,
        'queries' => 3,
    ], $overrides);
}

function locationIngestJobAttempt(CarbonImmutable $at, array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'job-attempt',
        'timestamp' => $at->getTimestamp() + 0.25,
        'deploy' => 'v1.4.2',
        'server' => 'worker-1',
        'trace_id' => (string) Str::uuid(),
        'job_id' => '22222222-2222-4222-8222-222222222222',
        'attempt_id' => '33333333-3333-4333-8333-333333333331',
        'attempt' => 1,
        'name' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'status' => 'processed',
        'duration' => 1_250_000,
        'exception_preview' => '',
    ], $overrides);
}

function locationIngestQueuedJob(CarbonImmutable $at, array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'queued-job',
        'timestamp' => $at->getTimestamp() + 0.25,
        'deploy' => 'v1.4.2',
        'server' => 'web-1',
        'trace_id' => (string) Str::uuid(),
        'job_id' => '22222222-2222-4222-8222-222222222222',
        'name' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'duration' => 480,
    ], $overrides);
}

it('stores the location and connection type of a query', function () {
    $traceId = (string) Str::uuid();

    ($this->send)(locationIngestQuery($this->now, $traceId));

    $query = TraceQuery::sole();

    expect($query->file)->toBe('app/Http/Controllers/OrderController.php')
        ->and($query->line)->toBe(42)
        ->and($query->connection_type)->toBe('read')
        ->and($query->connection_name)->toBe('mysql');
});

it('stores a query without a location with null columns', function () {
    ($this->send)(locationIngestQuery($this->now, (string) Str::uuid(), ['file' => '', 'line' => 0, 'connection_type' => '']));

    $query = TraceQuery::sole();

    expect($query->file)->toBeNull()
        ->and($query->line)->toBeNull()
        ->and($query->connection_type)->toBeNull();
});

it('stores the lazy load and hydrated model counts of a request', function () {
    $traceId = (string) Str::uuid();

    ($this->send)(locationIngestRequest($this->now, $traceId, ['lazy_loads' => 5, 'hydrated_models' => 321]));

    $trace = Trace::where('correlation_id', $traceId)->sole();

    expect($trace->lazy_loads)->toBe(5)
        ->and($trace->hydrated_models)->toBe(321);
});

it('stores a request that does not report counts with null counts', function () {
    $traceId = (string) Str::uuid();

    ($this->send)(locationIngestRequest($this->now, $traceId));

    $trace = Trace::where('correlation_id', $traceId)->sole();

    expect($trace->lazy_loads)->toBeNull()
        ->and($trace->hydrated_models)->toBeNull();
});

it('keeps the counts of a request when the record is sent again without them', function () {
    $traceId = (string) Str::uuid();

    ($this->send)(locationIngestRequest($this->now, $traceId, ['lazy_loads' => 5, 'hydrated_models' => 321]));
    ($this->send)(locationIngestRequest($this->now, $traceId));

    $trace = Trace::where('correlation_id', $traceId)->sole();

    expect($trace->lazy_loads)->toBe(5)
        ->and($trace->hydrated_models)->toBe(321);
});

it('stores the counts of command, scheduled task and job runs', function () {
    $counts = ['lazy_loads' => 3, 'hydrated_models' => 90];

    ($this->send)(
        [
            'v' => 1,
            't' => 'command',
            'timestamp' => $this->now->getTimestamp() + 0.25,
            'trace_id' => (string) Str::uuid(),
            'name' => 'app:sync-users',
            'command' => 'app:sync-users --force',
            'exit_code' => 0,
            'duration' => 1_000_000,
        ] + $counts,
        [
            'v' => 1,
            't' => 'scheduled-task',
            'timestamp' => $this->now->getTimestamp() + 0.25,
            'trace_id' => (string) Str::uuid(),
            'name' => 'php artisan app:prune',
            'cron' => '0 * * * *',
            'timezone' => 'UTC',
            'repeat_seconds' => 0,
            'status' => 'processed',
            'duration' => 2_000_000,
        ] + $counts,
        locationIngestJobAttempt($this->now, $counts),
    );

    $command = CommandRun::sole();
    $task = ScheduledTaskRun::sole();
    $job = QueueJobRun::sole();

    expect($command->lazy_loads)->toBe(3)
        ->and($command->hydrated_models)->toBe(90)
        ->and($task->lazy_loads)->toBe(3)
        ->and($task->hydrated_models)->toBe(90)
        ->and($job->lazy_loads)->toBe(3)
        ->and($job->hydrated_models)->toBe(90);
});

it('stores null counts for runs from a sensor that does not report them', function () {
    ($this->send)(locationIngestJobAttempt($this->now));

    $job = QueueJobRun::sole();

    expect($job->lazy_loads)->toBeNull()
        ->and($job->hydrated_models)->toBeNull();
});

it('lets the newest job attempt set the counts', function () {
    ($this->send)(
        locationIngestJobAttempt($this->now, ['status' => 'released', 'lazy_loads' => 9, 'hydrated_models' => 900]),
        locationIngestJobAttempt($this->now->addSeconds(30), [
            'attempt_id' => '33333333-3333-4333-8333-333333333332',
            'attempt' => 2,
            'lazy_loads' => 1,
            'hydrated_models' => 10,
        ]),
    );

    $job = QueueJobRun::sole();

    expect($job->attempts)->toBe(2)
        ->and($job->lazy_loads)->toBe(1)
        ->and($job->hydrated_models)->toBe(10);
});

it('ignores the counts of an older attempt that arrives late', function () {
    ($this->send)(
        locationIngestJobAttempt($this->now->addSeconds(30), [
            'attempt_id' => '33333333-3333-4333-8333-333333333332',
            'attempt' => 2,
            'lazy_loads' => 1,
            'hydrated_models' => 10,
        ]),
        locationIngestJobAttempt($this->now, ['status' => 'released', 'lazy_loads' => 9, 'hydrated_models' => 900]),
    );

    $job = QueueJobRun::sole();

    expect($job->attempts)->toBe(2)
        ->and($job->lazy_loads)->toBe(1)
        ->and($job->hydrated_models)->toBe(10);
});

it('keeps the counts when the same attempt is delivered again without them', function () {
    ($this->send)(locationIngestJobAttempt($this->now, ['lazy_loads' => 4, 'hydrated_models' => 40]));
    ($this->send)(locationIngestJobAttempt($this->now));

    $job = QueueJobRun::sole();

    expect($job->lazy_loads)->toBe(4)
        ->and($job->hydrated_models)->toBe(40);
});

it('keeps the counts when a queued record arrives after the attempt', function () {
    ($this->send)(locationIngestJobAttempt($this->now, ['lazy_loads' => 4, 'hydrated_models' => 40]));
    ($this->send)(locationIngestQueuedJob($this->now->subSeconds(5), ['lazy_loads' => 99, 'hydrated_models' => 99]));

    $job = QueueJobRun::sole();

    expect($job->lazy_loads)->toBe(4)
        ->and($job->hydrated_models)->toBe(40)
        ->and($job->dispatched_at)->not->toBeNull();
});

it('drops invalid location and counts from the legacy sync endpoint instead of failing', function () {
    $project = Project::factory()->withApiKey('pk_test_location')->create();
    $correlationId = (string) Str::uuid();

    $this->withHeader('Authorization', 'Bearer pk_test_location')
        ->postJson('/api/ingest/sync', [
            'events' => [
                ['type' => 'query', 'data' => [
                    'request_id' => $correlationId,
                    'sql' => 'select 1',
                    'file' => ['app/Models/User.php'],
                    'line' => -7,
                    'connection_type' => ['read'],
                ]],
                ['type' => 'query', 'data' => [
                    'request_id' => $correlationId,
                    'sql' => 'select 2',
                    'file' => 'app/Models/User.php',
                    'line' => [3],
                ]],
                ['type' => 'request', 'data' => [
                    'request_id' => $correlationId,
                    'lazy_loads' => [1],
                    'hydrated_models' => -4,
                ]],
                ['type' => 'command', 'data' => ['command' => 'app:sync', 'lazy_loads' => 'abc', 'hydrated_models' => 1e30]],
                ['type' => 'scheduled-task', 'data' => ['task' => 'app:prune', 'lazy_loads' => -1, 'hydrated_models' => ['x']]],
                ['type' => 'job-attempt', 'data' => ['class' => 'App\\Jobs\\SendInvoice', 'lazy_loads' => [1], 'hydrated_models' => -2]],
            ],
        ])
        ->assertOk()
        ->assertJson(['success' => true, 'events_stored' => 6]);

    $queries = TraceQuery::where('project_id', $project->id)->orderBy('sql')->get();
    $trace = Trace::where('correlation_id', $correlationId)->sole();

    expect($queries[0]->file)->toBeNull()
        ->and($queries[0]->line)->toBeNull()
        ->and($queries[0]->connection_type)->toBeNull()
        ->and($queries[1]->file)->toBe('app/Models/User.php')
        ->and($queries[1]->line)->toBeNull()
        ->and($trace->lazy_loads)->toBeNull()
        ->and($trace->hydrated_models)->toBeNull()
        ->and(CommandRun::sole()->lazy_loads)->toBeNull()
        ->and(CommandRun::sole()->hydrated_models)->toBeNull()
        ->and(ScheduledTaskRun::sole()->lazy_loads)->toBeNull()
        ->and(ScheduledTaskRun::sole()->hydrated_models)->toBeNull()
        ->and(QueueJobRun::sole()->lazy_loads)->toBeNull()
        ->and(QueueJobRun::sole()->hydrated_models)->toBeNull();
});

it('returns the most called location of each query from the stats', function () {
    $traceId = (string) Str::uuid();

    ($this->send)(
        locationIngestQuery($this->now, $traceId),
        locationIngestQuery($this->now, $traceId),
        locationIngestQuery($this->now, $traceId, ['file' => 'app/Services/CheckoutService.php', 'line' => 12]),
        locationIngestQuery($this->now, $traceId, ['sql' => 'select 1', 'file' => '', 'line' => 0, 'connection_type' => '']),
    );

    $rows = collect((new QueryStats)->queries($this->project, $this->range))->keyBy('sql');

    expect($rows['select * from `orders` where `user_id` = ?']['location'])->toBe('app/Http/Controllers/OrderController.php:42')
        ->and($rows['select * from `orders` where `user_id` = ?']['locations_count'])->toBe(2)
        ->and($rows['select 1']['location'])->toBeNull()
        ->and($rows['select 1']['locations_count'])->toBe(0);
});

it('groups the calls of a query by file and line in the detail', function () {
    $traceId = (string) Str::uuid();
    $sql = 'select * from `orders` where `user_id` = ?';

    ($this->send)(
        locationIngestQuery($this->now, $traceId, ['duration' => 1_000]),
        locationIngestQuery($this->now, $traceId, ['duration' => 3_000]),
        locationIngestQuery($this->now, $traceId, ['file' => 'app/Services/CheckoutService.php', 'line' => 12, 'connection_type' => 'write']),
        locationIngestQuery($this->now, $traceId, ['file' => '', 'line' => 0, 'connection_type' => '']),
    );

    $detail = (new QueryStats)->queryDetail($this->project, $this->range, sha1($sql));

    expect($detail['locations'])->toHaveCount(2)
        ->and($detail['locations'][0])->toBe([
            'file' => 'app/Http/Controllers/OrderController.php',
            'line' => 42,
            'total' => 2,
            'total_ms' => 4.0,
            'avg_ms' => 2.0,
        ])
        ->and($detail['locations'][1]['file'])->toBe('app/Services/CheckoutService.php')
        ->and($detail['connection_types'])->toBe(['read', 'write'])
        ->and($detail['calls'])->toHaveCount(4)
        ->and(collect($detail['calls'])->pluck('file')->filter()->unique()->sort()->values()->all())
        ->toBe(['app/Http/Controllers/OrderController.php', 'app/Services/CheckoutService.php']);
});

it('renders the query pages with the location', function () {
    $this->withoutVite();
    $sql = 'select * from `orders` where `user_id` = ?';

    ($this->send)(locationIngestQuery($this->now, (string) Str::uuid()));

    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]));

    $this->get(route('projects.queries.index', [$this->project]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/queries/index')
            ->where('queries.data.0.location', 'app/Http/Controllers/OrderController.php:42')
            ->where('queries.data.0.locations_count', 1));

    $this->get(route('projects.queries.show', [$this->project, sha1($sql)]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/queries/show')
            ->where('detail.locations.0.file', 'app/Http/Controllers/OrderController.php')
            ->where('detail.locations.0.line', 42)
            ->where('detail.connection_types', ['read'])
            ->where('detail.calls.0.line', 42));
});

it('returns the counts of recent requests from the route detail', function () {
    ($this->send)(locationIngestRequest($this->now, (string) Str::uuid(), ['lazy_loads' => 5, 'hydrated_models' => 321]));

    $detail = (new RequestStats)->routeDetail($this->project, $this->range, 'GET', '/orders/{order}');

    expect($detail['recent'][0]['lazy_loads'])->toBe(5)
        ->and($detail['recent'][0]['hydrated_models'])->toBe(321);
});

it('renders the counts on the request, command, job and scheduled task pages', function () {
    $this->withoutVite();
    $counts = ['lazy_loads' => 3, 'hydrated_models' => 90];

    ($this->send)(
        locationIngestRequest($this->now, (string) Str::uuid(), $counts),
        [
            'v' => 1,
            't' => 'command',
            'timestamp' => $this->now->getTimestamp() + 0.25,
            'trace_id' => (string) Str::uuid(),
            'name' => 'app:sync-users',
            'command' => 'app:sync-users',
            'exit_code' => 0,
            'duration' => 1_000_000,
        ] + $counts,
        [
            'v' => 1,
            't' => 'scheduled-task',
            'timestamp' => $this->now->getTimestamp() + 0.25,
            'trace_id' => (string) Str::uuid(),
            'name' => 'php artisan app:prune',
            'cron' => '0 * * * *',
            'timezone' => 'UTC',
            'repeat_seconds' => 0,
            'status' => 'processed',
            'duration' => 2_000_000,
        ] + $counts,
        locationIngestJobAttempt($this->now, $counts),
    );

    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]));

    $this->get(route('projects.requests.index', [$this->project, 'route_method' => 'GET', 'route_uri' => '/orders/{order}']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('routeDetail.recent.0.lazy_loads', 3)
            ->where('routeDetail.recent.0.hydrated_models', 90));

    $this->get(route('projects.commands.show', [$this->project, 'app:sync-users']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('detail.calls.0.lazy_loads', 3)
            ->where('detail.calls.0.hydrated_models', 90));

    $this->get(route('projects.jobs.show', [$this->project, 'App--Jobs--SendInvoice']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('detail.attempts.0.lazy_loads', 3)
            ->where('detail.attempts.0.hydrated_models', 90));

    $this->get(route('projects.scheduled-tasks.show', [$this->project, sha1('php artisan app:prune,0 * * * *,UTC')]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('detail.runs.0.lazy_loads', 3)
            ->where('detail.runs.0.hydrated_models', 90));
});
