<?php

use App\Jobs\DeleteProjectData;
use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\IssueComment;
use App\Models\LogEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Models\User;
use App\Watch\Databases;
use App\Watch\EventStore;
use App\Watch\Fingerprinter;
use App\Watch\TelemetryPruner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every model class in app/Models.
 *
 * @return list<class-string<Model>>
 */
function appModelClasses(): array
{
    return collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path): string => 'App\\Models\\'.basename($path, '.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class))
        ->values()
        ->all();
}

/**
 * Run `$act` and report how deep the transactions were on the telemetry and default connections each time a model
 * event fired, relative to where they stood before. The test case already holds one transaction per connection.
 *
 * @param  class-string<Model>  $model
 * @return list<array{telemetry: int, default: int}>
 */
function transactionDepthsDuring(string $model, string $event, Closure $act): array
{
    $telemetryBefore = DB::connection(Databases::TELEMETRY)->transactionLevel();
    $defaultBefore = DB::connection()->transactionLevel();
    $depths = [];

    $model::$event(function () use (&$depths, $telemetryBefore, $defaultBefore): void {
        $depths[] = [
            'telemetry' => DB::connection(Databases::TELEMETRY)->transactionLevel() - $telemetryBefore,
            'default' => DB::connection()->transactionLevel() - $defaultBefore,
        ];
    });

    $act();

    return $depths;
}

describe('where the models live', function () {
    it('points every model at the connection that owns its table', function () {
        $classes = appModelClasses();

        expect($classes)->not->toBeEmpty();

        foreach ($classes as $class) {
            $model = new $class;

            expect($model->getConnectionName())->toBe(Databases::connectionFor($model->getTable()), $class);
        }
    });

    it('keeps each table on its own database and nowhere else', function () {
        $default = config('database.default');

        foreach (Databases::TELEMETRY_TABLES as $table) {
            expect(Schema::connection(Databases::TELEMETRY)->hasTable($table))->toBeTrue($table)
                ->and(Schema::connection($default)->hasTable($table))->toBeFalse($table)
                ->and(Schema::connection(Databases::LOGS)->hasTable($table))->toBeFalse($table);
        }

        foreach (Databases::LOG_TABLES as $table) {
            expect(Schema::connection(Databases::LOGS)->hasTable($table))->toBeTrue($table)
                ->and(Schema::connection($default)->hasTable($table))->toBeFalse($table)
                ->and(Schema::connection(Databases::TELEMETRY)->hasTable($table))->toBeFalse($table);
        }

        foreach (['users', 'projects', 'organizations', 'project_user', 'sessions', 'cache', 'maintenance_runs', 'jobs', 'job_batches', 'failed_jobs', 'migrations'] as $table) {
            expect(Schema::connection($default)->hasTable($table))->toBeTrue($table)
                ->and(Schema::connection(Databases::TELEMETRY)->hasTable($table))->toBeFalse($table)
                ->and(Schema::connection(Databases::LOGS)->hasTable($table))->toBeFalse($table);
        }
    });

    it('keeps the three databases apart in a test', function () {
        $connections = [config('database.default'), Databases::TELEMETRY, Databases::LOGS];

        foreach ($connections as $name) {
            expect(DB::connection($name)->transactionLevel())->toBeGreaterThan(0, $name);
        }

        expect(Databases::sameDatabase(Databases::TELEMETRY, Databases::LOGS))->toBeFalse()
            ->and(Databases::sameDatabase(config('database.default'), Databases::TELEMETRY))->toBeFalse();
    });

    it('reads the default connection when the model is used, not when its class loads', function () {
        $default = config('database.default');
        config()->set('database.default', Databases::LOGS);

        try {
            expect((new User)->getConnectionName())->toBe(Databases::LOGS)
                ->and((new Project)->getConnectionName())->toBe(Databases::LOGS)
                ->and((new Trace)->getConnectionName())->toBe(Databases::TELEMETRY);
        } finally {
            config()->set('database.default', $default);
        }
    });

    it('loads app models from the app database through telemetry relations', function () {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $group = ErrorGroup::factory()->create([
            'project_id' => $project->id,
            'assigned_to_user_id' => $user->id,
            'resolved_by_user_id' => $user->id,
        ]);
        $comment = IssueComment::create(['error_group_id' => $group->id, 'user_id' => $user->id, 'type' => 'comment', 'body' => 'Looking']);
        $trace = Trace::factory()->create(['project_id' => $project->id]);

        $loadedGroup = ErrorGroup::with(['assignedTo', 'resolvedBy', 'project'])->findOrFail($group->id);
        $loadedComment = IssueComment::with('user')->findOrFail($comment->id);

        expect($loadedGroup->assignedTo->is($user))->toBeTrue()
            ->and($loadedGroup->resolvedBy->is($user))->toBeTrue()
            ->and($loadedGroup->project->is($project))->toBeTrue()
            ->and($loadedComment->user->is($user))->toBeTrue()
            ->and($trace->project->is($project))->toBeTrue()
            ->and($project->traces()->count())->toBe(1)
            ->and($project->errorGroups()->count())->toBe(1);
    });
});

