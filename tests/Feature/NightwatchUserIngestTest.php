<?php

use App\Models\EndUser;
use App\Models\ErrorOccurrence;
use App\Models\Project;
use App\Models\Trace;
use App\Watch\Stats\TimeRange;
use App\Watch\Stats\UserStats;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->token = ingestToken($this->project->id);
    $this->base = CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456;
});

function nightwatchUser(float $timestamp, string $id = 'user-1', string $name = 'Jane Example', string $email = 'jane@example.com'): array
{
    return ['v' => 1, 't' => 'user', 'timestamp' => $timestamp, 'id' => $id, 'name' => $name, 'username' => $email];
}

function nightwatchRequest(float $timestamp, string $traceId, ?string $userId = 'user-1'): array
{
    return [
        'v' => 1,
        't' => 'request',
        'timestamp' => $timestamp,
        'deploy' => 'production',
        'server' => 'web-1',
        'trace_id' => $traceId,
        'method' => 'GET',
        'route_path' => '/dashboard',
        'status_code' => 200,
        'user' => $userId ?? '',
        'duration' => 25_000,
    ];
}

function nightwatchException(float $timestamp, string $traceId, ?string $userId = 'user-1'): array
{
    return [
        'v' => 3,
        't' => 'exception',
        'timestamp' => $timestamp,
        'deploy' => 'production',
        'server' => 'web-1',
        '_group' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'trace_id' => $traceId,
        'class' => 'RuntimeException',
        'message' => 'Something broke',
        'file' => 'app/Example.php',
        'line' => 12,
        'user' => $userId ?? '',
    ];
}

it('gives a trace the name and email of a user record that came before its request', function () {
    postNightwatchRecords([
        nightwatchUser($this->base),
        nightwatchRequest($this->base + 0.01, 'trace-1'),
    ], $this->token)->assertOk();

    $trace = Trace::where('correlation_id', 'trace-1')->firstOrFail();

    expect($trace->user_identifier)->toBe('user-1')
        ->and($trace->user_name)->toBe('Jane Example')
        ->and($trace->user_email)->toBe('jane@example.com')
        ->and($trace->is_stub)->toBeFalse();

    $user = EndUser::where('project_id', $this->project->id)->sole();

    expect($user->identifier)->toBe('user-1')
        ->and($user->name)->toBe('Jane Example')
        ->and($user->email)->toBe('jane@example.com')
        ->and($user->last_seen_at->equalTo(CarbonImmutable::createFromTimestamp($this->base)->startOfSecond()))->toBeTrue();
});

it('backfills traces whose request came before the user record', function () {
    postNightwatchRecords([nightwatchRequest($this->base, 'trace-1')], $this->token)->assertOk();

    expect(Trace::where('correlation_id', 'trace-1')->sole())
        ->user_name->toBeNull()
        ->user_email->toBeNull();

    postNightwatchRecords([nightwatchUser($this->base + 0.5)], $this->token)->assertOk();

    expect(Trace::where('correlation_id', 'trace-1')->sole())
        ->user_name->toBe('Jane Example')
        ->user_email->toBe('jane@example.com');
});

it('only backfills the same user in the same project', function () {
    $other = Project::factory()->create();

    postNightwatchRecords([
        nightwatchRequest($this->base, 'trace-1', 'user-1'),
        nightwatchRequest($this->base, 'trace-2', 'user-2'),
    ], $this->token)->assertOk();
    postNightwatchRecords([nightwatchRequest($this->base, 'trace-3', 'user-1')], ingestToken($other->id))->assertOk();

    postNightwatchRecords([nightwatchUser($this->base + 0.5)], $this->token)->assertOk();

    expect(Trace::where('correlation_id', 'trace-1')->sole()->user_name)->toBe('Jane Example')
        ->and(Trace::where('correlation_id', 'trace-2')->sole()->user_name)->toBeNull()
        ->and(Trace::where('correlation_id', 'trace-3')->sole()->user_name)->toBeNull();
});

it('fills the user of an error occurrence recorded before and after the user record', function () {
    postNightwatchRecords([nightwatchException($this->base, 'trace-1')], $this->token)->assertOk();

    expect(ErrorOccurrence::sole())
        ->user_identifier->toBe('user-1')
        ->user_name->toBeNull()
        ->user_email->toBeNull();

    postNightwatchRecords([
        nightwatchUser($this->base + 0.5),
        nightwatchException($this->base + 1, 'trace-2'),
    ], $this->token)->assertOk();

    $occurrences = ErrorOccurrence::orderBy('occurred_at')->get();

    expect($occurrences)->toHaveCount(2)
        ->and($occurrences->pluck('user_name')->all())->toBe(['Jane Example', 'Jane Example'])
        ->and($occurrences->pluck('user_email')->all())->toBe(['jane@example.com', 'jane@example.com']);
});

