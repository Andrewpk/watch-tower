<?php

use App\Watch\NightwatchTranslator;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nightwatchMailRecord(array $overrides = []): array
{
    return [
        'v' => 1,
        't' => 'mail',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456,
        'deploy' => 'production',
        'server' => 'web-1',
        '_group' => md5('mail-group'),
        'trace_id' => '4d9a3c1e-0000-4000-8000-000000000001',
        'execution_source' => 'request',
        'execution_id' => '4d9a3c1e-0000-4000-8000-000000000001',
        'execution_preview' => 'POST /orders',
        'execution_stage' => 'action',
        'user' => '42',
        'mailer' => 'smtp',
        'class' => 'App\\Mail\\OrderShipped',
        'subject' => 'Your order has shipped',
        'to' => 2,
        'cc' => 1,
        'bcc' => 3,
        'attachments' => 1,
        'duration' => 2_500_000,
        'failed' => false,
        ...$overrides,
    ];
}

it('translates a mail record', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchMailRecord());

    expect($event['type'])->toBe('mail')
        ->and($event['id'])->toBe(md5('mail-group'))
        ->and($event['data'])->toBe([
            'request_id' => '4d9a3c1e-0000-4000-8000-000000000001',
            'class' => 'App\\Mail\\OrderShipped',
            'mailer' => 'smtp',
            'subject' => 'Your order has shipped',
            'recipients_count' => 6,
            'attachments_count' => 1,
            'status' => 'sent',
            'duration_ms' => 2500,
            'source_type' => 'request',
            'source_id' => '4d9a3c1e-0000-4000-8000-000000000001',
            'source_label' => 'POST /orders',
            'environment' => 'production',
            'occurred_at' => '2026-07-01T10:15:00+00:00',
        ]);
});

it('marks a failed mail and names an unclassed one', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchMailRecord(['failed' => true, 'class' => '']));

    expect($event['data']['status'])->toBe('failed')
        ->and($event['data']['class'])->toBe('UnknownMailable');
});

it('cuts mail strings to their column lengths', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchMailRecord([
        'mailer' => str_repeat('m', 60),
        'subject' => str_repeat('s', 400),
        'execution_source' => str_repeat('e', 60),
        'execution_preview' => str_repeat('p', 300),
    ]));

    expect(mb_strlen($event['data']['mailer']))->toBe(50)
        ->and(mb_strlen($event['data']['subject']))->toBe(255)
        ->and(mb_strlen($event['data']['source_type']))->toBe(50)
        ->and(mb_strlen($event['data']['source_label']))->toBe(255);
});

it('does not fail on array-valued string fields', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchMailRecord(['subject' => ['x'], 'mailer' => ['y']]));

    expect($event['data']['subject'])->toBeNull()
        ->and($event['data']['mailer'])->toBeNull();
});

it('translates a notification record', function () {
    $event = (new NightwatchTranslator)->translate([
        't' => 'notification',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.5,
        'deploy' => 'production',
        '_group' => md5('notification-group'),
        'trace_id' => 'trace-1',
        'execution_source' => 'job',
        'execution_id' => 'job-uuid',
        'execution_preview' => 'App\\Jobs\\SendInvoice',
        'channel' => 'mail',
        'class' => 'App\\Notifications\\InvoicePaid',
        'duration' => 1_250_000,
        'failed' => false,
    ]);

    expect($event['type'])->toBe('notification')
        ->and($event['id'])->toBe(md5('notification-group'))
        ->and($event['data'])->toMatchArray([
            'request_id' => 'trace-1',
            'class' => 'App\\Notifications\\InvoicePaid',
            'channel' => 'mail',
            'status' => 'sent',
            'duration_ms' => 1250,
            'source_type' => 'job',
            'source_id' => 'job-uuid',
            'source_label' => 'App\\Jobs\\SendInvoice',
            'environment' => 'production',
        ]);
});

it('cuts a long notification channel to 50 characters', function () {
    $event = (new NightwatchTranslator)->translate([
        't' => 'notification',
        'channel' => str_repeat('c', 60),
        'class' => 'App\\Notifications\\InvoicePaid',
    ]);

    expect($event['data']['channel'])->toBe(str_repeat('c', 50));
});

