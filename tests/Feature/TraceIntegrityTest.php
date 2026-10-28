<?php

use App\Models\CacheEvent;
use App\Models\ErrorOccurrence;
use App\Models\LogEntry;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Watch\EventStore;
use App\Watch\Fingerprinter;
use App\Watch\Stats\DashboardStats;
use App\Watch\Stats\RequestStats;
use App\Watch\Stats\TimeRange;
use App\Watch\Stats\UserStats;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->store = new EventStore(new Fingerprinter);
    $this->project = Project::factory()->create();
});

it('keeps traces that share a correlation id independent per project', function () {
    $otherProject = Project::factory()->create();
    $correlationId = (string) Str::uuid();

    $this->store->store($this->project, [
        'type' => 'request',
        'data' => ['request_id' => $correlationId, 'method' => 'GET', 'uri' => '/alpha', 'status_code' => 200, 'user_id' => 'alpha-user'],
    ]);
    $this->store->store($otherProject, [
        'type' => 'request',
        'data' => ['request_id' => $correlationId, 'method' => 'POST', 'uri' => '/beta', 'status_code' => 500, 'user_id' => 'beta-user'],
    ]);

    expect(Trace::where('correlation_id', $correlationId)->count())->toBe(2);

    $first = Trace::where('project_id', $this->project->id)->where('correlation_id', $correlationId)->first();
    $second = Trace::where('project_id', $otherProject->id)->where('correlation_id', $correlationId)->first();

    expect($first->method)->toBe('GET')
        ->and($first->uri)->toBe('/alpha')
        ->and($first->status_code)->toBe(200)
        ->and($first->user_identifier)->toBe('alpha-user')
        ->and($second->method)->toBe('POST')
        ->and($second->uri)->toBe('/beta')
        ->and($second->status_code)->toBe(500)
        ->and($second->user_identifier)->toBe('beta-user');

    $this->store->store($otherProject, [
        'type' => 'request',
        'data' => ['request_id' => $correlationId, 'method' => 'PUT', 'uri' => '/beta-updated', 'status_code' => 201],
    ]);
    $this->store->store($otherProject, [
        'type' => 'query',
        'data' => ['request_id' => $correlationId, 'sql' => 'SELECT 1', 'duration_ms' => 1],
    ]);

    $first->refresh();
    $second->refresh();

    expect($first->project_id)->toBe($this->project->id)
        ->and($first->uri)->toBe('/alpha')
        ->and($first->status_code)->toBe(200)
        ->and($second->project_id)->toBe($otherProject->id)
        ->and($second->uri)->toBe('/beta-updated')
        ->and(TraceQuery::where('trace_id', $first->id)->count())->toBe(0)
        ->and(TraceQuery::where('trace_id', $second->id)->count())->toBe(1);
});

it('does not link a child record to another project\'s trace with the same correlation id', function () {
    $otherProject = Project::factory()->create();
    $correlationId = (string) Str::uuid();

    $this->store->store($this->project, [
        'type' => 'request',
        'data' => ['request_id' => $correlationId, 'method' => 'GET', 'uri' => '/alpha'],
    ]);
    $this->store->store($otherProject, [
        'type' => 'log',
        'data' => ['request_id' => $correlationId, 'level' => 'info', 'message' => 'from the other project'],
    ]);

    $ownTrace = Trace::where('project_id', $this->project->id)->where('correlation_id', $correlationId)->first();
    $otherTrace = Trace::where('project_id', $otherProject->id)->where('correlation_id', $correlationId)->first();
    $log = LogEntry::where('project_id', $otherProject->id)->first();

    expect($ownTrace->is_stub)->toBeFalse()
        ->and($otherTrace->is_stub)->toBeTrue()
        ->and($log->trace_id)->toBe($otherTrace->id);
});

it('links a query and an exception that arrive before the request record and keeps has_errors', function () {
    $correlationId = (string) Str::uuid();

    $this->store->store($this->project, [
        'type' => 'query',
        'data' => ['request_id' => $correlationId, 'sql' => 'SELECT * FROM users', 'duration_ms' => 4.2],
    ]);
    $this->store->store($this->project, [
        'type' => 'exception',
        'data' => ['request_id' => $correlationId, 'class' => 'RuntimeException', 'file' => 'app/A.php', 'line' => 5, 'message' => 'boom'],
    ]);

    $stub = Trace::where('project_id', $this->project->id)->where('correlation_id', $correlationId)->first();

    expect($stub->is_stub)->toBeTrue()
        ->and($stub->has_errors)->toBeTrue();

    $this->store->store($this->project, [
        'type' => 'request',
        'data' => ['request_id' => $correlationId, 'method' => 'POST', 'uri' => '/orders', 'status_code' => 500, 'duration_ms' => 120, 'environment' => 'production'],
    ]);

    expect(Trace::where('correlation_id', $correlationId)->count())->toBe(1);

    $trace = $stub->refresh();

    expect($trace->is_stub)->toBeFalse()
        ->and($trace->method)->toBe('POST')
        ->and($trace->uri)->toBe('/orders')
        ->and($trace->status_code)->toBe(500)
        ->and($trace->environment)->toBe('production')
        ->and($trace->has_errors)->toBeTrue()
        ->and(TraceQuery::where('trace_id', $trace->id)->count())->toBe(1)
        ->and(ErrorOccurrence::where('trace_id', $trace->id)->count())->toBe(1);
});

