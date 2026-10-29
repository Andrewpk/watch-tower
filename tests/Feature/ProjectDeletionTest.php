<?php

use App\Jobs\DeleteProjectData;
use App\Models\ErrorGroup;
use App\Models\Project;
use App\Models\User;
use App\Watch\TelemetryPruner;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    $this->project = Project::factory()->create();
    $this->project->admins()->attach($this->admin->id);
});

function deleteProjectViaAdmin(mixed $test, Project $project, ?string $confirmation): mixed
{
    return $test->actingAs($test->admin)->delete(
        route('settings.projects.destroy', $project->slug),
        ['confirmation' => $confirmation],
    );
}

it('hides the project at once and queues the deletion job', function () {
    Bus::fake();

    deleteProjectViaAdmin($this, $this->project, $this->project->slug)
        ->assertRedirect(route('settings.projects.index'))
        ->assertSessionHas('success', "Project '{$this->project->name}' and all its data have been deleted.");

    Bus::assertDispatched(DeleteProjectData::class, fn (DeleteProjectData $job) => $job->projectId === $this->project->id);

    expect(Project::find($this->project->id))->toBeNull()
        ->and(Project::where('slug', $this->project->slug)->exists())->toBeFalse()
        ->and($this->admin->projects()->count())->toBe(0)
        ->and(DB::table('project_user')->where('project_id', $this->project->id)->count())->toBe(0)
        ->and(Project::withoutGlobalScope('notDeleting')->find($this->project->id)->deleting_at)->not->toBeNull();
});

it('does not delete anything without the exact slug confirmation', function () {
    Bus::fake();

    deleteProjectViaAdmin($this, $this->project, 'wrong-slug')->assertSessionHasErrors('confirmation');

    Bus::assertNotDispatched(DeleteProjectData::class);

    expect(Project::find($this->project->id))->not->toBeNull()
        ->and($this->admin->projects()->count())->toBe(1);
});

it('removes the project and all of its telemetry when the job runs', function () {
    $other = Project::factory()->create();
    seedTelemetry($this->project, now()->subDays(2));
    seedTelemetry($this->project, now());
    seedTelemetry($other, now());

    deleteProjectViaAdmin($this, $this->project, $this->project->slug)->assertRedirect();

    expect(DB::table('projects')->where('id', $this->project->id)->exists())->toBeFalse()
        ->and(ErrorGroup::where('project_id', $this->project->id)->count())->toBe(0);

    foreach (array_keys(TelemetryPruner::TIME_COLUMNS) as $table) {
        expect(DB::table($table)->where('project_id', $this->project->id)->count())->toBe(0, $table)
            ->and(DB::table($table)->where('project_id', $other->id)->count())->toBe(1, $table);
    }
});

it('deletes in chunks smaller than the data set', function () {
    seedTelemetry($this->project, now());
    seedTelemetry($this->project, now());
    seedTelemetry($this->project, now());
    $this->project->forceFill(['deleting_at' => now()])->save();

    app()->instance(TelemetryPruner::class, new TelemetryPruner(2));

    DeleteProjectData::dispatchSync($this->project->id);

    expect(DB::table('projects')->where('id', $this->project->id)->exists())->toBeFalse()
        ->and(DB::table('traces')->count())->toBe(0)
        ->and(DB::table('error_groups')->count())->toBe(0);
});

it('never deletes a project that is not marked as deleting', function () {
    seedTelemetry($this->project, now());

    DeleteProjectData::dispatchSync($this->project->id);

    expect(Project::find($this->project->id))->not->toBeNull()
        ->and(DB::table('traces')->where('project_id', $this->project->id)->count())->toBe(1);
});

it('does nothing when the project is already gone', function () {
    DeleteProjectData::dispatchSync((string) Str::uuid());

    expect(Project::count())->toBe(1);
});

/**
 * Rows the project still owns across every purged table, error groups included.
 */
function remainingProjectRows(Project $project): int
{
    return collect([...array_keys(TelemetryPruner::TIME_COLUMNS), 'error_groups'])
        ->sum(fn (string $table) => DB::table($table)->where('project_id', $project->id)->count());
}

