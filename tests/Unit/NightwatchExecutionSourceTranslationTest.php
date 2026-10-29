<?php

use App\Watch\NightwatchTranslator;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function executionSourceRecord(string $type, array $overrides = []): array
{
    $base = match ($type) {
        'command' => ['name' => 'app:sync-users', 'command' => 'app:sync-users', 'exit_code' => 0, 'duration' => 1_000],
        'job-attempt' => ['job_id' => 'job-1', 'attempt_id' => 'attempt-1', 'attempt' => 1, 'name' => 'App\\Jobs\\SendInvoice', 'status' => 'processed', 'duration' => 1_000],
        'scheduled-task' => ['name' => 'php artisan app:prune', 'cron' => '0 * * * *', 'timezone' => 'UTC', 'repeat_seconds' => 0, 'status' => 'processed', 'duration' => 1_000],
        'query' => ['_group' => 'group-1', 'sql' => 'select 1', 'duration' => 1_000, 'connection' => 'mysql'],
        'cache-event' => ['_group' => 'group-1', 'key' => 'users', 'store' => 'redis', 'type' => 'hit', 'duration' => 1_000],
        'exception' => ['_group' => 'group-1', 'class' => 'RuntimeException', 'message' => 'Broke', 'file' => 'app/Example.php', 'line' => 1, 'trace' => '[]'],
    };

    return array_merge([
        'v' => 1,
        't' => $type,
        'timestamp' => 1_782_900_000.5,
        'trace_id' => '11111111-2222-3333-4444-555555555555',
    ], $base, $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function translateExecutionSource(string $type, array $overrides = []): array
{
    return (new NightwatchTranslator)->translate(executionSourceRecord($type, $overrides))['data'];
}

it('maps the peak memory of non-request records to kilobytes', function (string $type) {
    expect(translateExecutionSource($type, ['peak_memory_usage' => 20_971_520])['memory_peak_kb'])->toBe(20_480)
        ->and(translateExecutionSource($type, ['peak_memory_usage' => 1_536])['memory_peak_kb'])->toBe(2);
})->with(['command', 'job-attempt', 'scheduled-task']);

it('stores no peak memory when it is missing or not numeric', function (string $type, mixed $value) {
    $overrides = $value === 'missing' ? [] : ['peak_memory_usage' => $value];

    expect(translateExecutionSource($type, $overrides)['memory_peak_kb'])->toBeNull();
})->with(['command', 'job-attempt', 'scheduled-task'])->with(['missing', 'abc', [['x']], null]);

it('clamps a negative peak memory to zero', function (string $type) {
    expect(translateExecutionSource($type, ['peak_memory_usage' => -4_096])['memory_peak_kb'])->toBe(0);
})->with(['command', 'job-attempt', 'scheduled-task']);

it('maps the scheduled task options', function (int $seconds, ?int $expected) {
    $data = translateExecutionSource('scheduled-task', [
        'timezone' => 'Europe/Amsterdam',
        'repeat_seconds' => $seconds,
        'without_overlapping' => true,
        'on_one_server' => false,
        'run_in_background' => true,
        'even_in_maintenance_mode' => false,
    ]);

    expect($data['timezone'])->toBe('Europe/Amsterdam')
        ->and($data['repeat_seconds'])->toBe($expected)
        ->and($data['without_overlapping'])->toBeTrue()
        ->and($data['on_one_server'])->toBeFalse()
        ->and($data['run_in_background'])->toBeTrue()
        ->and($data['even_in_maintenance_mode'])->toBeFalse();
})->with([
    'no repeat' => [0, 0],
    'every 30 seconds' => [30, 30],
    'negative' => [-1, null],
    'above the column maximum' => [70_000, null],
]);

it('treats a flag that is not a real boolean as unknown', function (mixed $value) {
    $data = translateExecutionSource('scheduled-task', [
        'without_overlapping' => $value,
        'on_one_server' => $value,
        'run_in_background' => $value,
        'even_in_maintenance_mode' => $value,
    ]);

    expect($data['without_overlapping'])->toBeNull()
        ->and($data['on_one_server'])->toBeNull()
        ->and($data['run_in_background'])->toBeNull()
        ->and($data['even_in_maintenance_mode'])->toBeNull();
})->with(['yes', 1, '1', [[]], null]);

it('leaves the options null when the record carries none', function () {
    $record = executionSourceRecord('scheduled-task');
    unset($record['timezone'], $record['repeat_seconds']);

    $data = (new NightwatchTranslator)->translate($record)['data'];

    expect($data['timezone'])->toBeNull()
        ->and($data['repeat_seconds'])->toBeNull()
        ->and($data['without_overlapping'])->toBeNull();
});

it('cuts the timezone to the column length', function () {
    expect(translateExecutionSource('scheduled-task', ['timezone' => str_repeat('a', 100)])['timezone'])->toHaveLength(64);
});

it('maps the execution source of child records', function (string $type) {
    $data = translateExecutionSource($type, ['execution_source' => 'job', 'execution_preview' => 'App\\Jobs\\SendInvoice']);

    expect($data['source_type'])->toBe('job')
        ->and($data['source_label'])->toBe('App\\Jobs\\SendInvoice')
        ->and($data)->not->toHaveKeys(['source_id', 'execution_stage']);
})->with(['query', 'cache-event', 'exception']);

it('leaves the source null when the record has none', function (string $type) {
    $data = translateExecutionSource($type);

    expect($data['source_type'])->toBeNull()
        ->and($data['source_label'])->toBeNull();
})->with(['query', 'cache-event', 'exception']);

it('cuts the source to the column lengths and drops arrays', function (string $type) {
    $long = translateExecutionSource($type, ['execution_source' => str_repeat('s', 80), 'execution_preview' => str_repeat('p', 300)]);
    $arrays = translateExecutionSource($type, ['execution_source' => ['x'], 'execution_preview' => ['y']]);

    expect($long['source_type'])->toHaveLength(50)
        ->and($long['source_label'])->toHaveLength(255)
        ->and($arrays['source_type'])->toBeNull()
        ->and($arrays['source_label'])->toBeNull();
})->with(['query', 'cache-event', 'exception']);
