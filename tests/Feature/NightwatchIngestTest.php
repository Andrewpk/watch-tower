<?php

use App\Jobs\ProcessWatchEvent;
use App\Models\Project;
use App\Watch\NightwatchTranslator;
use Illuminate\Support\Facades\Queue;

it('rejects ingest without a token', function () {
    test()->call('POST', '/api/nightwatch-ingest')->assertStatus(401);
});

it('rejects ingest with an invalid token', function () {
    postNightwatchRecords([], 'not-an-encrypted-token')->assertStatus(401);
});

it('rejects ingest with an expired token', function () {
    $project = Project::factory()->create();
    $token = ingestToken($project->id, expiresInSeconds: -10);

    postNightwatchRecords([], $token)
        ->assertStatus(401)
        ->assertJson(['message' => 'Expired ingest token']);
});

it('queues a translated request record', function () {
    Queue::fake();
    $project = Project::factory()->create();

    $record = [
        'v' => 1,
        't' => 'request',
        'timestamp' => 1762000000.123,
        'trace_id' => 'trace-abc',
        'method' => 'POST',
        'url' => 'https://app.test/login',
        'route_path' => '/login',
        'status_code' => 200,
        'duration' => 125000, // microseconds
        'ip' => '203.0.113.4',
        'server' => 'web-1',
        'deploy' => 'production',
        'user' => '42',
        'queries' => 3,
        'peak_memory_usage' => 4194304, // 4 MB
    ];

    postNightwatchRecords([$record], ingestToken($project->id))
        ->assertOk()
        ->assertJson(['records_received' => 1, 'records_queued' => 1]);

    Queue::assertPushed(ProcessWatchEvent::class, function ($job) use ($project) {
        return $job->projectId === $project->id
            && $job->event['type'] === 'request'
            && $job->event['data']['method'] === 'POST'
            && $job->event['data']['duration_ms'] === 125
            && $job->event['data']['memory_used_kb'] === 4096
            && $job->event['data']['request_id'] === 'trace-abc';
    });
});

it('skips unknown record types', function () {
    Queue::fake();
    $project = Project::factory()->create();

    postNightwatchRecords([
        ['v' => 1, 't' => 'something-new', 'trace_id' => 'x'],
    ], ingestToken($project->id))
        ->assertOk()
        ->assertJson(['records_received' => 1, 'records_queued' => 0]);

    Queue::assertNotPushed(ProcessWatchEvent::class);
});

it('queues a translated cache-event record', function () {
    Queue::fake();
    $project = Project::factory()->create();

    $record = [
        'v' => 1,
        't' => 'cache-event',
        'timestamp' => 1778755417.835628,
        'deploy' => 'production',
        'server' => 'web-1',
        '_group' => 'group-hash',
        'trace_id' => 'trace-cache-1',
        'execution_source' => 'request',
        'execution_preview' => 'GET /home',
        'store' => 'database',
        'key' => 'test',
        'type' => 'write',
        'duration' => 3223,
        'ttl' => 60,
    ];

    postNightwatchRecords([$record], ingestToken($project->id))
        ->assertOk()
        ->assertJson(['records_received' => 1, 'records_queued' => 1]);

    Queue::assertPushed(ProcessWatchEvent::class, function ($job) use ($project) {
        return $job->projectId === $project->id
            && $job->event['type'] === 'cache-event'
            && $job->event['data']['key'] === 'test'
            && $job->event['data']['store'] === 'database'
            && $job->event['data']['operation'] === 'write'
            && $job->event['data']['succeeded'] === true
            && $job->event['data']['duration_ms'] === 3
            && ! array_key_exists('environment', $job->event['data'])
            && $job->event['data']['request_id'] === 'trace-cache-1';
    });
});

it('queues a translated log record', function () {
    Queue::fake();
    $project = Project::factory()->create();

    $record = [
        'v' => 1,
        't' => 'log',
        'timestamp' => 1778754766.635732,
        'deploy' => 'production',
        'server' => 'web-1',
        'trace_id' => 'trace-log-1',
        'execution_source' => 'request',
        'execution_preview' => 'GET /home',
        'user' => 'amal',
        'level' => 'info',
        'message' => 'HomeController@index called',
        'context' => '{"foo":"bar"}',
    ];

    postNightwatchRecords([$record], ingestToken($project->id))
        ->assertOk()
        ->assertJson(['records_received' => 1, 'records_queued' => 1]);

    Queue::assertPushed(ProcessWatchEvent::class, function ($job) use ($project) {
        return $job->projectId === $project->id
            && $job->event['type'] === 'log'
            && $job->event['data']['level'] === 'info'
            && $job->event['data']['message'] === 'HomeController@index called'
            && $job->event['data']['source_type'] === 'request'
            && $job->event['data']['source_label'] === 'GET /home'
            && $job->event['data']['user_name'] === 'amal'
            && $job->event['data']['context'] === ['foo' => 'bar']
            && ! array_key_exists('environment', $job->event['data'])
            && $job->event['data']['request_id'] === 'trace-log-1';
    });
});

