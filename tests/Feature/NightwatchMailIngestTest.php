<?php

use App\Models\MailSend;
use App\Models\NotificationSend;
use App\Models\Organization;
use App\Models\OutgoingRequest;
use App\Models\Project;
use App\Models\Trace;
use App\Models\User;
use App\Watch\Stats\MailStats;
use App\Watch\Stats\NotificationStats;
use App\Watch\Stats\OutgoingRequestStats;
use App\Watch\Stats\TimeRange;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Organization::factory()->create();
    $this->project = Project::factory()->create();
    $this->token = ingestToken($this->project->id);
    $this->startedAt = now()->subMinutes(5)->getTimestamp() + 0.123456;
    $this->traceId = '4d9a3c1e-0000-4000-8000-000000000001';
});

/**
 * One request with a mail, a notification and an outgoing request sent during it, as the Nightwatch agent forwards them.
 *
 * @return list<array<string, mixed>>
 */
function nightwatchMailBatch(float $startedAt, string $traceId, bool $withRequest = true): array
{
    $source = [
        'trace_id' => $traceId,
        'execution_source' => 'request',
        'execution_id' => $traceId,
        'execution_preview' => 'POST /orders',
        'execution_stage' => 'action',
        'user' => '42',
        'deploy' => 'production',
        'server' => 'web-1',
    ];

    $records = [
        [
            ...$source,
            't' => 'mail',
            'timestamp' => $startedAt + 0.1,
            '_group' => md5('mail'),
            'mailer' => 'smtp',
            'class' => 'App\\Mail\\OrderShipped',
            'subject' => 'Your order has shipped',
            'to' => 1,
            'cc' => 1,
            'bcc' => 0,
            'attachments' => 2,
            'duration' => 180_000,
            'failed' => false,
        ],
        [
            ...$source,
            't' => 'notification',
            'timestamp' => $startedAt + 0.2,
            '_group' => md5('notification'),
            'channel' => 'mail',
            'class' => 'App\\Notifications\\InvoicePaid',
            'duration' => 95_000,
            'failed' => false,
        ],
        [
            ...$source,
            't' => 'outgoing-request',
            'timestamp' => $startedAt + 0.3,
            '_group' => md5('outgoing'),
            'host' => 'api.example.com',
            'method' => 'POST',
            'url' => 'https://api.example.com/v1/orders',
            'duration' => 340_000,
            'request_size' => 512,
            'response_size' => 2048,
            'status_code' => 201,
        ],
    ];

    if ($withRequest) {
        array_unshift($records, [
            ...$source,
            't' => 'request',
            'timestamp' => $startedAt,
            'method' => 'POST',
            'url' => 'https://app.example.test/orders',
            'route_path' => '/orders',
            'status_code' => 302,
            'duration' => 400_000,
        ]);
    }

    return $records;
}

it('stores mail, notification and outgoing request records against the request trace', function () {
    postNightwatchRecords(nightwatchMailBatch($this->startedAt, $this->traceId), $this->token)->assertOk();

    $trace = Trace::where('project_id', $this->project->id)->sole();

    expect($trace->correlation_id)->toBe($this->traceId)
        ->and($trace->is_stub)->toBeFalse();

    $mail = MailSend::sole();
    expect($mail->trace_id)->toBe($trace->id)
        ->and($mail->project_id)->toBe($this->project->id)
        ->and($mail->mailable_class)->toBe('App\\Mail\\OrderShipped')
        ->and($mail->mailer)->toBe('smtp')
        ->and($mail->subject)->toBe('Your order has shipped')
        ->and($mail->recipients_count)->toBe(2)
        ->and($mail->attachments_count)->toBe(2)
        ->and($mail->status)->toBe('sent')
        ->and($mail->duration_ms)->toBe(180)
        ->and($mail->source_type)->toBe('request')
        ->and($mail->source_id)->toBe($this->traceId)
        ->and($mail->source_label)->toBe('POST /orders')
        ->and($mail->environment)->toBe('production');

    $notification = NotificationSend::sole();
    expect($notification->trace_id)->toBe($trace->id)
        ->and($notification->notification_class)->toBe('App\\Notifications\\InvoicePaid')
        ->and($notification->channel)->toBe('mail')
        ->and($notification->status)->toBe('sent')
        ->and($notification->duration_ms)->toBe(95);

    $outgoing = OutgoingRequest::sole();
    expect($outgoing->trace_id)->toBe($trace->id)
        ->and($outgoing->method)->toBe('POST')
        ->and($outgoing->host)->toBe('api.example.com')
        ->and($outgoing->status_code)->toBe(201)
        ->and($outgoing->duration_ms)->toBe(340)
        ->and($outgoing->request_size_bytes)->toBe(512)
        ->and($outgoing->response_size_bytes)->toBe(2048);
});