describe('deleting a user', function () {
    it('clears the user from issues and comments in the telemetry database', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->create();
        $mine = ErrorGroup::factory()->create(['project_id' => $project->id, 'resolved_by_user_id' => $user->id, 'assigned_to_user_id' => null]);
        $assigned = ErrorGroup::factory()->create(['project_id' => $project->id, 'resolved_by_user_id' => null, 'assigned_to_user_id' => $user->id]);
        $theirs = ErrorGroup::factory()->create(['project_id' => $project->id, 'resolved_by_user_id' => $other->id, 'assigned_to_user_id' => $other->id]);
        $comment = IssueComment::create(['error_group_id' => $mine->id, 'user_id' => $user->id, 'type' => 'comment', 'body' => 'Mine']);
        $otherComment = IssueComment::create(['error_group_id' => $mine->id, 'user_id' => $other->id, 'type' => 'comment', 'body' => 'Theirs']);
        $updatedAt = $mine->fresh()->updated_at;

        $user->delete();

        expect($mine->fresh()->resolved_by_user_id)->toBeNull()
            ->and($assigned->fresh()->assigned_to_user_id)->toBeNull()
            ->and($theirs->fresh()->resolved_by_user_id)->toBe($other->id)
            ->and($theirs->fresh()->assigned_to_user_id)->toBe($other->id)
            ->and($comment->fresh()->user_id)->toBeNull()
            ->and($comment->fresh()->body)->toBe('Mine')
            ->and($otherComment->fresh()->user_id)->toBe($other->id)
            ->and($mine->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    });

    it('does the same when a super admin removes an admin in Settings', function () {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $group = ErrorGroup::factory()->create(['project_id' => Project::factory()->create()->id, 'assigned_to_user_id' => $admin->id]);
        $comment = IssueComment::create(['error_group_id' => $group->id, 'user_id' => $admin->id, 'type' => 'comment', 'body' => 'Hi']);

        $this->actingAs($superAdmin)->delete(route('settings.admins.destroy', $admin))->assertRedirect();

        expect(User::find($admin->id))->toBeNull()
            ->and($group->fresh()->assigned_to_user_id)->toBeNull()
            ->and($comment->fresh()->user_id)->toBeNull();
    });
});