it('translates an outgoing request record', function () {
    $event = (new NightwatchTranslator)->translate([
        't' => 'outgoing-request',
        'timestamp' => CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.25,
        'deploy' => 'production',
        '_group' => md5('http-group'),
        'trace_id' => 'trace-1',
        'execution_source' => 'command',
        'execution_id' => 'cmd-uuid',
        'execution_preview' => 'php artisan sync',
        'host' => 'api.example.com',
        'method' => 'post',
        'url' => 'https://api.example.com/v1/orders',
        'duration' => 340_000,
        'request_size' => 512,
        'response_size' => 2048,
        'status_code' => 201,
    ]);

    expect($event['type'])->toBe('outgoing-request')
        ->and($event['id'])->toBe(md5('http-group'))
        ->and($event['data'])->toBe([
            'request_id' => 'trace-1',
            'method' => 'POST',
            'host' => 'api.example.com',
            'url' => 'https://api.example.com/v1/orders',
            'status_code' => 201,
            'duration_ms' => 340,
            'request_size_bytes' => 512,
            'response_size_bytes' => 2048,
            'source_type' => 'command',
            'source_id' => 'cmd-uuid',
            'source_label' => 'php artisan sync',
            'environment' => 'production',
            'occurred_at' => '2026-07-01T10:15:00+00:00',
        ]);
});

it('cuts a long outgoing request url to 2048 characters', function () {
    $event = (new NightwatchTranslator)->translate([
        't' => 'outgoing-request',
        'host' => 'api.example.com',
        'method' => 'GET',
        'url' => 'https://api.example.com/'.str_repeat('a', 3000),
    ]);

    expect(mb_strlen($event['data']['url']))->toBe(2048);
});

it('drops outgoing request numbers that do not fit their columns', function () {
    $event = (new NightwatchTranslator)->translate([
        't' => 'outgoing-request',
        'host' => 'api.example.com',
        'method' => 'GET',
        'url' => 'https://api.example.com/',
        'status_code' => 70000,
        'request_size' => -1,
        'response_size' => 'n/a',
    ]);

    expect($event['data']['status_code'])->toBeNull()
        ->and($event['data']['request_size_bytes'])->toBeNull()
        ->and($event['data']['response_size_bytes'])->toBeNull();
});

it('cuts the environment of mail, notification and outgoing request records to 50 characters', function () {
    $translator = new NightwatchTranslator;
    $deploy = str_repeat('d', 60);

    foreach (['mail', 'notification', 'outgoing-request'] as $type) {
        $event = $translator->translate([
            't' => $type,
            'deploy' => $deploy,
            'class' => 'App\\Thing',
            'channel' => 'mail',
            'host' => 'api.example.com',
            'url' => 'https://api.example.com/',
        ]);

        expect($event['data']['environment'])->toBe(str_repeat('d', 50));
    }
});

it('stores the base name of a notification channel given as a class name', function () {
    $event = (new NightwatchTranslator)->translate([
        't' => 'notification',
        'class' => 'App\\Notifications\\InvoicePaid',
        'channel' => 'NotificationChannels\\PusherPushNotifications\\PusherChannel',
    ]);

    expect($event['data']['channel'])->toBe('PusherChannel');
});

it('drops the file path after the NUL byte of an anonymous mailable', function () {
    $event = (new NightwatchTranslator)->translate(nightwatchMailRecord([
        'class' => "Illuminate\\Mail\\Mailable@anonymous\0/var/www/app/Http/Controllers/OrderController.php:12$0",
    ]));

    expect($event['data']['class'])->toBe('Illuminate\\Mail\\Mailable@anonymous');
});

it('drops the file path after the NUL byte of an anonymous notification', function () {
    $event = (new NightwatchTranslator)->translate([
        't' => 'notification',
        'channel' => 'mail',
        'class' => "Illuminate\\Notifications\\Notification@anonymous\0/var/www/app/Jobs/Notify.php:30$0",
    ]);

    expect($event['data']['class'])->toBe('Illuminate\\Notifications\\Notification@anonymous');
});
