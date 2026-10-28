<?php

use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\Project;
use App\Models\User;
use App\Watch\EventStore;
use App\Watch\Fingerprinter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->store = new EventStore(new Fingerprinter);
    $this->project = Project::factory()->create();
    $this->user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

    $this->ingest = function (string $occurredAt): void {
        $this->store->store($this->project, [
            'type' => 'exception',
            'data' => [
                'class' => 'RuntimeException',
                'file' => 'app/A.php',
                'line' => 5,
                'message' => 'boom',
                'occurred_at' => $occurredAt,
            ],
        ]);
    };
});

it('reopens a resolved issue when the error recurs and records a regression on the timeline', function () {
    ($this->ingest)('2026-03-01T10:00:00Z');

    $group = ErrorGroup::where('project_id', $this->project->id)->sole();
    $group->forceFill([
        'status' => 'resolved',
        'resolved_at' => '2026-03-02T09:00:00Z',
        'resolved_by_user_id' => $this->user->id,
    ])->save();

    ($this->ingest)('2026-03-05T12:30:00Z');

    $group->refresh();

    expect(ErrorGroup::where('project_id', $this->project->id)->count())->toBe(1)
        ->and($group->status)->toBe('unresolved')
        ->and($group->resolved_at)->toBeNull()
        ->and($group->resolved_by_user_id)->toBeNull()
        ->and($group->total_count)->toBe(2)
        ->and($group->last_occurrence_at->toIso8601String())->toBe('2026-03-05T12:30:00+00:00')
        ->and(ErrorOccurrence::where('error_group_id', $group->id)->count())->toBe(2);

    $entry = $group->comments()->sole();

    expect($entry->type)->toBe('regression')
        ->and($entry->user_id)->toBeNull()
        ->and($entry->meta['previous_status'])->toBe('resolved')
        ->and($entry->meta['resolved_by_user_id'])->toBe($this->user->id);
});

it('does not add timeline entries when an unresolved issue recurs', function () {
    ($this->ingest)('2026-03-01T10:00:00Z');
    ($this->ingest)('2026-03-02T10:00:00Z');

    $group = ErrorGroup::where('project_id', $this->project->id)->sole();

    expect($group->status)->toBe('unresolved')
        ->and($group->total_count)->toBe(2)
        ->and($group->comments()->count())->toBe(0);
});

it('only records one regression until the issue is resolved again', function () {
    ($this->ingest)('2026-03-01T10:00:00Z');

    $group = ErrorGroup::where('project_id', $this->project->id)->sole();
    $group->forceFill(['status' => 'resolved', 'resolved_at' => '2026-03-01T12:00:00Z'])->save();

    ($this->ingest)('2026-03-02T10:00:00Z');
    ($this->ingest)('2026-03-03T10:00:00Z');

    expect($group->refresh()->total_count)->toBe(3)
        ->and($group->comments()->where('type', 'regression')->count())->toBe(1);
});

it('counts an occurrence that predates the resolution without reopening the issue', function () {
    ($this->ingest)('2026-03-01T10:00:00Z');

    $group = ErrorGroup::where('project_id', $this->project->id)->sole();
    $group->forceFill([
        'status' => 'resolved',
        'resolved_at' => '2026-03-02T09:00:00Z',
        'resolved_by_user_id' => $this->user->id,
    ])->save();

    ($this->ingest)('2026-03-02T08:59:59Z');

    $group->refresh();

    expect($group->status)->toBe('resolved')
        ->and($group->resolved_at->toIso8601String())->toBe('2026-03-02T09:00:00+00:00')
        ->and($group->resolved_by_user_id)->toBe($this->user->id)
        ->and($group->total_count)->toBe(2)
        ->and($group->last_occurrence_at->toIso8601String())->toBe('2026-03-02T08:59:59+00:00')
        ->and($group->comments()->count())->toBe(0);

    ($this->ingest)('2026-03-02T09:00:00Z');

    expect($group->refresh()->status)->toBe('resolved')
        ->and($group->total_count)->toBe(3)
        ->and($group->comments()->count())->toBe(0);

    ($this->ingest)('2026-03-02T09:00:01Z');

    expect($group->refresh()->status)->toBe('unresolved')
        ->and($group->total_count)->toBe(4)
        ->and($group->comments()->where('type', 'regression')->count())->toBe(1);
});

it('keeps an ignored issue ignored but still counts the occurrence', function () {
    ($this->ingest)('2026-03-01T10:00:00Z');

    $group = ErrorGroup::where('project_id', $this->project->id)->sole();
    $group->forceFill(['status' => 'ignored'])->save();

    ($this->ingest)('2026-03-04T10:00:00Z');

    $group->refresh();

    expect($group->status)->toBe('ignored')
        ->and($group->total_count)->toBe(2)
        ->and($group->last_occurrence_at->toIso8601String())->toBe('2026-03-04T10:00:00+00:00')
        ->and($group->comments()->count())->toBe(0);
});

it('does not move last_occurrence_at backwards for an out-of-order older event', function () {
    ($this->ingest)('2026-03-10T10:00:00Z');
    ($this->ingest)('2026-03-05T10:00:00Z');

    $group = ErrorGroup::where('project_id', $this->project->id)->sole();

    expect($group->total_count)->toBe(2)
        ->and($group->last_occurrence_at->toIso8601String())->toBe('2026-03-10T10:00:00+00:00');

    ($this->ingest)('2026-03-12T10:00:00Z');

    expect($group->refresh()->last_occurrence_at->toIso8601String())->toBe('2026-03-12T10:00:00+00:00');
});

it('shows the regression on the issue page as a system entry', function () {
    $this->withoutVite();

    ($this->ingest)('2026-03-01T10:00:00Z');

    $group = ErrorGroup::where('project_id', $this->project->id)->sole();
    $group->forceFill(['status' => 'resolved', 'resolved_at' => '2026-03-02T09:00:00Z', 'resolved_by_user_id' => $this->user->id])->save();
    $group->comments()->create(['user_id' => $this->user->id, 'type' => 'comment', 'body' => 'Shipped a fix.']);

    ($this->ingest)('2026-03-05T10:00:00Z');

    $this->actingAs($this->user)
        ->get(route('projects.issues.show', [
            'project' => $this->project->slug,
            'issue' => $group->display_number,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/issues/show')
            ->where('issue.status', 'unresolved')
            ->where('issue.total_count', 2)
            ->has('issue.comments', 2)
            ->where('issue.comments.0.type', 'comment')
            ->where('issue.comments.0.user.id', $this->user->id)
            ->where('issue.comments.1.type', 'regression')
            ->where('issue.comments.1.user', null)
        );
});

it('lists a reopened issue under the open tab and not under resolved', function () {
    $this->withoutVite();

    ($this->ingest)('2026-03-01T10:00:00Z');

    $group = ErrorGroup::where('project_id', $this->project->id)->sole();
    $group->forceFill(['status' => 'resolved', 'resolved_at' => '2026-03-02T09:00:00Z'])->save();

    ($this->ingest)('2026-03-05T10:00:00Z');

    $this->actingAs($this->user)
        ->get(route('projects.issues.index', ['project' => $this->project->slug, 'status' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page->has('groups.data', 0));

    $this->actingAs($this->user)
        ->get(route('projects.issues.index', ['project' => $this->project->slug, 'status' => 'open']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('groups.data', 1)
            ->where('groups.data.0.id', $group->id)
        );
});
