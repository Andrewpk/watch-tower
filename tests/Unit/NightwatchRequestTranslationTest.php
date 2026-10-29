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
            'route_name' => null,
            'route_methods' => null,
            'route_domain' => null,
            'route_action' => null,
            'request_size_bytes' => null,
            'response_size_bytes' => null,
            'bootstrap_ms' => null,
            'before_middleware_ms' => null,
            'action_ms' => null,
            'render_ms' => null,
            'after_middleware_ms' => null,
            'sending_ms' => null,
            'terminating_ms' => null,
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

    if ($type === 'job-attempt') {
        expect($event['data']['exception'])->toBeNull();
    }
})->with(['queued-job', 'job-attempt', 'command', 'scheduled-task', 'mail', 'notification', 'outgoing-request']);

it('cuts multibyte strings by characters and keeps them valid UTF-8', function () {
    $data = (new NightwatchTranslator)->translate(nightwatchRequestTranslationRecord([
        'deploy' => str_repeat('é', 300),
        'server' => str_repeat('é', 300),
    ]))['data'];

    expect(mb_strlen($data['release']))->toBe(255)
        ->and(mb_check_encoding($data['release'], 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($data['hostname']))->toBe(255)
        ->and(mb_check_encoding($data['hostname'], 'UTF-8'))->toBeTrue();
});

it('translates a job attempt with an array exception preview and no exception', function () {
    $event = (new NightwatchTranslator)->translate([
        't' => 'job-attempt',
        'attempt_id' => '33333333-3333-4333-8333-333333333333',
        'job_id' => '22222222-2222-4222-8222-222222222222',
        'status' => 'failed',
        'exception_preview' => ['x'],
    ]);

    expect($event['data']['exception'])->toBeNull();
});

it('turns an unusable timestamp into null instead of throwing', function (mixed $timestamp) {
    $translator = new NightwatchTranslator;

    expect($translator->translate(nightwatchRequestTranslationRecord(['timestamp' => $timestamp]))['data']['occurred_at'])->toBeNull();

    $attempt = $translator->translate([
        't' => 'job-attempt',
        'attempt_id' => 'attempt-1',
        'job_id' => 'job-1',
        'status' => 'failed',
        'timestamp' => $timestamp,
        'duration' => 1_000_000,
    ])['data'];

    expect($attempt['started_at'])->toBeNull()
        ->and($attempt['failed_at'])->toBeNull();
})->with([
    'microsecond timestamp' => 1_730_000_000_000_000,
    'float microsecond timestamp' => 1.73e15,
    'exponent string' => '1e15',
    'not a number' => 'soon',
    'array' => [['x']],
    'zero' => 0,
    'negative' => -5,
    'infinity' => INF,
]);

it('adds a duration to a job attempt timestamp only when both are usable', function () {
    $translator = new NightwatchTranslator;
    $at = CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp();
    $base = ['t' => 'job-attempt', 'attempt_id' => 'a', 'job_id' => 'j', 'status' => 'processed', 'timestamp' => $at];

    expect($translator->translate($base + ['duration' => 2_000_000])['data']['completed_at'])->toBe('2026-07-01T10:15:02+00:00')
        ->and($translator->translate($base + ['duration' => 'abc'])['data']['completed_at'])->toBe('2026-07-01T10:15:00+00:00')
        ->and($translator->translate($base + ['duration' => 1e30])['data']['completed_at'])->toBeNull();
});

it('clamps negative and oversized numbers to the unsigned column range', function () {
    $translator = new NightwatchTranslator;

    $request = $translator->translate(nightwatchRequestTranslationRecord([
        'queries' => -5,
        'duration' => -1_000,
        'peak_memory_usage' => -4_096,
    ]))['data'];

    expect($request['db_queries_count'])->toBe(0)
        ->and($request['duration_ms'])->toBe(0)
        ->and($request['memory_used_kb'])->toBe(0)
        ->and($request['memory_peak_kb'])->toBe(0);

    $huge = $translator->translate(nightwatchRequestTranslationRecord([
        'queries' => 1e30,
        'duration' => 1e30,
        'peak_memory_usage' => 1e30,
    ]))['data'];

    expect($huge['db_queries_count'])->toBe(4_294_967_295)
        ->and($huge['duration_ms'])->toBe(4_294_967_295)
        ->and($huge['memory_used_kb'])->toBe(4_294_967_295);

    $attempt = $translator->translate(['t' => 'job-attempt', 'attempt' => -2, 'duration' => -500])['data'];
    $mail = $translator->translate(['t' => 'mail', 'to' => -1, 'cc' => -2, 'bcc' => 3, 'attachments' => -4, 'duration' => -500])['data'];
    $notification = $translator->translate(['t' => 'notification', 'duration' => -500])['data'];
    $outgoing = $translator->translate(['t' => 'outgoing-request', 'duration' => -500])['data'];
    $cache = $translator->translate(['t' => 'cache-event', 'type' => 'hit', 'duration' => -500])['data'];

    expect($attempt['attempts'])->toBe(0)
        ->and($attempt['duration_ms'])->toBe(0)
        ->and($mail['recipients_count'])->toBe(3)
        ->and($mail['attachments_count'])->toBe(0)
        ->and($mail['duration_ms'])->toBe(0)
        ->and($notification['duration_ms'])->toBe(0)
        ->and($outgoing['duration_ms'])->toBe(0)
        ->and($cache['duration_ms'])->toBe(0);

    expect($translator->translate(['t' => 'job-attempt', 'attempt' => 'abc'])['data']['attempts'])->toBe(1)
        ->and($translator->translate(['t' => 'job-attempt'])['data']['attempts'])->toBe(1)
        ->and($translator->translate(['t' => 'mail', 'to' => 4_294_967_295, 'cc' => 4_294_967_295])['data']['recipients_count'])->toBe(4_294_967_295);
});

it('maps cache write and delete failures to their operation with succeeded false', function (string $type, string $operation, bool $succeeded) {
    $data = (new NightwatchTranslator)->translate(['t' => 'cache-event', 'type' => $type, 'key' => 'users:42'])['data'];

    expect($data['operation'])->toBe($operation)
        ->and($data['succeeded'])->toBe($succeeded);
})->with([
    'write' => ['write', 'write', true],
    'write failure' => ['write-failure', 'write', false],
    'delete' => ['delete', 'delete', true],
    'delete failure' => ['delete-failure', 'delete', false],
    'legacy write failed' => ['write-failed', 'write', false],
    'legacy forget' => ['forget', 'delete', true],
    'legacy forget failed' => ['forget-failed', 'delete', false],
    'hit' => ['hit', 'hit', true],
    'miss' => ['miss', 'miss', true],
]);
