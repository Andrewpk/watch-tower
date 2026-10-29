<?php

use App\Watch\NightwatchTranslator;
use Carbon\CarbonImmutable;

function nightwatchRequestTranslationRecord(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'request',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456,
        'deploy' => 'v1.4.2',
        'server' => 'web-1',
        'trace_id' => '11111111-1111-4111-8111-111111111111',
        'user' => '42',
        'method' => 'POST',
        'url' => 'https://example.com/orders/7',
        'route_path' => '/orders/{order}',
        'status_code' => 201,
        'duration' => 125_000,
        'queries' => 3,
        'peak_memory_usage' => 4_194_304,
        'ip' => '203.0.113.4',
    ], $overrides);
}

it('translates a request record with the deploy id as its release and no environment', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchRequestTranslationRecord());

    expect($event)->toBe([
        'type' => 'request',
        'id' => '11111111-1111-4111-8111-111111111111',
        'data' => [
            'request_id' => '11111111-1111-4111-8111-111111111111',
            'method' => 'POST',
            'uri' => '/orders/{order}',
            'status_code' => 201,
            'user_id' => '42',
            'duration_ms' => 125,
            'db_queries_count' => 3,
            'memory_used_kb' => 4096,
            'memory_peak_kb' => 4096,
            'release' => 'v1.4.2',
            'hostname' => 'web-1',
            'ip_address' => '203.0.113.4',
            'headers' => null,
            'request_data' => null,
            'occurred_at' => '2026-07-01T10:15:00+00:00',
        ],
    ]);
});

it('keeps an empty uri for an unmatched route and falls back to the url or a slash', function () {
    $translator = new NightwatchTranslator;

    expect($translator->translate(nightwatchRequestTranslationRecord(['route_path' => '']))['data']['uri'])->toBe('')
        ->and($translator->translate(nightwatchRequestTranslationRecord(['route_path' => null]))['data']['uri'])->toBe('https://example.com/orders/7')
        ->and($translator->translate(nightwatchRequestTranslationRecord(['route_path' => null, 'url' => null]))['data']['uri'])->toBe('/');
});

it('cuts request strings to their column lengths and uppercases the method', function () {
    $data = (new NightwatchTranslator)->translate(nightwatchRequestTranslationRecord([
        'deploy' => str_repeat('d', 300),
        'method' => 'post',
        'ip' => str_repeat('1', 60),
        'server' => str_repeat('s', 300),
        'user' => str_repeat('u', 300),
        'trace_id' => str_repeat('t', 300),
        'route_path' => str_repeat('/', 3000),
        'status_code' => 70_000,
    ]))['data'];

    expect($data['release'])->toBe(str_repeat('d', 255))
        ->and($data['method'])->toBe('POST')
        ->and($data['ip_address'])->toBe(str_repeat('1', 45))
        ->and($data['hostname'])->toBe(str_repeat('s', 255))
        ->and($data['user_id'])->toBe(str_repeat('u', 255))
        ->and($data['request_id'])->toBe(str_repeat('t', 255))
        ->and(mb_strlen($data['uri']))->toBe(2048)
        ->and($data['status_code'])->toBeNull();

    expect((new NightwatchTranslator)->translate(nightwatchRequestTranslationRecord(['method' => str_repeat('m', 30)]))['data']['method'])
        ->toBe(str_repeat('M', 10));
});

it('does not throw on array values in a request record', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchRequestTranslationRecord([
        'trace_id' => ['x'],
        'method' => ['x'],
        'route_path' => ['x'],
        'url' => ['x'],
        'server' => ['x'],
        'user' => ['x'],
        'deploy' => ['x'],
        'ip' => ['x'],
        'status_code' => ['x'],
    ]));

    expect($event['id'])->toBe('')
        ->and($event['data'])->toMatchArray([
            'request_id' => '',
            'method' => 'GET',
            'uri' => '',
            'status_code' => null,
            'user_id' => null,
            'release' => null,
            'hostname' => null,
            'ip_address' => null,
        ]);
});