it('rejects payload with malformed JSON', function () {
    $project = Project::factory()->create();

    test()->call('POST', '/api/nightwatch-ingest', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.ingestToken($project->id),
        'HTTP_CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], 'not valid json')
        ->assertStatus(400);
});

/**
 * An empty records payload padded with JSON whitespace to an exact byte length.
 */
function paddedRecordsPayload(int $bytes): string
{
    return str_pad('{"records":[]}', $bytes);
}

it('defaults to sensible ingest limits', function () {
    expect(config('watch.ingest'))->toBe([
        'max_decoded_bytes' => 10 * 1024 * 1024,
        'max_records' => 5000,
        'rate_limit_per_minute' => 600,
        'agent_auth_per_minute' => 30,
    ]);
});

it('rejects a gzip payload that inflates past the decoded size limit', function () {
    Queue::fake();
    config()->set('watch.ingest.max_decoded_bytes', 1024);
    $project = Project::factory()->create();

    $compressed = gzencode(json_encode([
        'records' => [['v' => 1, 't' => 'log', 'message' => str_repeat('x', 50_000)]],
    ]));

    expect(strlen($compressed))->toBeLessThan(1024);

    postNightwatchBody($compressed, ingestToken($project->id))
        ->assertStatus(413)
        ->assertJson(['stop' => false])
        ->assertJsonStructure(['message', 'stop']);

    Queue::assertNothingPushed();
});

it('rejects a gzip bomb as too large without inflating it fully', function () {
    Queue::fake();
    config()->set('watch.ingest.max_decoded_bytes', 1024 * 1024);
    $project = Project::factory()->create();

    $bomb = gzencode(str_repeat("\0", 20 * 1024 * 1024), 9);

    expect(strlen($bomb))->toBeLessThan(64 * 1024);

    postNightwatchBody($bomb, ingestToken($project->id))
        ->assertStatus(413)
        ->assertJson(['stop' => false]);

    Queue::assertNothingPushed();
});

it('accepts a payload exactly at the decoded size limit and rejects one byte more', function (bool $gzip) {
    Queue::fake();
    config()->set('watch.ingest.max_decoded_bytes', 2048);
    $token = ingestToken(Project::factory()->create()->id);

    $encode = fn (string $payload): string => $gzip ? gzencode($payload) : $payload;

    postNightwatchBody($encode(paddedRecordsPayload(2048)), $token, $gzip)
        ->assertOk()
        ->assertJson(['records_received' => 0]);

    postNightwatchBody($encode(paddedRecordsPayload(2049)), $token, $gzip)
        ->assertStatus(413)
        ->assertJson(['stop' => false]);
})->with([
    'gzip' => true,
    'raw' => false,
]);

it('rejects a raw body over the size limit', function () {
    Queue::fake();
    config()->set('watch.ingest.max_decoded_bytes', 1024);
    $project = Project::factory()->create();

    postNightwatchBody(paddedRecordsPayload(5000), ingestToken($project->id), gzip: false)
        ->assertStatus(413)
        ->assertJson(['stop' => false]);

    Queue::assertNothingPushed();
});

it('reports corrupt or truncated gzip as a bad request, not as too large', function (string $body) {
    config()->set('watch.ingest.max_decoded_bytes', 1024 * 1024);
    $project = Project::factory()->create();

    postNightwatchBody($body, ingestToken($project->id))
        ->assertStatus(400)
        ->assertJson(['message' => 'Unable to decode payload', 'stop' => false]);
})->with([
    'garbage' => fn () => "\x1f\x8b\x08\x00not-really-gzip-data-at-all",
    'not gzip at all' => fn () => '{"records":[]}',
    'truncated' => fn () => substr(gzencode(str_repeat('abc', 5000)), 0, -10),
]);

it('rejects more records than the limit before translating or queuing anything', function () {
    Queue::fake();
    config()->set('watch.ingest.max_records', 3);
    $project = Project::factory()->create();

    $this->mock(NightwatchTranslator::class)->shouldNotReceive('translate');

    $record = ['v' => 1, 't' => 'log', 'level' => 'info', 'message' => 'hi', 'trace_id' => 'trace-1'];

    postNightwatchRecords(array_fill(0, 4, $record), ingestToken($project->id))
        ->assertStatus(413)
        ->assertJson(['stop' => false])
        ->assertJsonStructure(['message', 'stop']);

    Queue::assertNothingPushed();
});

it('accepts exactly the maximum number of records', function () {
    Queue::fake();
    config()->set('watch.ingest.max_records', 3);
    $project = Project::factory()->create();

    $record = ['v' => 1, 't' => 'log', 'level' => 'info', 'message' => 'hi', 'trace_id' => 'trace-1'];

    postNightwatchRecords(array_fill(0, 3, $record), ingestToken($project->id))
        ->assertOk()
        ->assertJson(['records_received' => 3, 'records_queued' => 3]);

    Queue::assertPushed(ProcessWatchEvent::class, 3);
});

it('rate limits ingest per project', function () {
    Queue::fake();
    config()->set('watch.ingest.rate_limit_per_minute', 2);
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $token = ingestToken($project->id);

    postNightwatchRecords([], $token)->assertOk();
    postNightwatchRecords([], $token)->assertOk();

    $response = postNightwatchRecords([], $token)
        ->assertStatus(429)
        ->assertExactJson(['message' => 'Too many requests', 'stop' => false])
        ->assertHeader('Retry-After');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    postNightwatchRecords([], ingestToken($otherProject->id))->assertOk();
});

it('does not rate limit ingest when the limit is zero', function () {
    config()->set('watch.ingest.rate_limit_per_minute', 0);
    $token = ingestToken(Project::factory()->create()->id);

    foreach (range(1, 5) as $ignored) {
        postNightwatchRecords([], $token)->assertOk();
    }
});

it('does not count unauthenticated ingest requests against any project', function () {
    config()->set('watch.ingest.rate_limit_per_minute', 1);
    $token = ingestToken(Project::factory()->create()->id);

    postNightwatchRecords([], 'not-an-encrypted-token')->assertStatus(401);
    postNightwatchRecords([], 'not-an-encrypted-token')->assertStatus(401);

    postNightwatchRecords([], $token)->assertOk();
});
