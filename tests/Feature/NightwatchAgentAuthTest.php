<?php

use App\Models\Project;
use Illuminate\Support\Facades\Crypt;

it('rejects agent-auth without a bearer token', function () {
    $this->postJson('/api/agent-auth', [])
        ->assertStatus(401)
        ->assertJson([
            'message' => 'Missing environment token',
            'stop' => true,
        ]);
});

it('rejects agent-auth with an unknown token', function () {
    $this->withHeader('Authorization', 'Bearer pk_unknown')
        ->postJson('/api/agent-auth', [])
        ->assertStatus(401)
        ->assertJson([
            'message' => 'Invalid environment token',
            'stop' => true,
        ]);
});

it('issues a short-lived ingest token for a known project', function () {
    $project = Project::factory()->withApiKey('pk_test_known')->create();

    $response = $this->withHeader('Authorization', 'Bearer pk_test_known')
        ->postJson('/api/agent-auth', []);

    $response->assertOk()
        ->assertJsonStructure(['token', 'expires_in', 'refresh_in', 'ingest_url']);

    $token = $response->json('token');
    $payload = json_decode(Crypt::decryptString($token), true);

    expect($payload['project_id'])->toBe($project->id);
    expect($payload['expires_at'])->toBeGreaterThan(time());
    expect($payload['key_version'])->toBe(1);
    expect($response->json('ingest_url'))->toBe(route('api.nightwatch.ingest'));
});

it('rate limits agent-auth per client, counting failed attempts too', function () {
    config()->set('watch.ingest.agent_auth_per_minute', 2);
    Project::factory()->withApiKey('pk_test_known')->create();

    $this->withHeader('Authorization', 'Bearer pk_guess_1')->postJson('/api/agent-auth', [])->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer pk_guess_2')->postJson('/api/agent-auth', [])->assertStatus(401);

    $response = $this->withHeader('Authorization', 'Bearer pk_test_known')
        ->postJson('/api/agent-auth', [])
        ->assertStatus(429)
        ->assertExactJson(['message' => 'Too many requests', 'stop' => false])
        ->assertHeader('Retry-After');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('does not rate limit agent-auth when the limit is zero', function () {
    config()->set('watch.ingest.agent_auth_per_minute', 0);
    Project::factory()->withApiKey('pk_test_known')->create();

    foreach (range(1, 5) as $ignored) {
        $this->withHeader('Authorization', 'Bearer pk_test_known')->postJson('/api/agent-auth', [])->assertOk();
    }
});
