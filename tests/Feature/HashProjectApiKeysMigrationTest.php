<?php

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The migration that replaced plain-text keys with hashes.
 */
function hashApiKeysMigration(): Migration
{
    return require glob(database_path('migrations/*_hash_project_api_keys.php'))[0];
}

/**
 * Insert a project the way the schema before the migration stored it.
 */
function insertLegacyProject(string $apiKey): string
{
    $id = (string) Str::uuid();

    DB::table('projects')->insert([
        'id' => $id,
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'Legacy',
        'slug' => 'legacy-'.Str::lower(Str::random(5)),
        'api_key' => $apiKey,
        'api_secret_hash' => 'unused',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('hashes keys that were stored in plain text and drops the plain-text columns', function () {
    Queue::fake();
    $migration = hashApiKeysMigration();
    $migration->down();

    $id = insertLegacyProject('pk_legacy_known_key');
    $migration->up();

    $row = DB::table('projects')->where('id', $id)->first();

    expect(Schema::hasColumn('projects', 'api_key'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'api_secret_hash'))->toBeFalse()
        ->and($row->api_key_hash)->toBe(hash('sha256', 'pk_legacy_known_key'))
        ->and($row->api_key_prefix)->toBe('pk_legacy_')
        ->and($row->api_key_version)->toBe(1)
        ->and($row->previous_api_key_hash)->toBeNull()
        ->and($row->api_key_last_used_at)->toBeNull()
        ->and(json_encode(DB::table('projects')->get()))->not->toContain('pk_legacy_known_key');

    $this->withHeader('Authorization', 'Bearer pk_legacy_known_key')
        ->postJson('/api/agent-auth')
        ->assertOk();

    $this->withHeader('Authorization', 'Bearer pk_legacy_known_key')
        ->postJson('/api/ingest', ['events' => [['type' => 'request', 'data' => ['request_id' => 'req-1']]]])
        ->assertStatus(202);

    expect(Project::findByApiKey('pk_legacy_known_key')?->id)->toBe($id);
});

it('gives every project its own hash', function () {
    $migration = hashApiKeysMigration();
    $migration->down();

    $first = insertLegacyProject('pk_legacy_first');
    $second = insertLegacyProject('pk_legacy_second');
    $migration->up();

    expect(DB::table('projects')->where('id', $first)->value('api_key_hash'))
        ->not->toBe(DB::table('projects')->where('id', $second)->value('api_key_hash'));
});

it('restores plain-text columns with fresh keys when rolled back, since hashes cannot be reversed', function () {
    $migration = hashApiKeysMigration();
    $project = Project::factory()->withApiKey('pk_test_before_rollback')->create();

    $migration->down();

    $row = DB::table('projects')->where('id', $project->id)->first();

    expect(Schema::hasColumn('projects', 'api_key_hash'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'api_key_version'))->toBeFalse()
        ->and($row->api_key)->toStartWith('pk_')
        ->and($row->api_key)->not->toBe('pk_test_before_rollback')
        ->and($row->api_secret_hash)->not->toBeEmpty();

    $migration->up();

    $this->withHeader('Authorization', 'Bearer pk_test_before_rollback')->postJson('/api/agent-auth')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$row->api_key)->postJson('/api/agent-auth')->assertOk();
});
