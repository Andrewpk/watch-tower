<?php

use App\Jobs\DeleteProjectData;
use App\Models\ErrorGroup;
use App\Models\Project;
use App\Models\User;
use App\Watch\TelemetryPruner;
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