it('links logs, cache events, events and jobs that arrive before the request record', function () {
    $correlationId = (string) Str::uuid();

    $this->store->store($this->project, [
        'type' => 'log',
        'data' => ['request_id' => $correlationId, 'level' => 'info', 'message' => 'early log'],
    ]);
    $this->store->store($this->project, [
        'type' => 'cache-event',
        'data' => ['request_id' => $correlationId, 'key' => 'k', 'operation' => 'write'],
    ]);
    $this->store->store($this->project, [
        'type' => 'job',
        'data' => ['request_id' => $correlationId, 'class' => 'App\\Jobs\\Early', 'status' => 'queued'],
    ]);
    $this->store->store($this->project, [
        'type' => 'request',
        'data' => ['request_id' => $correlationId, 'method' => 'GET', 'uri' => '/late'],
    ]);

    $trace = Trace::where('correlation_id', $correlationId)->sole();

    expect(LogEntry::sole()->trace_id)->toBe($trace->id)
        ->and(CacheEvent::sole()->trace_id)->toBe($trace->id)
        ->and(QueueJobRun::sole()->trace_id)->toBe($trace->id)
        ->and($trace->is_stub)->toBeFalse();
});

it('never downgrades has_errors or has_slow_queries when the request record reports false', function () {
    $correlationId = (string) Str::uuid();

    $this->store->store($this->project, [
        'type' => 'request',
        'data' => ['request_id' => $correlationId, 'method' => 'GET', 'uri' => '/x', 'has_errors' => true, 'has_slow_queries' => true],
    ]);
    $this->store->store($this->project, [
        'type' => 'request',
        'data' => ['request_id' => $correlationId, 'method' => 'GET', 'uri' => '/x', 'has_errors' => false, 'has_slow_queries' => false],
    ]);

    $trace = Trace::where('correlation_id', $correlationId)->sole();

    expect($trace->has_errors)->toBeTrue()
        ->and($trace->has_slow_queries)->toBeTrue();
});

it('does not count stub traces in request totals', function () {
    $range = TimeRange::fromLabel('24h');
    $stubOnlyId = (string) Str::uuid();

    $this->store->store($this->project, [
        'type' => 'query',
        'data' => ['request_id' => $stubOnlyId, 'sql' => 'SELECT 1', 'duration_ms' => 1],
    ]);
    $this->store->store($this->project, [
        'type' => 'exception',
        'data' => ['request_id' => $stubOnlyId, 'class' => 'RuntimeException', 'file' => 'app/A.php', 'line' => 5, 'message' => 'boom', 'user_id' => 'u1'],
    ]);

    expect(Trace::where('project_id', $this->project->id)->where('is_stub', true)->count())->toBe(1);

    $requests = new RequestStats;
    $dashboard = new DashboardStats;
    $users = new UserStats;

    expect($requests->summary($this->project, $range)['totals']['total'])->toBe(0)
        ->and($requests->routes($this->project, $range))->toBe([])
        ->and($dashboard->forProject($this->project, $range)['activity']['requests']['total'])->toBe(0)
        ->and($users->summary($this->project, $range)['total_requests'])->toBe(0);

    $this->store->store($this->project, [
        'type' => 'request',
        'data' => ['request_id' => (string) Str::uuid(), 'method' => 'GET', 'uri' => '/real', 'status_code' => 200, 'duration_ms' => 30],
    ]);

    expect($requests->summary($this->project, $range)['totals']['total'])->toBe(1)
        ->and($requests->routes($this->project, $range))->toHaveCount(1)
        ->and($requests->routes($this->project, $range)[0]['uri'])->toBe('/real')
        ->and($dashboard->forProject($this->project, $range)['activity']['requests']['total'])->toBe(1)
        ->and($users->summary($this->project, $range)['total_requests'])->toBe(1);

    $this->store->store($this->project, [
        'type' => 'request',
        'data' => ['request_id' => $stubOnlyId, 'method' => 'GET', 'uri' => '/filled', 'status_code' => 500, 'duration_ms' => 10],
    ]);

    expect($requests->summary($this->project, $range)['totals']['total'])->toBe(2)
        ->and($requests->summary($this->project, $range)['totals']['server_error'])->toBe(1);
});

it('returns the existing stub when a concurrent job creates it between the lookup and the insert', function () {
    $correlationId = (string) Str::uuid();
    $competitorId = (string) Str::uuid();
    $competitorInserted = false;

    DB::listen(function (QueryExecuted $query) use (&$competitorInserted, $correlationId, $competitorId): void {
        if ($competitorInserted || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '"traces"')) {
            return;
        }

        $competitorInserted = true;

        DB::table('traces')->insert([
            'id' => $competitorId,
            'project_id' => $this->project->id,
            'correlation_id' => $correlationId,
            'method' => '',
            'uri' => '',
            'is_stub' => true,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->store->store($this->project, [
        'type' => 'query',
        'data' => ['request_id' => $correlationId, 'sql' => 'SELECT 1', 'duration_ms' => 1],
    ]);

    expect($competitorInserted)->toBeTrue()
        ->and(Trace::where('correlation_id', $correlationId)->count())->toBe(1)
        ->and(TraceQuery::sole()->trace_id)->toBe($competitorId);
});

it('scopes the trace unique index to the project', function () {
    $uniqueColumns = collect(Schema::getIndexes('traces'))
        ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index): array => $index['columns'])
        ->values()
        ->all();

    expect($uniqueColumns)->toContain(['project_id', 'correlation_id'])
        ->and($uniqueColumns)->not->toContain(['correlation_id']);
});
