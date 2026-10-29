<?php

use App\Watch\NightwatchTranslator;
use Carbon\CarbonImmutable;

function commandWireRecord(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'command',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456,
        'deploy' => 'v1.4.2',
        'server' => 'web-1.example.test',
        'trace_id' => '11111111-2222-3333-4444-555555555555',
        'class' => 'App\\Console\\Commands\\SyncUsers',
        'name' => 'app:sync-users',
        'command' => 'app:sync-users --force 42',
        'exit_code' => 0,
        'duration' => 1_250_000,
    ], $overrides);
}

function scheduledTaskWireRecord(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'scheduled-task',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.5,
        'deploy' => 'v1.4.2',
        'server' => 'web-1.example.test',
        'trace_id' => '66666666-7777-8888-9999-000000000000',
        'name' => 'php artisan app:prune',
        'cron' => '0 * * * *',
        'timezone' => 'UTC',
        'repeat_seconds' => 0,
        'status' => 'processed',
        'duration' => 2_500_000,
    ], $overrides);
}

function translateCommandWireRecord(array $record): array
{
    return (new NightwatchTranslator)->translate($record);
}

it('translates a successful command', function () {
    $event = translateCommandWireRecord(commandWireRecord());

    expect($event['type'])->toBe('command')
        ->and($event['id'])->toBe('11111111-2222-3333-4444-555555555555')
        ->and($event['data'])->toMatchArray([
            'request_id' => '11111111-2222-3333-4444-555555555555',
            'command' => 'app:sync-users',
            'status' => 'completed',
            'exit_code' => 0,
            'duration_ms' => 1250,
            'occurred_at' => '2026-07-01T10:15:00+00:00',
        ])
        ->and($event['data'])->not->toHaveKey('environment');
});

it('marks a command with a non-zero exit code as failed', function (int $exitCode) {
    $event = translateCommandWireRecord(commandWireRecord(['exit_code' => $exitCode]));

    expect($event['data']['status'])->toBe('failed')
        ->and($event['data']['exit_code'])->toBe($exitCode);
})->with([1, 2, 127]);

it('splits the raw command line into arguments and options', function () {
    $data = translateCommandWireRecord(commandWireRecord(['command' => 'app:sync-users   --force 42 -v  --limit=5 acme']))['data'];

    expect($data['arguments'])->toBe(['42', 'acme'])
        ->and($data['options'])->toBe(['--force', '-v', '--limit=5']);
});

it('leaves arguments and options null for a bare command', function () {
    $data = translateCommandWireRecord(commandWireRecord(['command' => 'app:sync-users']))['data'];

    expect($data['arguments'])->toBeNull()
        ->and($data['options'])->toBeNull();
});

it('drops the command name after a leading global option', function () {
    $data = translateCommandWireRecord(commandWireRecord(['command' => '--env=staging app:sync-users --force 42']))['data'];

    expect($data['arguments'])->toBe(['42'])
        ->and($data['options'])->toBe(['--env=staging', '--force']);
});

it('only drops the command name once', function () {
    $data = translateCommandWireRecord(commandWireRecord(['command' => 'app:sync-users app:sync-users']))['data'];

    expect($data['arguments'])->toBe(['app:sync-users']);
});

it('clamps a command duration to the unsigned integer column range', function () {
    $data = translateCommandWireRecord(commandWireRecord(['duration' => 9_000_000_000_000_000]))['data'];

    expect($data['duration_ms'])->toBe(4_294_967_295);
});

it('truncates a long command name to the column length', function () {
    $data = translateCommandWireRecord(commandWireRecord(['name' => str_repeat('a', 300), 'command' => '']))['data'];

    expect(mb_strlen($data['command']))->toBe(255);
});

it('translates a processed scheduled task', function () {
    $event = translateCommandWireRecord(scheduledTaskWireRecord());

    expect($event['type'])->toBe('scheduled-task')
        ->and($event['id'])->toBe('66666666-7777-8888-9999-000000000000')
        ->and($event['data'])->toMatchArray([
            'request_id' => '66666666-7777-8888-9999-000000000000',
            'task' => 'php artisan app:prune',
            'schedule' => '0 * * * *',
            'status' => 'processed',
            'duration_ms' => 2500,
            'occurred_at' => '2026-07-01T10:15:00+00:00',
        ])
        ->and($event['data'])->not->toHaveKey('environment');
});

it('keeps the failed status of a scheduled task', function () {
    expect(translateCommandWireRecord(scheduledTaskWireRecord(['status' => 'failed']))['data'])
        ->toMatchArray(['status' => 'failed', 'duration_ms' => 2500]);
});

it('drops the duration of a skipped scheduled task', function () {
    expect(translateCommandWireRecord(scheduledTaskWireRecord(['status' => 'skipped', 'duration' => 0]))['data'])
        ->toMatchArray(['status' => 'skipped', 'duration_ms' => null]);
});

