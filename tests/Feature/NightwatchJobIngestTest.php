<?php

use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\Trace;
use App\Watch\EventStore;
use App\Watch\Stats\JobStats;
use App\Watch\Stats\TimeRange;
use App\Watch\Stats\UserStats;
use Carbon\CarbonImmutable;

const JOB_FIXTURE_ID = '22222222-2222-4222-8222-222222222222';
const JOB_FIXTURE_TRACE = '11111111-1111-4111-8111-111111111111';

function jobFixtureTime(int $offsetSeconds = 0): float
{
    return CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + $offsetSeconds + 0.123456;
}

function queuedJobRecord(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'queued-job',
        'timestamp' => jobFixtureTime(-5),
        'deploy' => 'production',
        'server' => 'web-1',
        'trace_id' => JOB_FIXTURE_TRACE,
        'user' => '42',
        'job_id' => JOB_FIXTURE_ID,
        'name' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'duration' => 480,
    ], $overrides);
}

function attemptRecord(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'job-attempt',
        'timestamp' => jobFixtureTime(),
        'deploy' => 'production',
        'server' => 'worker-1',
        'trace_id' => JOB_FIXTURE_TRACE,
        'user' => '42',
        'job_id' => JOB_FIXTURE_ID,
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

function jobStatsRange(): TimeRange
{
    return new TimeRange(CarbonImmutable::parse('2026-06-30', 'UTC'), CarbonImmutable::now()->addDay(), '30d');
}

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->token = ingestToken($this->project->id);
    $this->send = fn (array ...$records) => postNightwatchRecords($records, $this->token)->assertOk();
});

it('merges a queued job and its processed attempt into one completed run', function () {
    ($this->send)(queuedJobRecord(), attemptRecord());

    $run = QueueJobRun::sole();

    expect($run->job_id)->toBe(JOB_FIXTURE_ID)
        ->and($run->status)->toBe('completed')
        ->and($run->attempts)->toBe(1)
        ->and($run->job_class)->toBe('App\\Jobs\\SendInvoice')
        ->and($run->duration_ms)->toBe(1250)
        ->and($run->dispatched_at->toIso8601String())->toBe('2026-07-01T10:14:55+00:00')
        ->and($run->started_at->toIso8601String())->toBe('2026-07-01T10:15:00+00:00')
        ->and($run->completed_at->toIso8601String())->toBe('2026-07-01T10:15:01+00:00')
        ->and($run->failed_at)->toBeNull()
        ->and($run->user_identifier)->toBe('42')
        ->and($run->trace_id)->toBe(Trace::where('correlation_id', JOB_FIXTURE_TRACE)->value('id'));
});

it('keeps the dispatch details when the attempt arrives before the queued record', function () {
    ($this->send)(attemptRecord(['user' => '']));
    ($this->send)(queuedJobRecord());

    $run = QueueJobRun::sole();

    expect($run->status)->toBe('completed')
        ->and($run->attempts)->toBe(1)
        ->and($run->dispatched_at->toIso8601String())->toBe('2026-07-01T10:14:55+00:00')
        ->and($run->started_at->toIso8601String())->toBe('2026-07-01T10:15:00+00:00')
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->duration_ms)->toBe(1250)
        ->and($run->user_identifier)->toBe('42');
});

it('ends on completed with the latest attempt number after a release and a retry', function () {
    ($this->send)(
        queuedJobRecord(),
        attemptRecord(['status' => 'released', 'duration' => 200_000]),
        attemptRecord([
            'attempt_id' => '33333333-3333-4333-8333-333333333332',
            'attempt' => 2,
            'timestamp' => jobFixtureTime(30),
        ]),
    );

    $run = QueueJobRun::sole();

    expect($run->status)->toBe('completed')
        ->and($run->attempts)->toBe(2)
        ->and($run->started_at->toIso8601String())->toBe('2026-07-01T10:15:30+00:00')
        ->and($run->duration_ms)->toBe(1250);
});

it('ignores an older attempt that arrives after a newer one', function () {
    ($this->send)(
        attemptRecord([
            'attempt_id' => '33333333-3333-4333-8333-333333333332',
            'attempt' => 2,
            'timestamp' => jobFixtureTime(30),
        ]),
        attemptRecord(['status' => 'released', 'duration' => 200_000]),
        queuedJobRecord(),
    );

    $run = QueueJobRun::sole();

    expect($run->status)->toBe('completed')
        ->and($run->attempts)->toBe(2)
        ->and($run->started_at->toIso8601String())->toBe('2026-07-01T10:15:30+00:00')
        ->and($run->duration_ms)->toBe(1250)
        ->and($run->dispatched_at)->not->toBeNull();
});

