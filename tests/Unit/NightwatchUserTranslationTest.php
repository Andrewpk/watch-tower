<?php

use App\Watch\NightwatchTranslator;
use Carbon\CarbonImmutable;

function nightwatchUserRecord(array $overrides = []): array
{
    return [
        'v' => 1,
        't' => 'user',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456,
        'id' => '42',
        'name' => 'Jane Example',
        'username' => 'jane@example.com',
        ...$overrides,
    ];
}

it('translates a user record into an end user envelope', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchUserRecord());

    expect($event)->toBe([
        'type' => 'user',
        'id' => '42',
        'data' => [
            'user_id' => '42',
            'name' => 'Jane Example',
            'email' => 'jane@example.com',
            'occurred_at' => '2026-07-01T10:15:00+00:00',
        ],
    ]);
});

it('treats empty name and username as missing', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchUserRecord(['name' => '', 'username' => '']));

    expect($event['data']['name'])->toBeNull()
        ->and($event['data']['email'])->toBeNull();
});

it('returns null for a user record without an id', function (mixed $id) {
    expect((new NightwatchTranslator)->translate(nightwatchUserRecord(['id' => $id])))->toBeNull();
})->with(['empty string' => '', 'null' => null, 'array' => [['nested']]]);

it('accepts a numeric user id', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchUserRecord(['id' => 7]));

    expect($event['id'])->toBe('7')
        ->and($event['data']['user_id'])->toBe('7');
});

it('cuts long values to the column length', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchUserRecord([
        'id' => str_repeat('i', 300),
        'name' => str_repeat('n', 300),
        'username' => str_repeat('e', 300),
    ]));

    expect(mb_strlen($event['id']))->toBe(255)
        ->and(mb_strlen($event['data']['user_id']))->toBe(255)
        ->and(mb_strlen($event['data']['name']))->toBe(255)
        ->and(mb_strlen($event['data']['email']))->toBe(255);
});

it('does not fail on non-scalar names', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchUserRecord(['name' => ['Jane'], 'username' => ['jane@example.com']]));

    expect($event['data']['name'])->toBeNull()
        ->and($event['data']['email'])->toBeNull();
});
