<?php

use App\Http\Controllers\Project\JobsController;
use App\Models\CommandRun;
use App\Models\ErrorGroup;
use App\Models\IssueComment;
use App\Models\MailSend;
use App\Models\NotificationSend;
use App\Models\OutgoingRequest;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\ScheduledTaskRun;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| A query that joins or nests tables from two databases only fails on a page that runs it, so this walks every
| project page and every settings page over a full demo data set. The app, telemetry and log databases are separate
| in-memory databases here, so a cross-database query raises "no such table".
*/

/**
 * The URL of every page a project has, with real identifiers for the detail pages.
 *
 * @return array<string, string>
 */
function projectPageUrls(Project $project): array
{
    $slug = $project->slug;
    $parameters = fn (array $extra = []): array => ['project' => $slug, ...$extra];

    $firstIssue = ErrorGroup::query()->where('project_id', $project->id)->orderBy('display_number')->firstOrFail();

    return [
        'dashboard' => route('projects.dashboard', $parameters()),
        'requests' => route('projects.requests.index', $parameters()),
        'exceptions' => route('projects.exceptions.index', $parameters()),
        'exception' => route('projects.exceptions.show', $parameters(['exception' => $firstIssue->id])),
        'issues' => route('projects.issues.index', $parameters()),
        'issues, all statuses' => route('projects.issues.index', $parameters() + ['status' => 'all', 'search' => 'e', 'sort' => 'users']),
        'issue' => route('projects.issues.show', $parameters(['issue' => $firstIssue->display_number])),
        'jobs' => route('projects.jobs.index', $parameters()),
        'job' => route('projects.jobs.show', $parameters(['job' => JobsController::encodeJobClass((string) QueueJobRun::query()->where('project_id', $project->id)->value('job_class'))])),
        'commands' => route('projects.commands.index', $parameters()),
        'command' => route('projects.commands.show', $parameters(['command' => (string) CommandRun::query()->where('project_id', $project->id)->value('command')])),
        'scheduled tasks' => route('projects.scheduled-tasks.index', $parameters()),
        'scheduled task' => route('projects.scheduled-tasks.show', $parameters(['task' => (string) ScheduledTaskRun::query()->where('project_id', $project->id)->value('task_hash')])),
        'queries' => route('projects.queries.index', $parameters()),
        'query' => route('projects.queries.show', $parameters(['query' => sha1((string) TraceQuery::query()->where('project_id', $project->id)->value('sql'))])),
        'mail' => route('projects.mail.index', $parameters()),
        'mailable' => route('projects.mail.show', $parameters(['mail' => sha1((string) MailSend::query()->where('project_id', $project->id)->value('mailable_class'))])),
        'notifications' => route('projects.notifications.index', $parameters()),
        'notification' => route('projects.notifications.show', $parameters(['notification' => sha1((string) NotificationSend::query()->where('project_id', $project->id)->value('notification_class'))])),
        'cache' => route('projects.cache.index', $parameters()),
        'http client' => route('projects.http-client.index', $parameters()),
        'http client domain' => route('projects.http-client.show', $parameters(['domain' => sha1((string) OutgoingRequest::query()->where('project_id', $project->id)->value('host'))])),
        'logs' => route('projects.logs.index', $parameters()),
        'logs, filtered' => route('projects.logs.index', $parameters() + ['level' => 'error', 'search' => 'a']),
        'users' => route('projects.users.index', $parameters()),
        'user' => route('projects.users.show', $parameters(['user' => (string) Trace::query()->where('project_id', $project->id)->whereNotNull('user_identifier')->value('user_identifier')])),
    ];
}

/**
 * Assert that every URL answers 200 for the signed-in user.
 *
 * @param  array<string, string>  $urls
 */
function expectEveryPageToLoad(mixed $test, array $urls): void
{
    foreach ($urls as $name => $url) {
        $response = $test->get($url);

        expect($response->status())->toBe(200, "{$name} ({$url}) returned {$response->status()}");
    }
}

/*
| Seeding the demo data is the slow, memory-hungry part, so it happens once and one test walks everything.
*/
it('serves every project and settings page over a full demo data set', function () {
    $this->withoutVite();
    $this->seed(DemoDataSeeder::class);

    $project = Project::query()->where('slug', 'acme-app')->firstOrFail();
    $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'organization_id' => $project->organization_id]);
    $project->admins()->attach($admin->id);

    $group = ErrorGroup::query()->where('project_id', $project->id)->orderBy('display_number')->firstOrFail();
    $group->forceFill(['assigned_to_user_id' => $admin->id, 'resolved_by_user_id' => $superAdmin->id])->save();
    IssueComment::create(['error_group_id' => $group->id, 'user_id' => $admin->id, 'type' => 'comment', 'body' => 'On it.']);

    $projectPages = projectPageUrls($project);

    $this->actingAs($superAdmin);
    expectEveryPageToLoad($this, $projectPages);
    expectEveryPageToLoad($this, [
        'profile' => route('settings.profile.show'),
        'admins' => route('settings.admins.index'),
        'new admin' => route('settings.admins.create'),
        'edit admin' => route('settings.admins.edit', $admin),
        'projects' => route('settings.projects.index'),
        'new project' => route('settings.projects.create'),
        'edit project' => route('settings.projects.edit', $project->slug),
    ]);
    $this->get(route('home'))->assertRedirect();

    $this->get($projectPages['issue'])
        ->assertInertia(fn ($page) => $page
            ->where('issue.assigned_to.id', $admin->id)
            ->where('issue.comments.0.user.id', $admin->id));

    $this->actingAs($admin);
    expectEveryPageToLoad($this, $projectPages);

    $visited = collect($projectPages)
        ->map(fn (string $url): ?string => app('router')->getRoutes()->match(Request::create($url))->getName())
        ->unique()
        ->sort()
        ->values()
        ->all();

    $existing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('GET', $route->methods(), true) && str_starts_with((string) $route->getName(), 'projects.') && $route->getName() !== 'projects.')
        ->map(fn ($route): string => $route->getName())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($visited)->toBe($existing);
});
