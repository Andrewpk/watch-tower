<?php

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->freezeSecond();
    $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    Organization::factory()->create();
});

/**
 * @return array<string, mixed>
 */
function newProjectInput(): array
{
    return [
        'name' => 'Acme',
        'slug' => 'acme',
        'description' => 'Acme app',
        'sampling_rate' => 1,
        'retention_days' => 30,
    ];
}

/**
 * The key an Inertia response shows once, read from the page's flash data.
 *
 * @return array{project: string, key: string}|null
 */
function shownApiKey(TestResponse $response): ?array
{
    return $response->inertiaPage()['flash']['new_api_key'] ?? null;
}

/**
 * Whether any column of any project row holds the given text.
 */
function projectsTableContains(string $needle): bool
{
    return str_contains(json_encode(DB::table('projects')->get()), $needle);
}

it('shows the full key once after creating a project', function () {
    $this->actingAs($this->admin)
        ->post(route('settings.projects.store'), newProjectInput())
        ->assertRedirect(route('settings.projects.index'))
        ->assertSessionHas('success', 'Project created.');

    $response = $this->get(route('settings.projects.index'));
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->hasFlash('new_api_key.project', 'Acme')
        ->hasFlash('new_api_key.key'));
    $shown = shownApiKey($response);

    expect($shown['key'])->toStartWith('pk_')
        ->and(Project::findByApiKey($shown['key'])?->slug)->toBe('acme')
        ->and(projectsTableContains($shown['key']))->toBeFalse();

    $this->get(route('settings.projects.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('new_api_key'));
});

it('keeps the key out of the page props, which end up in browser history', function () {
    $this->actingAs($this->admin)->post(route('settings.projects.store'), newProjectInput());

    $response = $this->get(route('settings.projects.index'));
    $key = shownApiKey($response)['key'];

    expect(json_encode($response->inertiaPage()['props']))->not->toContain($key)
        ->and($response->inertiaProps('flash'))->not->toHaveKey('new_api_key');
});

it('keeps the plain-text key out of the session store', function () {
    $this->actingAs($this->admin)->post(route('settings.projects.store'), newProjectInput());

    $flashed = session('new_api_key');
    $key = Crypt::decryptString($flashed['key']);

    expect($flashed)->toHaveKeys(['project', 'key'])
        ->and(json_encode(session()->all()))->not->toContain($key);

    $response = $this->get(route('settings.projects.index'));

    expect(shownApiKey($response)['key'])->toBe($key)
        ->and(json_encode(session()->all()))->not->toContain($key)
        ->and(session('new_api_key'))->toBeNull()
        ->and(session(SessionKey::FLASH_DATA))->toBeNull();
});

it('shows nothing when the flashed key cannot be decrypted', function () {
    $this->actingAs($this->admin)
        ->withSession(['new_api_key' => ['project' => 'Acme', 'key' => 'not-encrypted']])
        ->get(route('settings.projects.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('new_api_key'));
});

it('lists projects with the key prefix and last use but never the full key', function () {
    $project = Project::factory()->withApiKey('pk_abcdef123456_full_secret')->create(['api_key_last_used_at' => now()->subHour()]);

    $response = $this->actingAs($this->admin)->get(route('settings.projects.index'));

    $row = $response->inertiaProps('projects')[0];

    expect($row['api_key_prefix'])->toBe('pk_abcdef1')
        ->and($row['api_key_last_used_at'])->toBe(now()->subHour()->toISOString())
        ->and($row['previous_api_key_expires_at'])->toBeNull()
        ->and($row)->not->toHaveKeys(['api_key', 'api_key_hash'])
        ->and($response->getContent())->not->toContain('pk_abcdef123456_full_secret')
        ->and($response->getContent())->not->toContain($project->api_key_hash);
});

it('rotates a key and shows the new one once', function () {
    $project = Project::factory()->withApiKey('pk_test_old')->create();

    $this->actingAs($this->admin)
        ->post(route('settings.projects.rotate-key', $project->slug), ['grace_hours' => 0])
        ->assertRedirect(route('settings.projects.index'))
        ->assertSessionHas('success', "API key rotated for '{$project->name}'.");

    $response = $this->get(route('settings.projects.index'));
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->hasFlash('new_api_key.project', $project->name)
        ->hasFlash('new_api_key.key'));
    $shown = shownApiKey($response);

    expect(Project::findByApiKey($shown['key'])?->id)->toBe($project->id)
        ->and(Project::findByApiKey('pk_test_old'))->toBeNull()
        ->and($project->fresh()->api_key_version)->toBe(2)
        ->and(projectsTableContains($shown['key']))->toBeFalse()
        ->and(json_encode($response->inertiaPage()['props']))->not->toContain($shown['key']);

    $this->get(route('settings.projects.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('new_api_key'));
});

it('keeps the old key working for the chosen grace period and reports when it ends', function (int $hours) {
    Queue::fake();
    $project = Project::factory()->withApiKey('pk_test_old')->create();

    $this->actingAs($this->admin)
        ->post(route('settings.projects.rotate-key', $project->slug), ['grace_hours' => $hours])
        ->assertSessionHasNoErrors();

    $this->withHeader('Authorization', 'Bearer pk_test_old')->postJson('/api/agent-auth')->assertOk();

    expect($this->get(route('settings.projects.index'))->inertiaProps('projects')[0]['previous_api_key_expires_at'])
        ->toBe(now()->addHours($hours)->toISOString());

    $this->travel($hours)->hours();
    $this->travel(1)->minutes();

    $this->withHeader('Authorization', 'Bearer pk_test_old')->postJson('/api/agent-auth')->assertUnauthorized();

    expect($this->get(route('settings.projects.index'))->inertiaProps('projects')[0]['previous_api_key_expires_at'])->toBeNull();
})->with([1, 24, 168]);

it('rejects a grace period that is not one of the offered choices', function (mixed $input) {
    $project = Project::factory()->withApiKey('pk_test_old')->create();

    $this->actingAs($this->admin)
        ->post(route('settings.projects.rotate-key', $project->slug), $input)
        ->assertSessionHasErrors('grace_hours');

    expect($project->fresh()->api_key_version)->toBe(1)
        ->and(Project::findByApiKey('pk_test_old')?->id)->toBe($project->id);
})->with([
    'missing' => [[]],
    'not offered' => [['grace_hours' => 5]],
    'negative' => [['grace_hours' => -1]],
    'not a number' => [['grace_hours' => 'forever']],
]);

it('lets only super admins rotate a key', function () {
    $project = Project::factory()->withApiKey('pk_test_old')->create();
    $projectAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $project->admins()->attach($projectAdmin->id);

    $this->actingAs($projectAdmin)
        ->post(route('settings.projects.rotate-key', $project->slug), ['grace_hours' => 0])
        ->assertForbidden();

    auth()->logout();

    $this->post(route('settings.projects.rotate-key', $project->slug), ['grace_hours' => 0])
        ->assertRedirect(route('login'));

    expect($project->fresh()->api_key_version)->toBe(1)
        ->and(Project::findByApiKey('pk_test_old')?->id)->toBe($project->id);
});

it('does not rotate the key of a project that is being deleted', function () {
    $project = Project::factory()->create();
    $project->forceFill(['deleting_at' => now()])->save();

    $this->actingAs($this->admin)
        ->post(route('settings.projects.rotate-key', $project->slug), ['grace_hours' => 0])
        ->assertNotFound();
});
