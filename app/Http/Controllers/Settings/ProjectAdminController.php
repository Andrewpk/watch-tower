<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\DeleteProjectData;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectAdminController extends Controller
{
    /**
     * How long, in hours, the replaced key may keep working after a rotation.
     * Zero revokes it at once.
     *
     * @var list<int>
     */
    public const ROTATION_GRACE_HOURS = [0, 1, 24, 168];

    /**
     * List the projects.
     *
     * A key flashed by `store()` or `rotateKey()` is handed to the page as
     * Inertia flash data, which, unlike a prop, is kept out of the browser's
     * history state, so Back or a session restore cannot show the key again.
     */
    public function index(Request $request): Response
    {
        $newApiKey = $this->pullNewApiKey($request);

        if ($newApiKey !== null) {
            Inertia::flash('new_api_key', $newApiKey);
        }

        $projects = Project::query()
            ->orderBy('name')
            ->withCount(['admins', 'errorGroups as open_issues_count' => function ($q) {
                $q->where('status', 'unresolved');
            }])
            ->get(['id', 'name', 'slug', 'description', 'api_key_prefix', 'api_key_last_used_at', 'previous_api_key_hash', 'previous_api_key_expires_at', 'sampling_rate', 'retention_days', 'created_at'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'description' => $project->description,
                'api_key_prefix' => $project->api_key_prefix,
                'api_key_last_used_at' => $project->api_key_last_used_at?->toISOString(),
                'previous_api_key_expires_at' => $project->hasActivePreviousApiKey()
                    ? $project->previous_api_key_expires_at->toISOString()
                    : null,
                'sampling_rate' => $project->sampling_rate,
                'retention_days' => $project->retention_days,
                'admins_count' => $project->admins_count,
                'open_issues_count' => $project->open_issues_count,
                'created_at' => $project->created_at?->toISOString(),
            ]);

        return Inertia::render('settings/projects/index', [
            'projects' => $projects,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('settings/projects/create', [
            'admins' => $this->adminOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'unique:projects,slug', 'regex:/^[a-z0-9-]+$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sampling_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'admin_ids' => ['array'],
            'admin_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $project = new Project([
            'organization_id' => Organization::query()->orderBy('created_at')->value('id'),
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'sampling_rate' => $validated['sampling_rate'],
            'retention_days' => $validated['retention_days'],
        ]);
        $apiKey = $project->issueApiKey();
        $project->save();

        $project->admins()->sync($validated['admin_ids'] ?? []);

        return redirect()->route('settings.projects.index')
            ->with('success', 'Project created.')
            ->with('new_api_key', $this->newApiKeyFlash($project, $apiKey));
    }

    /**
     * Replace the project's API key.
     *
     * The new key is flashed so the next page can show it once; only its hash
     * is stored. The old key stops working at once or, with a grace period,
     * when it ends.
     */
    public function rotateKey(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'grace_hours' => ['required', 'integer', Rule::in(self::ROTATION_GRACE_HOURS)],
        ]);

        $apiKey = $project->rotateApiKey($validated['grace_hours'] * 3600);

        return redirect()->route('settings.projects.index')
            ->with('success', "API key rotated for '{$project->name}'.")
            ->with('new_api_key', $this->newApiKeyFlash($project, $apiKey));
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('settings/projects/edit', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'description' => $project->description,
                'sampling_rate' => $project->sampling_rate,
                'retention_days' => $project->retention_days,
                'admin_ids' => $project->admins()->pluck('users.id')->all(),
            ],
            'admins' => $this->adminOptions(),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/', Rule::unique('projects', 'slug')->ignore($project->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sampling_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'admin_ids' => ['array'],
            'admin_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $project->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'sampling_rate' => $validated['sampling_rate'],
            'retention_days' => $validated['retention_days'],
        ]);

        $project->admins()->sync($validated['admin_ids'] ?? []);

        return redirect()->route('settings.projects.index')->with('success', 'Project updated.');
    }

    /**
     * Delete a project.
     *
     * The project is hidden from everyone straight away (admins detached,
     * `deleting_at` set, which drops it from the default `Project` scope and so
     * from lists, URLs and ingest). Its telemetry is then deleted in chunks
     * by a queued job, which finally removes the row.
     */
    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $confirmation = $request->input('confirmation');

        if ($confirmation !== $project->slug) {
            throw ValidationException::withMessages([
                'confirmation' => 'Type the project slug exactly to confirm deletion.',
            ]);
        }

        DB::transaction(function () use ($project) {
            $project->admins()->detach();
            $project->forceFill(['deleting_at' => now()])->save();
        });

        DeleteProjectData::dispatch($project->id);

        return redirect()->route('settings.projects.index')->with('success', "Project '{$project->name}' and all its data have been deleted.");
    }

    /**
     * The session flash that lets the next page show a new key once.
     *
     * The key is encrypted so that the plain text never sits in the session
     * store; `pullNewApiKey()` decrypts it for that one response.
     *
     * @return array{project: string, key: string}
     */
    private function newApiKeyFlash(Project $project, string $apiKey): array
    {
        return [
            'project' => $project->name,
            'key' => Crypt::encryptString($apiKey),
        ];
    }

    /**
     * Take the flashed key out of the session and decrypt it.
     *
     * Pulling it removes it from the session, so the plain text only exists
     * for the rest of this request.
     *
     * @return array{project: string, key: string}|null
     */
    private function pullNewApiKey(Request $request): ?array
    {
        $flashed = $request->session()->pull('new_api_key');

        if (! is_array($flashed) || ! isset($flashed['project'], $flashed['key'])) {
            return null;
        }

        try {
            return [
                'project' => $flashed['project'],
                'key' => Crypt::decryptString($flashed['key']),
            ];
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * @return array<int, array{id: int, name: string, email: string}>
     */
    private function adminOptions(): array
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])
            ->all();
    }
}
