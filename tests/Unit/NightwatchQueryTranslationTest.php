<?php

use App\Watch\NightwatchTranslator;
use Carbon\CarbonImmutable;

function nightwatchQueryRecord(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'query',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456,
        '_group' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'trace_id' => '11111111-2222-3333-4444-555555555555',
        'sql' => 'select * from `users` where `id` = ?',
        'duration' => 1_250,
        'connection' => 'mysql',
    ], $overrides);
}

function translateNightwatchQuery(array $overrides = []): array
{
    return (new NightwatchTranslator)->translate(nightwatchQueryRecord($overrides));
}

it('translates a query record', function () {
    expect(translateNightwatchQuery())->toBe([
        'type' => 'query',
        'id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'data' => [
            'request_id' => '11111111-2222-3333-4444-555555555555',
            'sql' => 'select * from `users` where `id` = ?',
            'connection' => 'mysql',
            'duration_ms' => 1.25,
            'occurred_at' => '2026-07-01T10:15:00+00:00',
        ],
    ]);
});

it('keeps sub-millisecond durations', function (int|string $micros, float $expected) {
    expect(translateNightwatchQuery(['duration' => $micros])['data']['duration_ms'])->toBe($expected);
})->with([
    'one and a quarter ms' => [1_250, 1.25],
    'zero point four ms' => [400, 0.4],
    'one microsecond' => [1, 0.001],
    'numeric string' => ['2500', 2.5],
    'zero' => [0, 0.0],
    'negative is clamped' => [-5_000, 0.0],
    'huge value is clamped to the column maximum' => [5_000_000_000_000_000_000, 999_999_999.999],
    'huge numeric string is clamped' => ['1e30', 999_999_999.999],
]);

it('stores a missing or non-numeric duration as null', function (mixed $duration) {
    expect(translateNightwatchQuery(['duration' => $duration])['data']['duration_ms'])->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'text' => ['fast'],
    'array' => [[1_000]],
    'overflowing numeric string' => ['1e400'],
    'infinity' => [INF],
    'negative infinity' => [-INF],
    'not a number' => [NAN],
]);

it('treats a missing duration key as null', function () {
    $record = nightwatchQueryRecord();
    unset($record['duration']);

    expect((new NightwatchTranslator)->translate($record)['data']['duration_ms'])->toBeNull();
});

it('cuts sql on a character boundary within the text column size', function () {
    $sql = str_repeat('é', 40_000);

    $translated = translateNightwatchQuery(['sql' => $sql])['data']['sql'];

    expect(strlen($translated))->toBeLessThanOrEqual(65_535)
        ->and(mb_check_encoding($translated, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($translated))->toBe(32_767);
});

it('does not throw on array values', function () {
    $translated = translateNightwatchQuery([
        '_group' => ['x'],
        'sql' => ['select 1'],
        'trace_id' => ['trace'],
        'connection' => ['mysql'],
    ]);

    expect($translated['id'])->toBe('')
        ->and($translated['data']['sql'])->toBe('')
        ->and($translated['data']['request_id'])->toBeNull()
        ->and($translated['data']['connection'])->toBeNull();
});

it('cuts a long connection name and trace id', function () {
    $data = translateNightwatchQuery([
        'connection' => str_repeat('c', 150),
        'trace_id' => str_repeat('t', 300),
    ])['data'];

    expect(strlen($data['connection']))->toBe(100)
        ->and(strlen($data['request_id']))->toBe(255);
});
