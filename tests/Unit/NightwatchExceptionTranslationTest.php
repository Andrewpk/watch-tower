<?php

use App\Watch\NightwatchTranslator;
use Carbon\CarbonImmutable;

function nightwatchExceptionV3Record(array $overrides = []): array
{
    return array_merge([
        'v' => 3,
        't' => 'exception',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456,
        'deploy' => 'v1.4.2',
        'server' => 'web-1',
        '_group' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'trace_id' => '11111111-2222-3333-4444-555555555555',
        'user' => 'user-1',
        'class' => 'RuntimeException',
        'file' => 'app/Example.php',
        'message' => 'Something broke',
        'line' => 12,
        'trace' => json_encode([['file' => 'app/Example.php', 'line' => 12, 'function' => 'handle']]),
        'handled' => true,
        'php_version' => '8.4.1',
        'laravel_version' => '12.35.1',
    ], $overrides);
}

function translateNightwatchExceptionV3(array $overrides = []): array
{
    return (new NightwatchTranslator)->translate(nightwatchExceptionV3Record($overrides));
}

it('translates a v3 exception record', function () {
    expect(translateNightwatchExceptionV3())->toBe([
        'type' => 'exception',
        'id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'data' => [
            'request_id' => '11111111-2222-3333-4444-555555555555',
            'class' => 'RuntimeException',
            'message' => 'Something broke',
            'file' => 'app/Example.php',
            'line' => 12,
            'trace' => [['file' => 'app/Example.php', 'line' => 12, 'function' => 'handle']],
            'is_handled' => true,
            'language_version' => '8.4.1',
            'framework_version' => '12.35.1',
            'hostname' => 'web-1',
            'user_id' => 'user-1',
            'release' => 'v1.4.2',
            'occurred_at' => '2026-07-01T10:15:00+00:00',
        ],
    ]);
});

it('does not map the deploy id to an environment', function () {
    expect(translateNightwatchExceptionV3()['data'])->not->toHaveKey('environment');
});

it('treats a missing or false handled flag as unhandled', function (array $overrides) {
    expect(translateNightwatchExceptionV3($overrides)['data']['is_handled'])->toBeFalse();
})->with([
    'false' => [['handled' => false]],
    'missing' => [['handled' => null]],
    'string' => [['handled' => 'true']],
]);

it('translates a fatal error record with an empty trace', function () {
    $data = translateNightwatchExceptionV3(['trace' => '', 'handled' => false])['data'];

    expect($data['trace'])->toBe([])
        ->and($data['is_handled'])->toBeFalse()
        ->and($data['language_version'])->toBe('8.4.1');
});

it('cuts long values to the column lengths', function () {
    $data = translateNightwatchExceptionV3([
        'class' => str_repeat('C', 300),
        'file' => str_repeat('f', 300),
        'php_version' => str_repeat('8', 60),
        'laravel_version' => str_repeat('1', 60),
        'deploy' => str_repeat('d', 300),
        'server' => str_repeat('s', 300),
    ])['data'];

    expect(mb_strlen($data['class']))->toBe(255)
        ->and(mb_strlen($data['file']))->toBe(255)
        ->and(mb_strlen($data['language_version']))->toBe(50)
        ->and(mb_strlen($data['framework_version']))->toBe(50)
        ->and(mb_strlen($data['release']))->toBe(255)
        ->and(mb_strlen($data['hostname']))->toBe(255);
});

it('cuts the anonymous class suffix off the class name', function () {
    $data = translateNightwatchExceptionV3(['class' => "RuntimeException@anonymous\0/srv/app/Example.php:12\$0"])['data'];

    expect($data['class'])->toBe('RuntimeException@anonymous');
});

it('does not throw on array values', function () {
    $data = translateNightwatchExceptionV3([
        'class' => ['x'],
        'file' => ['x'],
        'message' => ['x'],
        'trace_id' => ['x'],
        'server' => ['x'],
        'php_version' => ['x'],
        'laravel_version' => ['x'],
        'user' => ['x'],
        'deploy' => ['x'],
        '_group' => ['x'],
    ]);

    expect($data['id'])->toBe('')
        ->and($data['data']['class'])->toBe('Exception')
        ->and($data['data']['message'])->toBe('')
        ->and($data['data']['file'])->toBe('')
        ->and($data['data']['request_id'])->toBeNull()
        ->and($data['data']['hostname'])->toBeNull()
        ->and($data['data']['language_version'])->toBeNull()
        ->and($data['data']['framework_version'])->toBeNull()
        ->and($data['data']['user_id'])->toBeNull()
        ->and($data['data']['release'])->toBeNull();
});