it('stops a run after its chunk budget and dispatches itself again', function () {
    seedTelemetry($this->project, now());
    seedTelemetry($this->project, now());
    $this->project->forceFill(['deleting_at' => now()])->save();
    $before = remainingProjectRows($this->project);

    Bus::fake();

    (new DeleteProjectData($this->project->id, maxChunks: 1))->withFakeQueueInteractions()->handle(new TelemetryPruner(1));

    expect(remainingProjectRows($this->project))->toBe($before - 1)
        ->and(DB::table('projects')->where('id', $this->project->id)->exists())->toBeTrue();

    Bus::assertDispatched(
        DeleteProjectData::class,
        fn (DeleteProjectData $job) => $job->projectId === $this->project->id && $job->maxChunks === 1,
    );
});

it('removes the project and all its telemetry after repeated time-boxed runs', function () {
    seedTelemetry($this->project, now());
    seedTelemetry($this->project, now());
    seedTelemetry($this->project, now());
    $this->project->forceFill(['deleting_at' => now()])->save();

    Bus::fake();

    $pruner = new TelemetryPruner(1);
    $job = new DeleteProjectData($this->project->id, maxChunks: 1);
    $runs = 0;

    while ($job !== null && $runs < 200) {
        $job->withFakeQueueInteractions()->handle($pruner);
        $runs++;
        $job = Bus::dispatched(DeleteProjectData::class)->get($runs - 1);
    }

    expect($runs)->toBeGreaterThan(1)
        ->and($job)->toBeNull()
        ->and(Bus::dispatched(DeleteProjectData::class))->toHaveCount($runs - 1)
        ->and(DB::table('projects')->where('id', $this->project->id)->exists())->toBeFalse()
        ->and(remainingProjectRows($this->project))->toBe(0);
});

it('purges to completion in a single run when dispatched on the sync queue', function () {
    seedTelemetry($this->project, now());
    seedTelemetry($this->project, now());
    seedTelemetry($this->project, now());
    $this->project->forceFill(['deleting_at' => now()])->save();

    config(['queue.default' => 'sync']);
    app()->instance(TelemetryPruner::class, new TelemetryPruner(1));

    DeleteProjectData::dispatch($this->project->id, maxChunks: 1);

    expect(DB::table('projects')->where('id', $this->project->id)->exists())->toBeFalse()
        ->and(remainingProjectRows($this->project))->toBe(0);
});

it('purges to completion in a single run when handled with no queue job attached', function () {
    seedTelemetry($this->project, now());
    seedTelemetry($this->project, now());
    $this->project->forceFill(['deleting_at' => now()])->save();

    Bus::fake();

    (new DeleteProjectData($this->project->id, maxChunks: 1))->handle(new TelemetryPruner(1));

    Bus::assertNotDispatched(DeleteProjectData::class);

    expect(DB::table('projects')->where('id', $this->project->id)->exists())->toBeFalse()
        ->and(remainingProjectRows($this->project))->toBe(0);
});

it('purges within a deadline and reports whether it finished', function () {
    seedTelemetry($this->project, now());

    $pruner = new TelemetryPruner(1);

    $late = $pruner->purge($this->project->id, deadline: microtime(true) - 1);

    expect($late['finished'])->toBeFalse()
        ->and(array_sum($late['deleted']))->toBe(0)
        ->and(remainingProjectRows($this->project))->toBe(14 + 1);

    $unlimited = $pruner->purge($this->project->id, deadline: microtime(true) + 60);

    expect($unlimited['finished'])->toBeTrue()
        ->and(array_sum($unlimited['deleted']))->toBe(15)
        ->and(remainingProjectRows($this->project))->toBe(0);
});

it('does not let two deletions of one project overlap', function () {
    $middleware = (new DeleteProjectData($this->project->id))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe($this->project->id)
        ->and($middleware[0]->releaseAfter)->toBe(30)
        ->and($middleware[0]->expiresAfter)->toBe(120);
});

it('times out before the queue hands the job to another worker', function () {
    $retryAfters = collect(['database', 'redis', 'beanstalkd'])
        ->map(fn (string $connection) => config("queue.connections.{$connection}.retry_after"));

    expect((new DeleteProjectData($this->project->id))->timeout)->toBeLessThan($retryAfters->min());
});