it('updates the end user from a newer user record but not from an older one', function () {
    postNightwatchRecords([nightwatchUser($this->base)], $this->token)->assertOk();

    postNightwatchRecords([nightwatchUser($this->base + 60, name: 'Jane Newname', email: 'jane.new@example.com')], $this->token)->assertOk();

    expect(EndUser::sole())
        ->name->toBe('Jane Newname')
        ->email->toBe('jane.new@example.com');

    postNightwatchRecords([nightwatchUser($this->base - 60, name: 'Jane Oldname', email: 'jane.old@example.com')], $this->token)->assertOk();

    $user = EndUser::sole();

    expect($user->name)->toBe('Jane Newname')
        ->and($user->email)->toBe('jane.new@example.com')
        ->and($user->last_seen_at->equalTo(CarbonImmutable::createFromTimestamp($this->base + 60)->startOfSecond()))->toBeTrue();
});

it('keeps a stored email when a newer user record only brings a name', function () {
    postNightwatchRecords([nightwatchUser($this->base)], $this->token)->assertOk();
    postNightwatchRecords([nightwatchUser($this->base + 60, name: 'Jane Newname', email: '')], $this->token)->assertOk();

    expect(EndUser::sole())
        ->name->toBe('Jane Newname')
        ->email->toBe('jane@example.com');
});

it('does not erase backfilled details when the request is processed afterwards', function () {
    postNightwatchRecords([
        nightwatchRequest($this->base, 'trace-1'),
        nightwatchUser($this->base + 0.5),
    ], $this->token)->assertOk();

    expect(Trace::where('correlation_id', 'trace-1')->sole()->user_name)->toBe('Jane Example');

    // The same request record processed again (a queue retry) must not null out what the user record backfilled.
    postNightwatchRecords([nightwatchRequest($this->base, 'trace-1')], $this->token)->assertOk();

    expect(Trace::where('correlation_id', 'trace-1')->sole())
        ->user_name->toBe('Jane Example')
        ->user_email->toBe('jane@example.com')
        ->user_identifier->toBe('user-1');
});

it('does not null out backfilled details for a request whose trace already existed as a stub', function () {
    $stub = Trace::factory()->create([
        'project_id' => $this->project->id,
        'correlation_id' => 'trace-1',
        'user_identifier' => 'user-1',
        'user_name' => 'Jane Example',
        'user_email' => 'jane@example.com',
        'is_stub' => true,
    ]);

    postNightwatchRecords([nightwatchRequest($this->base, 'trace-1')], $this->token)->assertOk();

    expect($stub->refresh())
        ->is_stub->toBeFalse()
        ->user_name->toBe('Jane Example')
        ->user_email->toBe('jane@example.com');
});

it('does nothing for a user record without name and username', function () {
    postNightwatchRecords([
        nightwatchRequest($this->base, 'trace-1'),
        nightwatchUser($this->base + 0.5, name: '', email: ''),
    ], $this->token)->assertOk();

    expect(EndUser::count())->toBe(0)
        ->and(Trace::where('correlation_id', 'trace-1')->sole())
        ->user_name->toBeNull()
        ->user_email->toBeNull();
});

it('does nothing for a user record without an id', function () {
    postNightwatchRecords([nightwatchUser($this->base, id: '')], $this->token)
        ->assertOk()
        ->assertJson(['records_received' => 1, 'records_queued' => 0]);

    expect(EndUser::count())->toBe(0);
});

it('does not touch traces that already carry user details', function () {
    $trace = Trace::factory()->create([
        'project_id' => $this->project->id,
        'user_identifier' => 'user-1',
        'user_name' => 'Jane Existing',
        'user_email' => null,
    ]);

    postNightwatchRecords([nightwatchUser($this->base)], $this->token)->assertOk();

    expect($trace->refresh())
        ->user_name->toBe('Jane Existing')
        ->user_email->toBeNull();
});

it('lists the user name and email in the users page stats', function () {
    $now = CarbonImmutable::now()->getTimestamp() - 60 + 0.5;

    postNightwatchRecords([
        nightwatchUser($now),
        nightwatchRequest($now + 0.01, 'trace-1'),
        nightwatchRequest($now + 1, 'trace-2'),
    ], $this->token)->assertOk();

    $rows = (new UserStats)->list($this->project, TimeRange::fromLabel('24h'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0])
        ->id->toBe('user-1')
        ->name->toBe('Jane Example')
        ->email->toBe('jane@example.com')
        ->requests_total->toBe(2);
});

it('is cleaned up with the project', function () {
    postNightwatchRecords([nightwatchUser($this->base)], $this->token)->assertOk();

    expect(EndUser::count())->toBe(1);

    $this->project->delete();

    expect(EndUser::count())->toBe(0);
});
