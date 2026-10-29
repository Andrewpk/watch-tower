<?php

use App\Watch\NightwatchTranslator;
use Carbon\CarbonImmutable;

const STAGE_WIRE_KEYS = ['bootstrap', 'before_middleware', 'action', 'render', 'after_middleware', 'sending', 'terminating'];

function stageRequestRecord(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'request',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456,
        'trace_id' => '11111111-1111-4111-8111-111111111111',
        'method' => 'GET',
        'route_path' => '/orders/{order}',
        'route_name' => 'orders.show',
        'route_methods' => ['GET', 'HEAD'],
        'route_domain' => '',
        'route_action' => 'App\\Http\\Controllers\\OrderController@show',
        'request_size' => 512,
        'response_size' => 2048,
        'duration' => 10_000,
        'bootstrap' => 1_234,
        'before_middleware' => 250,
        'action' => 5_000,
        'render' => 2_000,
        'after_middleware' => 500,
        'sending' => 16,
        'terminating' => 1_000,
    ], $overrides);
}

function stageRequestData(array $overrides = []): array
{
    return (new NightwatchTranslator)->translate(stageRequestRecord($overrides))['data'];
}

it('translates each stage from microseconds to fractional milliseconds', function () {
    $data = stageRequestData();

    expect($data['bootstrap_ms'])->toBe(1.234)
        ->and($data['before_middleware_ms'])->toBe(0.25)
        ->and($data['action_ms'])->toBe(5.0)
        ->and($data['render_ms'])->toBe(2.0)
        ->and($data['after_middleware_ms'])->toBe(0.5)
        ->and($data['sending_ms'])->toBe(0.016)
        ->and($data['terminating_ms'])->toBe(1.0);
});

it('stores a missing, non-numeric or array stage as null', function (string $stage) {
    $translator = new NightwatchTranslator;
    $key = $stage.'_ms';

    expect($translator->translate(stageRequestRecord([$stage => null]))['data'][$key])->toBeNull()
        ->and($translator->translate(array_diff_key(stageRequestRecord(), [$stage => 1]))['data'][$key])->toBeNull()
        ->and($translator->translate(stageRequestRecord([$stage => 'abc']))['data'][$key])->toBeNull()
        ->and($translator->translate(stageRequestRecord([$stage => ['x']]))['data'][$key])->toBeNull();
})->with(STAGE_WIRE_KEYS);

it('clamps negative stages to zero and holds huge ones at the column maximum', function () {
    $data = stageRequestData(['bootstrap' => -5, 'action' => 1e30, 'render' => 1e400, 'sending' => '2500']);

    expect($data['bootstrap_ms'])->toBe(0.0)
        ->and($data['action_ms'])->toBe(999_999_999.999)
        ->and($data['render_ms'])->toBeNull()
        ->and($data['sending_ms'])->toBe(2.5);
});

it('maps the request and response sizes', function () {
    $data = stageRequestData();

    expect($data['request_size_bytes'])->toBe(512)
        ->and($data['response_size_bytes'])->toBe(2048);
});

it('stores a size outside the unsigned integer range or not numeric as null', function (mixed $value) {
    $data = stageRequestData(['request_size' => $value, 'response_size' => $value]);

    expect($data['request_size_bytes'])->toBeNull()
        ->and($data['response_size_bytes'])->toBeNull();
})->with([
    'negative' => -1,
    'above the column maximum' => 4_294_967_296,
    'text' => 'abc',
    'array' => [['x']],
]);

it('keeps a size of zero and the column maximum', function () {
    $data = stageRequestData(['request_size' => 0, 'response_size' => 4_294_967_295]);

    expect($data['request_size_bytes'])->toBe(0)
        ->and($data['response_size_bytes'])->toBe(4_294_967_295);
});

it('maps the route name, domain and action', function () {
    $data = stageRequestData(['route_domain' => 'api.example.com']);

    expect($data['route_name'])->toBe('orders.show')
        ->and($data['route_domain'])->toBe('api.example.com')
        ->and($data['route_action'])->toBe('App\\Http\\Controllers\\OrderController@show');
});

it('stores an empty or array route detail as null and cuts long ones to 255 characters', function () {
    $empty = stageRequestData(['route_name' => '', 'route_domain' => '', 'route_action' => '']);
    $arrays = stageRequestData(['route_name' => ['x'], 'route_domain' => ['x'], 'route_action' => ['x']]);
    $long = stageRequestData([
        'route_name' => str_repeat('n', 300),
        'route_domain' => str_repeat('d', 300),
        'route_action' => str_repeat('a', 300),
    ]);

    expect([$empty['route_name'], $empty['route_domain'], $empty['route_action']])->toBe([null, null, null])
        ->and([$arrays['route_name'], $arrays['route_domain'], $arrays['route_action']])->toBe([null, null, null])
        ->and($long['route_name'])->toBe(str_repeat('n', 255))
        ->and($long['route_domain'])->toBe(str_repeat('d', 255))
        ->and($long['route_action'])->toBe(str_repeat('a', 255));
});

it('joins the route methods with a pipe', function () {
    expect(stageRequestData(['route_methods' => ['GET', 'HEAD']])['route_methods'])->toBe('GET|HEAD')
        ->and(stageRequestData(['route_methods' => ['post']])['route_methods'])->toBe('POST')
        ->and(stageRequestData(['route_methods' => []])['route_methods'])->toBeNull()
        ->and(stageRequestData(['route_methods' => null])['route_methods'])->toBeNull()
        ->and(stageRequestData(['route_methods' => [1, ['x'], 'POST']])['route_methods'])->toBe('POST')
        ->and(stageRequestData(['route_methods' => 'GET'])['route_methods'])->toBe('GET');
});

it('cuts the route methods to 100 characters', function () {
    $methods = array_fill(0, 40, 'PATCH');

    expect(stageRequestData(['route_methods' => $methods])['route_methods'])->toHaveLength(100);
});

it('translates the three command stages and no render stage', function () {
    $data = (new NightwatchTranslator)->translate([
        'v' => 1,
        't' => 'command',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp(),
        'trace_id' => '22222222-2222-4222-8222-222222222222',
        'name' => 'app:sync-users',
        'command' => 'app:sync-users',
        'exit_code' => 0,
        'duration' => 9_000,
        'bootstrap' => 1_500,
        'action' => 7_000,
        'terminating' => 500,
        'render' => 900,
    ])['data'];

    expect($data['bootstrap_ms'])->toBe(1.5)
        ->and($data['action_ms'])->toBe(7.0)
        ->and($data['terminating_ms'])->toBe(0.5)
        ->and($data)->not->toHaveKey('render_ms');
});

it('stores missing command stages as null', function () {
    $data = (new NightwatchTranslator)->translate([
        'v' => 1,
        't' => 'command',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp(),
        'trace_id' => '22222222-2222-4222-8222-222222222222',
        'name' => 'app:sync-users',
        'command' => 'app:sync-users',
        'exit_code' => 0,
        'duration' => 9_000,
        'action' => 'abc',
    ])['data'];

    expect($data['bootstrap_ms'])->toBeNull()
        ->and($data['action_ms'])->toBeNull()
        ->and($data['terminating_ms'])->toBeNull();
});