it('derives a stable 40 character task hash', function () {
    $hash = translateCommandWireRecord(scheduledTaskWireRecord())['data']['task_hash'];

    expect($hash)->toMatch('/^[a-f0-9]{40}$/')
        ->and($hash)->toBe(sha1('php artisan app:prune,0 * * * *,UTC'))
        ->and(translateCommandWireRecord(scheduledTaskWireRecord(['trace_id' => 'another-run']))['data']['task_hash'])->toBe($hash);
});

it('changes the task hash with the task identity', function () {
    $base = translateCommandWireRecord(scheduledTaskWireRecord())['data']['task_hash'];
    $repeating = translateCommandWireRecord(scheduledTaskWireRecord(['repeat_seconds' => 10]))['data']['task_hash'];

    expect($repeating)->toBe(sha1('php artisan app:prune,0 * * * *,UTC,10'))
        ->and($repeating)->not->toBe($base)
        ->and(translateCommandWireRecord(scheduledTaskWireRecord(['cron' => '5 * * * *']))['data']['task_hash'])->not->toBe($base)
        ->and(translateCommandWireRecord(scheduledTaskWireRecord(['timezone' => 'Europe/Paris']))['data']['task_hash'])->not->toBe($base)
        ->and(translateCommandWireRecord(scheduledTaskWireRecord(['name' => 'php artisan other']))['data']['task_hash'])->not->toBe($base);
});

it('computes the next run of an hourly task', function () {
    expect(translateCommandWireRecord(scheduledTaskWireRecord())['data']['next_run_at'])->toBe('2026-07-01T11:00:00+00:00');
});

it('computes the next run in the task timezone and returns it in UTC', function () {
    // 2026-07-01 00:00Z is 20:00 on June 30 in New York (EDT, UTC-4): the next 02:00 is 06:00Z the same day.
    $beforeTwo = scheduledTaskWireRecord([
        'cron' => '0 2 * * *',
        'timezone' => 'America/New_York',
        'timestamp' => CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC')->getTimestamp(),
    ]);
    // At 10:15Z the 02:00 EDT run has already happened, so the next one is the following day.
    $afterTwo = scheduledTaskWireRecord(['cron' => '0 2 * * *', 'timezone' => 'America/New_York']);

    expect(translateCommandWireRecord($beforeTwo)['data']['next_run_at'])->toBe('2026-07-01T06:00:00+00:00')
        ->and(translateCommandWireRecord($afterTwo)['data']['next_run_at'])->toBe('2026-07-02T06:00:00+00:00');
});

it('schedules a sub-minute task one interval after the run while it stays inside the minute', function () {
    $data = translateCommandWireRecord(scheduledTaskWireRecord(['cron' => '* * * * *', 'repeat_seconds' => 10]))['data'];

    expect($data['next_run_at'])->toBe('2026-07-01T10:15:10+00:00');
});

it('follows the cron once a sub-minute task would leave the minute', function () {
    // everyTenSeconds()->weekdays(): the last run of Friday is 23:59:50, so the next one is Monday.
    $weekdays = scheduledTaskWireRecord([
        'cron' => '* * * * 1-5',
        'repeat_seconds' => 10,
        'timestamp' => CarbonImmutable::parse('2026-07-03 23:59:50', 'UTC')->getTimestamp(),
    ]);
    // everyThirtySeconds()->hourly(): the second run of the hour is 10:00:30, so the next one is 11:00.
    $hourly = scheduledTaskWireRecord([
        'cron' => '0 * * * *',
        'repeat_seconds' => 30,
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:00:30', 'UTC')->getTimestamp(),
    ]);

    expect(CarbonImmutable::parse('2026-07-03', 'UTC')->dayOfWeekIso)->toBe(5)
        ->and(translateCommandWireRecord($weekdays)['data']['next_run_at'])->toBe('2026-07-06T00:00:00+00:00')
        ->and(translateCommandWireRecord($hourly)['data']['next_run_at'])->toBe('2026-07-01T11:00:00+00:00');
});

it('clamps a scheduled task duration to the unsigned integer column range', function () {
    $data = translateCommandWireRecord(scheduledTaskWireRecord(['duration' => 9_000_000_000_000_000]))['data'];

    expect($data['duration_ms'])->toBe(4_294_967_295);
});

it('does not fail on non-scalar identity fields', function () {
    $data = translateCommandWireRecord(scheduledTaskWireRecord(['name' => ['nested'], 'cron' => ['x'], 'timezone' => ['y']]))['data'];

    expect($data['task_hash'])->toBe(sha1(',,'))
        ->and($data['task'])->toBeNull()
        ->and($data['schedule'])->toBeNull()
        ->and($data['next_run_at'])->toBeNull();
});

it('leaves next_run_at null for an invalid cron expression or timezone', function () {
    expect(translateCommandWireRecord(scheduledTaskWireRecord(['cron' => 'not a cron']))['data']['next_run_at'])->toBeNull()
        ->and(translateCommandWireRecord(scheduledTaskWireRecord(['timezone' => 'Mars/Olympus']))['data']['next_run_at'])->toBeNull()
        ->and(translateCommandWireRecord(scheduledTaskWireRecord(['cron' => '']))['data']['next_run_at'])->toBeNull();
});
