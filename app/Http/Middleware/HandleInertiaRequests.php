<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $currentProject = $request->route('project');
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar_url' => $user->avatar_path
                        ? asset('storage/'.$user->avatar_path)
                        : null,
                    'is_super_admin' => $user->isSuperAdmin(),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'new_api_key' => fn () => $this->newApiKey($request),
            ],
            'currentProject' => $currentProject instanceof Project ? [
                'id' => $currentProject->id,
                'slug' => $currentProject->slug,
                'name' => $currentProject->name,
                'environment' => $currentProject->organization?->plan ?? 'production',
                'open_issues_count' => $currentProject->errorGroups()->where('status', 'unresolved')->count(),
            ] : null,
            'projects' => function () use ($user) {
                if (! $user) {
                    return [];
                }

                $query = $user->isSuperAdmin()
                    ? Project::query()
                    : $user->projects()->getQuery();

                return $query
                    ->orderBy('name')
                    ->get(['projects.id', 'projects.slug', 'projects.name'])
                    ->map(fn (Project $project) => [
                        'id' => $project->id,
                        'slug' => $project->slug,
                        'name' => $project->name,
                    ])
                    ->all();
            },
        ];
    }

    /**
     * The API key flashed by project creation or key rotation, for display once.
     *
     * @return array{project: string, key: string}|null
     */
    private function newApiKey(Request $request): ?array
    {
        $flashed = $request->session()->get('new_api_key');

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
}
