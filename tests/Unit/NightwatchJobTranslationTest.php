<?php

use App\Watch\NightwatchTranslator;
use Carbon\CarbonImmutable;

function nightwatchJobTranslationAttempt(array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'job-attempt',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456,
        'deploy' => 'v1.4.2',
        'server' => 'worker-1',
        'trace_id' => '11111111-1111-4111-8111-111111111111',
        'user' => '42',
        'job_id' => '22222222-2222-4222-8222-222222222222',
        'attempt_id' => '33333333-3333-4333-8333-333333333333',
        'attempt' => 1,
        'name' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'status' => 'processed',
        'duration' => 1_250_000,
        'exception_preview' => '',
    ], $overrides);
}

it('translates a processed job attempt', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchJobTranslationAttempt());

    expect($event['type'])->toBe('job-attempt')
        ->and($event['id'])->toBe('33333333-3333-4333-8333-333333333333')
        ->and($event['data'])->toMatchArray([
            'job_id' => '22222222-2222-4222-8222-222222222222',
            'request_id' => '11111111-1111-4111-8111-111111111111',
            'class' => 'App\\Jobs\\SendInvoice',
            'queue' => 'default',
            'connection' => 'redis',
            'status' => 'completed',
            'attempts' => 1,
            'duration_ms' => 1250,
            'user_id' => '42',
            'exception' => null,
            'failed_at' => null,
        ])
        ->and($event['data'])->not->toHaveKey('environment')
        ->and(CarbonImmutable::parse($event['data']['started_at'])->toIso8601String())->toBe('2026-07-01T10:15:00+00:00')
        ->and(CarbonImmutable::parse($event['data']['completed_at'])->toIso8601String())->toBe('2026-07-01T10:15:01+00:00');
});

it('translates a released job attempt without a finish time', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchJobTranslationAttempt(['status' => 'released', 'attempt' => 2]));

    expect($event['data'])->toMatchArray([
        'status' => 'released',
        'attempts' => 2,
        'completed_at' => null,
        'failed_at' => null,
    ]);
});

it('translates a failed job attempt with its exception preview', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchJobTranslationAttempt([
        'status' => 'failed',
        'exception_preview' => 'Connection refused',
    ]));

    expect($event['data']['status'])->toBe('failed')
        ->and($event['data']['completed_at'])->toBeNull()
        ->and($event['data']['exception'])->toBe(['message' => 'Connection refused'])
        ->and(CarbonImmutable::parse($event['data']['failed_at'])->toIso8601String())->toBe('2026-07-01T10:15:01+00:00');
});

it('treats an empty user as no user', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchJobTranslationAttempt(['user' => '']));

    expect($event['data']['user_id'])->toBeNull();
});

it('cuts job attempt strings to their column lengths', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchJobTranslationAttempt([
        'queue' => str_repeat('q', 150),
        'connection' => str_repeat('c', 150),
        'name' => str_repeat('n', 400),
        'job_id' => str_repeat('j', 100),
    ]));

    expect(mb_strlen($event['data']['queue']))->toBe(100)
        ->and(mb_strlen($event['data']['connection']))->toBe(100)
        ->and(mb_strlen($event['data']['class']))->toBe(255)
        ->and(mb_strlen($event['data']['job_id']))->toBe(64);
});

it('does not map the deploy id of job records to an environment', function () {
    $translator = new NightwatchTranslator;
    $deploy = 'v1.4.2';

    $attempt = $translator->translate(nightwatchJobTranslationAttempt(['deploy' => $deploy]));
    $queued = $translator->translate([
        't' => 'queued-job',
        'job_id' => '22222222-2222-4222-8222-222222222222',
        'name' => 'App\\Jobs\\SendInvoice',
        'deploy' => $deploy,
    ]);

    expect($attempt['data'])->not->toHaveKey('environment')
        ->and($queued['data'])->not->toHaveKey('environment');
});

it('translates a queued job with its job id and user but without a duration', function () {
    $event = (new NightwatchTranslator)->translate([
        'v' => 1,
        't' => 'queued-job',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:14:59', 'UTC')->getTimestamp() + 0.5,
        'deploy' => 'v1.4.2',
        'trace_id' => '11111111-1111-4111-8111-111111111111',
        'user' => '42',
        'job_id' => '22222222-2222-4222-8222-222222222222',
        'name' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => str_repeat('q', 150),
        'duration' => 480,
    ]);

    expect($event['type'])->toBe('job')
        ->and($event['id'])->toBe('22222222-2222-4222-8222-222222222222')
        ->and($event['data'])->not->toHaveKey('duration_ms')
        ->and($event['data'])->toMatchArray([
            'job_id' => '22222222-2222-4222-8222-222222222222',
            'request_id' => '11111111-1111-4111-8111-111111111111',
            'class' => 'App\\Jobs\\SendInvoice',
            'status' => 'queued',
            'user_id' => '42',
        ])
        ->and(mb_strlen($event['data']['queue']))->toBe(100)
        ->and(CarbonImmutable::parse($event['data']['dispatched_at'])->toIso8601String())->toBe('2026-07-01T10:14:59+00:00');
});