it('orders attempts that start within the same second by attempt number', function () {
    ($this->send)(
        attemptRecord(['attempt' => 2, 'attempt_id' => '33333333-3333-4333-8333-333333333332', 'timestamp' => jobFixtureTime(0) + 0.5]),
        attemptRecord(['status' => 'released', 'attempt' => 1]),
    );

    $run = QueueJobRun::sole();

    expect($run->status)->toBe('completed')
        ->and($run->attempts)->toBe(2);
});

it('is idempotent when the same attempt is delivered twice', function () {
    ($this->send)(queuedJobRecord(), attemptRecord());
    $before = QueueJobRun::sole()->only(['status', 'attempts', 'started_at', 'completed_at', 'duration_ms', 'dispatched_at']);

    ($this->send)(attemptRecord());

    expect(QueueJobRun::count())->toBe(1)
        ->and(QueueJobRun::sole()->only(['status', 'attempts', 'started_at', 'completed_at', 'duration_ms', 'dispatched_at']))->toEqual($before);
});

it('is idempotent when the queued record is delivered twice', function () {
    ($this->send)(queuedJobRecord(), queuedJobRecord());

    $run = QueueJobRun::sole();

    expect($run->status)->toBe('queued')
        ->and($run->attempts)->toBe(0)
        ->and($run->duration_ms)->toBeNull();
});

it('does not let a late queued record undo the attempt outcome', function () {
    ($this->send)(attemptRecord(), queuedJobRecord(['queue' => 'other']));

    $run = QueueJobRun::sole();

    expect($run->status)->toBe('completed')
        ->and($run->queue)->toBe('default');
});

it('records the exception message and failure time of a failed attempt', function () {
    ($this->send)(queuedJobRecord(), attemptRecord(['status' => 'failed', 'exception_preview' => 'Connection refused']));

    $run = QueueJobRun::sole();

    expect($run->status)->toBe('failed')
        ->and($run->failed_at->toIso8601String())->toBe('2026-07-01T10:15:01+00:00')
        ->and($run->completed_at)->toBeNull()
        ->and($run->exception)->toBe(['message' => 'Connection refused']);
});

it('clears an earlier failure when a later attempt succeeds', function () {
    ($this->send)(
        attemptRecord(['status' => 'failed', 'exception_preview' => 'Timeout']),
        attemptRecord([
            'attempt_id' => '33333333-3333-4333-8333-333333333332',
            'attempt' => 2,
            'timestamp' => jobFixtureTime(60),
        ]),
    );

    $run = QueueJobRun::sole();

    expect($run->status)->toBe('completed')
        ->and($run->failed_at)->toBeNull()
        ->and($run->exception)->toBeNull();
});

it('keeps runs of the same job id in different projects apart', function () {
    $other = Project::factory()->create();

    ($this->send)(attemptRecord());
    postNightwatchRecords([attemptRecord()], ingestToken($other->id))->assertOk();

    expect(QueueJobRun::count())->toBe(2)
        ->and(QueueJobRun::where('project_id', $other->id)->count())->toBe(1);
});

it('feeds the job statistics', function () {
    ($this->send)(
        queuedJobRecord(),
        attemptRecord(),
        queuedJobRecord(['job_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']),
        queuedJobRecord(['job_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']),
        attemptRecord(['job_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'attempt_id' => '44444444-4444-4444-8444-444444444444', 'status' => 'failed', 'duration' => 3_000_000]),
    );

    $summary = (new JobStats)->summary($this->project, jobStatsRange());

    expect($summary['totals'])->toBe(['total' => 3, 'queued' => 1, 'processed' => 1, 'released' => 0, 'failed' => 1])
        ->and($summary['duration']['min_ms'])->toBe(1250)
        ->and($summary['duration']['max_ms'])->toBe(3000);
});

it('attributes jobs to the dispatching user', function () {
    ($this->send)(
        [
            'v' => 1,
            't' => 'request',
            'timestamp' => jobFixtureTime(-10),
            'trace_id' => JOB_FIXTURE_TRACE,
            'method' => 'POST',
            'url' => 'https://app.example.test/invoices',
            'route_path' => '/invoices',
            'status_code' => 200,
            'duration' => 100_000,
            'user' => '42',
            'deploy' => 'production',
        ],
        queuedJobRecord(),
        attemptRecord(),
    );

    $users = collect((new UserStats)->list($this->project, jobStatsRange()))->keyBy('id');

    expect(QueueJobRun::sole()->user_identifier)->toBe('42')
        ->and($users['42']['queued_jobs'])->toBe(1);
});

it('still stores a legacy job event without a job id as a separate row', function () {
    $store = app(EventStore::class);
    $event = ['type' => 'job', 'data' => ['class' => 'App\\Jobs\\Legacy', 'status' => 'completed', 'attempts' => 1, 'user_id' => '7']];

    $store->store($this->project, $event);
    $store->store($this->project, $event);

    expect(QueueJobRun::count())->toBe(2)
        ->and(QueueJobRun::whereNull('job_id')->count())->toBe(2)
        ->and(QueueJobRun::first()->user_identifier)->toBe('7');
});