it('creates a stub trace for children that arrive without their request', function () {
    postNightwatchRecords(nightwatchMailBatch($this->startedAt, $this->traceId, withRequest: false), $this->token)->assertOk();

    $trace = Trace::where('project_id', $this->project->id)->sole();

    expect($trace->is_stub)->toBeTrue()
        ->and(MailSend::sole()->trace_id)->toBe($trace->id)
        ->and(NotificationSend::sole()->trace_id)->toBe($trace->id)
        ->and(OutgoingRequest::sole()->trace_id)->toBe($trace->id);
});

it('counts ingested records in the stats classes', function () {
    postNightwatchRecords(nightwatchMailBatch($this->startedAt, $this->traceId), $this->token)->assertOk();

    $range = TimeRange::fromLabel('24h');

    expect((new MailStats)->summary($this->project, $range)['totals']['total'])->toBe(1)
        ->and((new NotificationStats)->summary($this->project, $range)['totals']['total'])->toBe(1)
        ->and((new OutgoingRequestStats)->summary($this->project, $range)['totals']['total'])->toBe(1);
});

it('lists an ingested mail on the mail page and resolves its detail page', function () {
    postNightwatchRecords(nightwatchMailBatch($this->startedAt, $this->traceId), $this->token)->assertOk();

    $hash = sha1('App\\Mail\\OrderShipped');
    $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

    $this->actingAs($admin)
        ->get(route('projects.mail.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.totals.total', 1)
            ->has('mails.data', 1)
            ->where('mails.data.0.mailable_class', 'App\\Mail\\OrderShipped')
            ->where('mails.data.0.hash', $hash));

    $this->get(route('projects.mail.show', [$this->project, $hash]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('detail.mailable_class', 'App\\Mail\\OrderShipped')
            ->where('detail.totals.total', 1)
            ->has('detail.sends', 1)
            ->where('detail.sends.0.subject', 'Your order has shipped'));
});

it('lists ingested notifications and outgoing requests on their pages and detail pages', function () {
    postNightwatchRecords(nightwatchMailBatch($this->startedAt, $this->traceId), $this->token)->assertOk();

    $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    $notificationHash = sha1('App\\Notifications\\InvoicePaid');
    $hostHash = sha1('api.example.com');

    $this->actingAs($admin)
        ->get(route('projects.notifications.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('summary.totals.total', 1));

    $this->get(route('projects.notifications.show', [$this->project, $notificationHash]))->assertOk();

    $this->get(route('projects.http-client.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.totals.total', 1)
            ->where('domains.data.0.hash', $hostHash));

    $this->get(route('projects.http-client.show', [$this->project, $hostHash]))->assertOk();
});

it('survives oversized strings in mail, notification and outgoing request records', function () {
    $records = nightwatchMailBatch($this->startedAt, $this->traceId);
    $records[1]['mailer'] = str_repeat('m', 80);
    $records[2]['channel'] = str_repeat('c', 80);
    $records[3]['url'] = 'https://api.example.com/'.str_repeat('a', 3000);

    postNightwatchRecords($records, $this->token)->assertOk();

    expect(MailSend::sole()->mailer)->toBe(str_repeat('m', 50))
        ->and(NotificationSend::sole()->channel)->toBe(str_repeat('c', 50))
        ->and(mb_strlen(OutgoingRequest::sole()->url))->toBe(2048);
});

it('names a mail without a mailable class UnknownMailable', function () {
    $records = nightwatchMailBatch($this->startedAt, $this->traceId);
    $records[1]['class'] = '';

    postNightwatchRecords($records, $this->token)->assertOk();

    expect(MailSend::sole()->mailable_class)->toBe('UnknownMailable');
});