describe('Settings > Projects', function () {
    it('counts open issues per project without a query across databases', function () {
        $this->withoutVite();

        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $busy = Project::factory()->create(['name' => 'Busy']);
        $quiet = Project::factory()->create(['name' => 'Quiet']);
        $resolvedOnly = Project::factory()->create(['name' => 'Resolved only']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $busy->admins()->attach($admin->id);

        ErrorGroup::factory()->count(3)->create(['project_id' => $busy->id, 'status' => 'unresolved']);
        ErrorGroup::factory()->create(['project_id' => $busy->id, 'status' => 'resolved']);
        ErrorGroup::factory()->create(['project_id' => $resolvedOnly->id, 'status' => 'resolved']);

        $this->actingAs($superAdmin)
            ->get(route('settings.projects.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $counts = collect($page->toArray()['props']['projects'])
                    ->mapWithKeys(fn (array $row): array => [$row['name'] => [$row['open_issues_count'], $row['admins_count']]]);

                expect($counts->all())->toBe([
                    'Busy' => [3, 1],
                    'Quiet' => [0, 0],
                    'Resolved only' => [0, 0],
                ]);
            });
    });
});

describe('ingest transactions', function () {
    beforeEach(function () {
        $this->store = new EventStore(new Fingerprinter);
        $this->project = Project::factory()->create();
    });

    it('writes an exception and its issue inside one telemetry transaction', function () {
        $depths = transactionDepthsDuring(ErrorOccurrence::class, 'creating', fn () => $this->store->store($this->project, [
            'type' => 'exception',
            'data' => ['class' => 'RuntimeException', 'file' => 'app/A.php', 'line' => 5, 'message' => 'boom', 'occurred_at' => '2026-03-01T10:00:00Z'],
        ]));

        expect($depths)->toBe([['telemetry' => 1, 'default' => 0]]);
    });

    it('records a regression comment in the same telemetry transaction', function () {
        $depths = transactionDepthsDuring(IssueComment::class, 'creating', function (): void {
            foreach (['2026-03-01T10:00:00Z', '2026-03-05T12:30:00Z'] as $index => $occurredAt) {
                $this->store->store($this->project, [
                    'type' => 'exception',
                    'data' => ['class' => 'RuntimeException', 'file' => 'app/A.php', 'line' => 5, 'message' => 'boom', 'occurred_at' => $occurredAt],
                ]);

                if ($index === 0) {
                    ErrorGroup::query()->where('project_id', $this->project->id)->update(['status' => 'resolved', 'resolved_at' => '2026-03-02T09:00:00Z']);
                }
            }
        });

        expect($depths)->toBe([['telemetry' => 1, 'default' => 0]]);
    });

    it('writes a query and the trace time inside one telemetry transaction', function () {
        $trace = Trace::factory()->create(['project_id' => $this->project->id, 'correlation_id' => 'req-1', 'db_time_ms' => 0]);

        $depths = transactionDepthsDuring(TraceQuery::class, 'creating', fn () => $this->store->store($this->project, [
            'type' => 'query',
            'data' => ['request_id' => 'req-1', 'sql' => 'select 1', 'duration_ms' => 3],
        ]));

        expect($depths)->toBe([['telemetry' => 1, 'default' => 0]])
            ->and($trace->fresh()->db_time_ms)->toBe(3.0);
    });

    it('locks a repeated job attempt inside a telemetry transaction', function () {
        $attempt = ['type' => 'job-attempt', 'data' => ['job_id' => 'job-1', 'class' => 'App\\Jobs\\Example', 'status' => 'released', 'attempts' => 1, 'started_at' => '2026-03-01T10:00:00Z']];
        $this->store->store($this->project, $attempt);
        $attempt['data'] = [...$attempt['data'], 'status' => 'processed', 'attempts' => 2, 'started_at' => '2026-03-01T10:01:00Z'];

        $depths = transactionDepthsDuring(QueueJobRun::class, 'updating', fn () => $this->store->store($this->project, $attempt));

        expect($depths)->toBe([['telemetry' => 1, 'default' => 0]])
            ->and(QueueJobRun::sole()->status)->toBe('processed');
    });

    it('stores a log in the logs database next to its stub trace in telemetry', function () {
        $this->store->store($this->project, [
            'type' => 'log',
            'data' => ['request_id' => 'req-9', 'level' => 'info', 'message' => 'hello'],
        ]);

        expect(DB::connection(Databases::LOGS)->table('log_entries')->count())->toBe(1)
            ->and(DB::connection(Databases::TELEMETRY)->table('traces')->where('correlation_id', 'req-9')->count())->toBe(1)
            ->and(LogEntry::sole()->trace_id)->toBe(Trace::sole()->id);
    });
});

describe('pruning and deleting data', function () {
    it('empties telemetry, then logs, then error groups', function () {
        $order = TelemetryPruner::purgeOrder();

        expect($order)->toHaveCount(count(TelemetryPruner::TIME_COLUMNS) + 1)
            ->and(array_unique($order))->toHaveCount(count($order))
            ->and(end($order))->toBe('error_groups')
            ->and(array_search('log_entries', $order, true))->toBe(count($order) - 2)
            ->and(array_search('trace_queries', $order, true))->toBeLessThan(array_search('traces', $order, true))
            ->and(array_search('error_occurrences', $order, true))->toBeLessThan(array_search('traces', $order, true));

        foreach (array_slice($order, 0, -2) as $table) {
            expect(Databases::connectionFor($table))->toBe(Databases::TELEMETRY, $table);
        }
    });

    it('prunes rows on the telemetry and logs databases', function () {
        $project = Project::factory()->create(['retention_days' => 30]);
        seedTelemetry($project, now()->subDays(45));
        seedTelemetry($project, now());

        $this->artisan('watch:prune')->assertSuccessful();

        foreach (array_keys(TelemetryPruner::TIME_COLUMNS) as $table) {
            expect(DB::connection(Databases::connectionFor($table))->table($table)->where('project_id', $project->id)->count())->toBe(1, $table);
        }
    });

    it('purges comments with their issues and leaves other projects alone', function () {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $doomed = Project::factory()->create();
        $other = Project::factory()->create();

        foreach ([$doomed, $other] as $project) {
            seedTelemetry($project, now());
            $group = ErrorGroup::factory()->create(['project_id' => $project->id]);
            IssueComment::create(['error_group_id' => $group->id, 'user_id' => $superAdmin->id, 'type' => 'comment', 'body' => 'note']);
        }

        $doomed->forceFill(['deleting_at' => now()])->save();
        DeleteProjectData::dispatchSync($doomed->id);

        $groupIds = fn (Project $project) => ErrorGroup::query()->where('project_id', $project->id)->pluck('id');

        expect(Project::withoutGlobalScope('notDeleting')->find($doomed->id))->toBeNull()
            ->and($groupIds($doomed))->toBeEmpty()
            ->and(IssueComment::query()->count())->toBe(1)
            ->and(IssueComment::sole()->errorGroup->project_id)->toBe($other->id)
            ->and(LogEntry::query()->where('project_id', $doomed->id)->count())->toBe(0)
            ->and(LogEntry::query()->where('project_id', $other->id)->count())->toBe(1)
            ->and(Trace::query()->where('project_id', $doomed->id)->count())->toBe(0)
            ->and(Trace::query()->where('project_id', $other->id)->count())->toBe(1);
    });

    it('removes rows that ingest wrote while the project was being deleted', function () {
        $project = Project::factory()->create();
        $project->forceFill(['deleting_at' => now()])->save();

        Event::listen('eloquent.deleted: '.Project::class, function (Project $deleted): void {
            Trace::factory()->create(['project_id' => $deleted->id]);
            ErrorGroup::factory()->create(['project_id' => $deleted->id]);
        });

        DeleteProjectData::dispatchSync($project->id);

        expect(Project::withoutGlobalScope('notDeleting')->find($project->id))->toBeNull()
            ->and(Trace::query()->where('project_id', $project->id)->count())->toBe(0)
            ->and(ErrorGroup::query()->where('project_id', $project->id)->count())->toBe(0);
    });
});

describe('queries that stay on the app database', function () {
    it('reads the organization of a project and its admins from the app database', function () {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $project->admins()->attach($admin->id);

        $trace = Trace::factory()->create(['project_id' => $project->id]);

        expect($trace->project->organization->is($organization))->toBeTrue()
            ->and($trace->project->admins->modelKeys())->toBe([$admin->id])
            ->and($organization->users->modelKeys())->toBe([$admin->id]);
    });
});
