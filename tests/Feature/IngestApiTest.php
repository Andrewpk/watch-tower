<?php

use App\Jobs\ProcessWatchEvent;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('rejects requests without an API key', function () {
    $this->postJson('/api/ingest', [
        'events' => [['type' => 'request', 'data' => []]],
    ])->assertStatus(401);
});

it('rejects requests with an unknown API key', function () {
    $this->withHeader('Authorization', 'Bearer pk_test_unknown')
        ->postJson('/api/ingest', [
            'events' => [['type' => 'request', 'data' => []]],
        ])
        ->assertStatus(401);
});

it('queues each event in the batch', function () {
    Queue::fake();

    $project = Project::factory()->withApiKey('pk_test_known')->create();
    $correlationId = (string) Str::uuid();

    $response = $this->withHeader('Authorization', 'Bearer pk_test_known')
        ->postJson('/api/ingest', [
            'batch_id' => 'batch-1',
            'events' => [
                ['type' => 'request', 'data' => ['request_id' => $correlationId, 'method' => 'GET', 'uri' => '/users']],
                ['type' => 'exception', 'data' => ['class' => 'RuntimeException', 'file' => 'app/Foo.php', 'line' => 42, 'message' => 'boom']],
            ],
        ]);

    $response->assertStatus(202)->assertJson([
        'success' => true,
        'batch_id' => 'batch-1',
        'events_received' => 2,
        'events_queued' => 2,
    ]);

    Queue::assertPushed(ProcessWatchEvent::class, 2);
    Queue::assertPushed(fn (ProcessWatchEvent $job) => $job->projectId === $project->id);
});

it('rejects payloads that fail validation', function () {
    Project::factory()->withApiKey('pk_test_valid')->create();

    $this->withHeader('Authorization', 'Bearer pk_test_valid')
        ->postJson('/api/ingest', ['events' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['events']);

    $this->withHeader('Authorization', 'Bearer pk_test_valid')
        ->postJson('/api/ingest', [
            'events' => [['type' => 'not_a_real_type', 'data' => []]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['events.0.type']);
});

it('synchronously stores events on the sync endpoint', function () {
    $project = Project::factory()->withApiKey('pk_test_sync')->create();
    $correlationId = (string) Str::uuid();

    $this->withHeader('Authorization', 'Bearer pk_test_sync')
        ->postJson('/api/ingest/sync', [
            'events' => [
                ['type' => 'request', 'data' => ['request_id' => $correlationId, 'method' => 'POST', 'uri' => '/api/login', 'status_code' => 200]],
            ],
        ])
        ->assertOk()
        ->assertJson(['success' => true, 'events_stored' => 1]);

    $this->assertDatabaseHas('traces', [
        'project_id' => $project->id,
        'correlation_id' => $correlationId,
        'method' => 'POST',
        'uri' => '/api/login',
        'status_code' => 200,
    ]);
});

it('rate limits the legacy ingest endpoints per project', function () {
    Queue::fake();
    config()->set('watch.ingest.rate_limit_per_minute', 2);

    Project::factory()->withApiKey('pk_test_limited')->create();
    Project::factory()->withApiKey('pk_test_other')->create();

    $payload = ['events' => [['type' => 'request', 'data' => ['request_id' => (string) Str::uuid()]]]];

    $this->withHeader('Authorization', 'Bearer pk_test_limited')->postJson('/api/ingest', $payload)->assertStatus(202);
    $this->withHeader('Authorization', 'Bearer pk_test_limited')->postJson('/api/ingest', $payload)->assertStatus(202);

    $response = $this->withHeader('Authorization', 'Bearer pk_test_limited')
        ->postJson('/api/ingest/sync', $payload)
        ->assertStatus(429)
        ->assertExactJson(['error' => 'Too many requests.'])
        ->assertHeader('Retry-After');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $this->withHeader('Authorization', 'Bearer pk_test_other')->postJson('/api/ingest', $payload)->assertStatus(202);
});
