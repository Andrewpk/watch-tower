<?php

use App\Jobs\ProcessWatchEvent;
use App\Models\Project;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Watch\EventStore;
use App\Watch\Fingerprinter;
use App\Watch\Sampler;
use App\Watch\Stats\QueryStats;
use App\Watch\Stats\TimeRange;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->store = new EventStore(new Fingerprinter);
    $this->correlationId = '11111111-2222-3333-4444-555555555555';
    $this->base = CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456;
});

function storeDbTimeQuery(Project $project, string $correlationId, float $durationMs, ?EventStore $store = null): void
{
    ($store ?? new EventStore(new Fingerprinter))->store($project, [
        'type' => 'query',
        'data' => ['request_id' => $correlationId, 'sql' => 'select 1', 'duration_ms' => $durationMs],
    ]);
}

function storeDbTimeRequest(Project $project, string $correlationId, array $extra = []): void
{
    (new EventStore(new Fingerprinter))->store($project, [
        'type' => 'request',
        'data' => ['request_id' => $correlationId, 'method' => 'GET', 'uri' => '/example', 'status_code' => 200, 'db_queries_count' => 2, ...$extra],
    ]);
}

it('sums query durations into the trace whichever record arrives first', function (bool $queriesFirst) {
    $steps = [
        fn () => storeDbTimeQuery($this->project, $this->correlationId, 1.25),
        fn () => storeDbTimeQuery($this->project, $this->correlationId, 0.5),
        fn () => storeDbTimeRequest($this->project, $this->correlationId),
    ];

    foreach ($queriesFirst ? $steps : [$steps[2], $steps[0], $steps[1]] as $step) {
        $step();
    }

    $trace = Trace::sole();

    expect($trace->is_stub)->toBeFalse()
        ->and($trace->db_queries_count)->toBe(2)
        ->and($trace->db_time_ms)->toBe(1.75)
        ->and(TraceQuery::count())->toBe(2);
})->with([
    'queries first' => [true],
    'request first' => [false],
]);

it('keeps the sum when a second request record for the trace arrives', function () {
    storeDbTimeQuery($this->project, $this->correlationId, 3.5);
    storeDbTimeRequest($this->project, $this->correlationId);
    storeDbTimeRequest($this->project, $this->correlationId);

    expect(Trace::sole()->db_time_ms)->toBe(3.5);
});

it('stores a redelivered query job as a second row and keeps the sum equal to the rows', function () {
    $job = new ProcessWatchEvent($this->project->id, [
        'type' => 'query',
        'id' => 'group-1',
        'data' => ['request_id' => $this->correlationId, 'sql' => 'select 1', 'duration_ms' => 0.75],
    ]);

    $job->handle($this->store, app(Sampler::class));
    $job->handle($this->store, app(Sampler::class));

    $trace = Trace::sole();

    expect(TraceQuery::count())->toBe(2)
        ->and($trace->db_time_ms)->toBe(1.5)
        ->and($trace->db_time_ms)->toEqualWithDelta((float) TraceQuery::sum('duration_ms'), 0.0001);
});

it('does not add time to the trace when the query row cannot be stored', function () {
    TraceQuery::creating(function (): never {
        throw new RuntimeException('insert failed');
    });

    expect(fn () => storeDbTimeQuery($this->project, $this->correlationId, 2.0, $this->store))
        ->toThrow(RuntimeException::class);

    expect(Trace::sole()->db_time_ms)->toBe(0.0)
        ->and(TraceQuery::count())->toBe(0);

    TraceQuery::flushEventListeners();
});

it('adds nothing for a zero duration query', function () {
    storeDbTimeQuery($this->project, $this->correlationId, 0.0);

    expect(Trace::sole()->db_time_ms)->toBe(0.0)
        ->and(TraceQuery::count())->toBe(1);
});

it('still accepts db_time_ms on a legacy request record', function () {
    storeDbTimeRequest($this->project, $this->correlationId, ['db_time_ms' => 40]);

    expect(Trace::sole()->db_time_ms)->toBe(40.0);
});

it('clamps a negative legacy db_time_ms to zero', function () {
    storeDbTimeRequest($this->project, $this->correlationId, ['db_time_ms' => -5]);

    expect(Trace::sole()->db_time_ms)->toBe(0.0);
});

it('stores fractional query durations and the trace sum from a Nightwatch batch', function () {
    $token = ingestToken($this->project->id);

    postNightwatchRecords([
        [
            'v' => 1,
            't' => 'request',
            'timestamp' => $this->base,
            'server' => 'web-1',
            'trace_id' => $this->correlationId,
            'method' => 'GET',
            'route_path' => '/example',
            'status_code' => 200,
            'queries' => 2,
            'duration' => 25_000,
        ],
        ['v' => 1, 't' => 'query', 'timestamp' => $this->base, '_group' => 'g1', 'trace_id' => $this->correlationId, 'sql' => 'select 1', 'duration' => 1_250, 'connection' => 'mysql'],
        ['v' => 1, 't' => 'query', 'timestamp' => $this->base + 0.01, '_group' => 'g2', 'trace_id' => $this->correlationId, 'sql' => 'select 2', 'duration' => 400, 'connection' => 'mysql'],
    ], $token)->assertOk();

    $durations = TraceQuery::orderBy('occurred_at')->get()->map(fn (TraceQuery $query): float => (float) $query->duration_ms)->all();
    $summary = (new QueryStats)->summary($this->project, new TimeRange(CarbonImmutable::parse('2026-06-30', 'UTC'), CarbonImmutable::now()->addDay(), '30d'));

    expect($durations)->toBe([1.25, 0.4])
        ->and(Trace::sole()->db_time_ms)->toEqualWithDelta(1.65, 0.0001)
        ->and($summary['totals']['total_ms'])->toEqualWithDelta(1.65, 0.0001);
});

it('keeps storing queries for a trace whose sum is near the old column limit', function () {
    // SQLite does not enforce the decimal precision, so this only proves the code path; the widened
    // decimal(15,3) column is what keeps MySQL strict from rejecting the increment.
    storeDbTimeQuery($this->project, $this->correlationId, 1.0);
    Trace::sole()->update(['db_time_ms' => 999_999_998.5]);

    storeDbTimeQuery($this->project, $this->correlationId, 0.75);
    storeDbTimeQuery($this->project, $this->correlationId, 999_999_999.999);

    expect(TraceQuery::count())->toBe(3)
        ->and(Trace::sole()->db_time_ms)->toBeGreaterThan(1_999_999_998.0);
});