it('translates a log record without an environment and cuts its strings', function () {
    $translator = new NightwatchTranslator;
    $base = [
        't' => 'log',
        'trace_id' => '11111111-1111-4111-8111-111111111111',
        'deploy' => 'v1.4.2',
        'level' => 'info',
        'message' => 'Order shipped',
        'execution_source' => 'request',
        'execution_preview' => 'GET /home',
        'user' => 'amal',
    ];

    $data = $translator->translate($base)['data'];

    expect($data)->not->toHaveKey('environment')
        ->and($data)->not->toHaveKey('release')
        ->and($data['level'])->toBe('info')
        ->and($data['message'])->toBe('Order shipped');

    $cut = $translator->translate(array_merge($base, [
        'level' => str_repeat('l', 40),
        'execution_source' => str_repeat('s', 80),
        'execution_preview' => str_repeat('p', 400),
        'user' => str_repeat('u', 400),
    ]))['data'];

    expect($cut['level'])->toBe(str_repeat('l', 20))
        ->and($cut['source_type'])->toBe(str_repeat('s', 50))
        ->and($cut['source_label'])->toBe(str_repeat('p', 255))
        ->and($cut['user_name'])->toBe(str_repeat('u', 255));

    $arrays = $translator->translate(array_merge($base, [
        'trace_id' => ['x'],
        'level' => ['x'],
        'message' => ['x'],
        'execution_source' => ['x'],
        'execution_preview' => ['x'],
        'user' => ['x'],
    ]));

    expect($arrays['id'])->toBe('')
        ->and($arrays['data'])->toMatchArray([
            'request_id' => null,
            'level' => 'info',
            'message' => '',
            'source_type' => null,
            'source_label' => null,
            'user_name' => null,
        ]);
});

it('translates a cache event record without an environment and cuts its strings', function () {
    $translator = new NightwatchTranslator;
    $base = [
        't' => 'cache-event',
        '_group' => 'group-hash',
        'trace_id' => '11111111-1111-4111-8111-111111111111',
        'deploy' => 'v1.4.2',
        'store' => 'redis',
        'key' => 'users:42',
        'type' => 'hit',
        'duration' => 3_223,
    ];

    $event = $translator->translate($base);

    expect($event['id'])->toBe('group-hash')
        ->and($event['data'])->not->toHaveKey('environment')
        ->and($event['data'])->not->toHaveKey('release')
        ->and($event['data'])->toMatchArray(['key' => 'users:42', 'store' => 'redis', 'operation' => 'hit', 'duration_ms' => 3]);

    $cut = $translator->translate(array_merge($base, [
        'key' => str_repeat('k', 400),
        'store' => str_repeat('s', 80),
        'type' => str_repeat('o', 40),
    ]))['data'];

    expect($cut['key'])->toBe(str_repeat('k', 255))
        ->and($cut['store'])->toBe(str_repeat('s', 50))
        ->and($cut['operation'])->toBe(str_repeat('o', 20));

    $arrays = $translator->translate(array_merge($base, [
        '_group' => ['x'],
        'trace_id' => ['x'],
        'key' => ['x'],
        'store' => ['x'],
        'type' => ['x'],
    ]));

    expect($arrays['id'])->toBe('')
        ->and($arrays['data'])->toMatchArray([
            'request_id' => null,
            'key' => '',
            'store' => null,
            'operation' => 'unknown',
        ]);
});

it('does not throw on array values in job, command, mail and other records', function (string $type) {
    $record = [
        't' => $type,
        '_group' => ['x'],
        'job_id' => ['x'],
        'attempt_id' => ['x'],
        'trace_id' => ['x'],
        'deploy' => ['x'],
        'user' => ['x'],
        'exception_preview' => ['x'],
        'execution_source' => ['x'],
    ];

    $event = (new NightwatchTranslator)->translate($record);

    expect($event['id'])->toBe('')
        ->and($event['data'])->not->toHaveKey('environment');
})->with(['queued-job', 'job-attempt', 'command', 'scheduled-task', 'mail', 'notification', 'outgoing-request']);
