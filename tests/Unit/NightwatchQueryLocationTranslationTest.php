<?php

use App\Watch\NightwatchTranslator;
use Carbon\CarbonImmutable;

function locationQueryRecord(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'query',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456,
        '_group' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'trace_id' => '11111111-2222-3333-4444-555555555555',
        'sql' => 'select * from `orders` where `id` = ?',
        'duration' => 1_250,
        'connection' => 'mysql',
        'file' => 'app/Http/Controllers/OrderController.php',
        'line' => 42,
        'connection_type' => 'read',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function locationQueryData(array $overrides = []): array
{
    return (new NightwatchTranslator)->translate(locationQueryRecord($overrides))['data'];
}

/**
 * Minimal wire records of the four types that carry lazy load and hydrated model counts.
 *
 * @return array<string, mixed>
 */
function locationCountRecord(string $type): array
{
    $base = [
        'v' => 1,
        't' => $type,
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.5,
        'trace_id' => '11111111-1111-4111-8111-111111111111',
        'duration' => 125_000,
    ];

    return match ($type) {
        'request' => $base + ['method' => 'GET', 'route_path' => '/orders/{order}', 'status_code' => 200],
        'command' => $base + ['name' => 'app:sync-users', 'command' => 'app:sync-users', 'exit_code' => 0],
        'job-attempt' => $base + ['attempt_id' => '33333333-3333-4333-8333-333333333331', 'job_id' => '22222222-2222-4222-8222-222222222222', 'attempt' => 1, 'name' => 'App\\Jobs\\SendInvoice', 'status' => 'processed'],
        'scheduled-task' => $base + ['name' => 'php artisan app:prune', 'cron' => '0 * * * *', 'timezone' => 'UTC', 'repeat_seconds' => 0, 'status' => 'processed'],
    };
}

it('maps the query location and connection type', function () {
    expect(locationQueryData())->toMatchArray([
        'file' => 'app/Http/Controllers/OrderController.php',
        'line' => 42,
        'connection_type' => 'read',
    ]);
});

it('keeps each known connection type', function (string $type) {
    expect(locationQueryData(['connection_type' => $type])['connection_type'])->toBe($type);
})->with(['read', 'write', 'direct']);

it('stores an empty or invalid connection type as null', function (mixed $type) {
    expect(locationQueryData(['connection_type' => $type])['connection_type'])->toBeNull();
})->with([
    'empty string' => [''],
    'null' => [null],
    'array' => [['read']],
]);

it('stores a query without a location as nulls', function () {
    $data = locationQueryData(['file' => null, 'line' => null, 'connection_type' => null]);

    expect($data)->toMatchArray(['file' => null, 'line' => null, 'connection_type' => null]);
});

it('stores a query from an older sensor without the location keys as nulls', function () {
    $record = locationQueryRecord();
    unset($record['file'], $record['line'], $record['connection_type']);

    expect((new NightwatchTranslator)->translate($record)['data'])
        ->toMatchArray(['file' => null, 'line' => null, 'connection_type' => null]);
});

it('stores no line for an unknown line, a missing file or invalid input', function (mixed $file, mixed $line) {
    expect(locationQueryData(['file' => $file, 'line' => $line])['line'])->toBeNull();
})->with([
    'the sensor sends 0 when unknown' => ['app/Models/User.php', 0],
    'missing file' => [null, 42],
    'empty file' => ['', 42],
    'negative line' => ['app/Models/User.php', -5],
    'line above the column maximum' => ['app/Models/User.php', 4_294_967_296],
    'text line' => ['app/Models/User.php', 'abc'],
    'array line' => ['app/Models/User.php', [42]],
]);

it('accepts a numeric string line', function () {
    expect(locationQueryData(['line' => '17'])['line'])->toBe(17);
});

it('cuts a long file path to the column length', function () {
    expect(locationQueryData(['file' => str_repeat('a', 300)])['file'])->toBe(str_repeat('a', 255));
});

it('stores an array file as null together with its line', function () {
    $data = locationQueryData(['file' => ['app/Models/User.php'], 'line' => 42]);

    expect($data['file'])->toBeNull()
        ->and($data['line'])->toBeNull();
});

it('maps the lazy load and hydrated model counts', function (string $type) {
    $data = (new NightwatchTranslator)->translate(locationCountRecord($type) + ['lazy_loads' => 7, 'hydrated_models' => 1_234])['data'];

    expect($data['lazy_loads'])->toBe(7)
        ->and($data['hydrated_models'])->toBe(1_234);
})->with(['request', 'command', 'job-attempt', 'scheduled-task']);

it('stores missing counts as null rather than zero', function (string $type) {
    $data = (new NightwatchTranslator)->translate(locationCountRecord($type))['data'];

    expect($data)->toHaveKeys(['lazy_loads', 'hydrated_models'])
        ->and($data['lazy_loads'])->toBeNull()
        ->and($data['hydrated_models'])->toBeNull();
})->with(['request', 'command', 'job-attempt', 'scheduled-task']);

it('clamps or drops invalid counts', function (string $type, mixed $value, ?int $expected) {
    $data = (new NightwatchTranslator)->translate(locationCountRecord($type) + ['lazy_loads' => $value, 'hydrated_models' => $value])['data'];

    expect($data['lazy_loads'])->toBe($expected)
        ->and($data['hydrated_models'])->toBe($expected);
})->with(['request', 'command', 'job-attempt', 'scheduled-task'])->with([
    'zero' => [0, 0],
    'numeric string' => ['12', 12],
    'text' => ['abc', null],
    'array' => [[1], null],
    'null' => [null, null],
    'negative is clamped to zero' => [-3, 0],
    'huge is clamped to the column maximum' => ['1e30', 4_294_967_295],
]);
