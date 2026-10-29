<?php

use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\Project;
use App\Models\Trace;
use App\Models\User;
use App\Watch\EventStore;
use App\Watch\Fingerprinter;
use App\Watch\Stats\ExceptionStats;
use App\Watch\Stats\TimeRange;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->token = ingestToken($this->project->id);
    $this->base = CarbonImmutable::parse('2026-07-01 10:15:00', 'UTC')->getTimestamp() + 0.123456;
});

function nightwatchExceptionIngestRecord(float $timestamp, array $overrides = []): array
{
    return array_merge([
        'v' => 3,
        't' => 'exception',
        'timestamp' => $timestamp,
        'deploy' => 'v1.4.2',
        'server' => 'web-1',
        '_group' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'trace_id' => '',
        'user' => '',
        'class' => 'RuntimeException',
        'file' => 'app/Example.php',
        'message' => 'Something broke',
        'line' => 12,
        'trace' => '[]',
        'handled' => true,
        'php_version' => '8.4.1',
        'laravel_version' => '12.35.1',
    ], $overrides);
}

function nightwatchExceptionGroup(Project $project): ErrorGroup
{
    return ErrorGroup::where('project_id', $project->id)->sole();
}

it('stores the handled flag, versions, hostname and release on the occurrence and the group', function () {
    postNightwatchRecords([nightwatchExceptionIngestRecord($this->base)], $this->token)->assertOk();

    $occurrence = ErrorOccurrence::where('project_id', $this->project->id)->sole();
    $group = nightwatchExceptionGroup($this->project);

    expect($occurrence->is_handled)->toBeTrue()
        ->and($occurrence->language_version)->toBe('8.4.1')
        ->and($occurrence->framework_version)->toBe('12.35.1')
        ->and($occurrence->hostname)->toBe('web-1')
        ->and($occurrence->release_version)->toBe('v1.4.2')
        ->and($occurrence->environment)->toBeNull()
        ->and($group->is_handled)->toBeTrue()
        ->and($group->language_version)->toBe('8.4.1')
        ->and($group->framework_version)->toBe('12.35.1');
});

it('lets the newest occurrence decide the handled flag and versions of the group', function () {
    postNightwatchRecords([nightwatchExceptionIngestRecord($this->base)], $this->token)->assertOk();

    postNightwatchRecords([nightwatchExceptionIngestRecord($this->base + 60, [
        'handled' => false,
        'php_version' => '8.4.2',
        'laravel_version' => '12.36.0',
    ])], $this->token)->assertOk();

    $group = nightwatchExceptionGroup($this->project);

    expect($group->total_count)->toBe(2)
        ->and($group->is_handled)->toBeFalse()
        ->and($group->language_version)->toBe('8.4.2')
        ->and($group->framework_version)->toBe('12.36.0');

    // An older occurrence arriving late changes nothing on the group.
    postNightwatchRecords([nightwatchExceptionIngestRecord($this->base - 3600, [
        'handled' => true,
        'php_version' => '8.3.0',
        'laravel_version' => '11.0.0',
    ])], $this->token)->assertOk();

    $group->refresh();

    expect($group->total_count)->toBe(3)
        ->and($group->is_handled)->toBeFalse()
        ->and($group->language_version)->toBe('8.4.2')
        ->and($group->framework_version)->toBe('12.36.0');

    // A newer handled occurrence flips the group back.
    postNightwatchRecords([nightwatchExceptionIngestRecord($this->base + 120, [
        'handled' => true,
        'php_version' => '8.4.0',
        'laravel_version' => '12.1.0',
    ])], $this->token)->assertOk();

    $group->refresh();

    expect($group->total_count)->toBe(4)
        ->and($group->is_handled)->toBeTrue()
        ->and($group->language_version)->toBe('8.4.0')
        ->and($group->framework_version)->toBe('12.1.0');
});

it('keeps the stored versions of the group when a newer occurrence reports none', function () {
    postNightwatchRecords([nightwatchExceptionIngestRecord($this->base)], $this->token)->assertOk();
    postNightwatchRecords([nightwatchExceptionIngestRecord($this->base + 60, [
        'php_version' => null,
        'laravel_version' => null,
    ])], $this->token)->assertOk();

    $group = nightwatchExceptionGroup($this->project);

    expect($group->language_version)->toBe('8.4.1')
        ->and($group->framework_version)->toBe('12.35.1');
});

it('counts handled and unhandled occurrences separately in the summary', function () {
    postNightwatchRecords([
        nightwatchExceptionIngestRecord($this->base, ['handled' => true]),
        nightwatchExceptionIngestRecord($this->base + 1, ['handled' => false]),
    ], $this->token)->assertOk();

    $summary = app(ExceptionStats::class)->summary(
        $this->project,
        new TimeRange(
            CarbonImmutable::createFromTimestamp($this->base - 60),
            CarbonImmutable::createFromTimestamp($this->base + 60),
            'custom',
        ),
    );

    expect($summary['totals'])->toBe(['total' => 2, 'handled' => 1, 'unhandled' => 1]);
});

it('lists versions newest first, servers from occurrences and the oldest release', function () {
    // Time order, versions and releases are all out of step with their alphabetical order and with the order the
    // records are posted in, so only a real "newest first" / "oldest first" ordering gives these results.
    postNightwatchRecords([
        nightwatchExceptionIngestRecord($this->base + 120, ['deploy' => 'v3.0.0', 'server' => 'web-3', 'php_version' => '8.4.1', 'laravel_version' => '12.35.1']),
        nightwatchExceptionIngestRecord($this->base, ['deploy' => 'v2.0.0', 'server' => 'web-1', 'php_version' => '8.4.2', 'laravel_version' => '12.36.0']),
        nightwatchExceptionIngestRecord($this->base + 60, ['deploy' => 'v10.0.0', 'server' => 'web-2', 'php_version' => '8.4.0', 'laravel_version' => '12.1.0']),
    ], $this->token)->assertOk();

    $detail = app(ExceptionStats::class)->detail($this->project, nightwatchExceptionGroup($this->project));

    expect($detail['php_versions'])->toBe(['8.4.1', '8.4.0', '8.4.2'])
        ->and($detail['laravel_versions'])->toBe(['12.35.1', '12.1.0', '12.36.0'])
        ->and($detail['servers'])->toEqualCanonicalizing(['web-1', 'web-2', 'web-3'])
        ->and($detail['first_release'])->toBe('v2.0.0');
});

it('merges the hostname of the request with the hostname of the occurrence', function () {
    Trace::factory()->create([
        'project_id' => $this->project->id,
        'correlation_id' => 'trace-1',
        'hostname' => 'web-9',
    ]);

    postNightwatchRecords([
        nightwatchExceptionIngestRecord($this->base, ['trace_id' => 'trace-1', 'server' => 'web-1']),
    ], $this->token)->assertOk();

    $detail = app(ExceptionStats::class)->detail($this->project, nightwatchExceptionGroup($this->project));

    expect($detail['servers'])->toEqualCanonicalizing(['web-1', 'web-9']);
});

it('falls back to the versions of the group and has no release for older occurrences', function () {
    $group = ErrorGroup::factory()->create([
        'project_id' => $this->project->id,
        'language_version' => '8.3.0',
        'framework_version' => '11.0.0',
    ]);
    ErrorOccurrence::factory()->create([
        'project_id' => $this->project->id,
        'error_group_id' => $group->id,
        'release_version' => null,
        'language_version' => null,
        'framework_version' => null,
        'hostname' => null,
    ]);

    $detail = app(ExceptionStats::class)->detail($this->project, $group);

    expect($detail['php_versions'])->toBe(['8.3.0'])
        ->and($detail['laravel_versions'])->toBe(['11.0.0'])
        ->and($detail['first_release'])->toBeNull();
});

it('shows the handled flag, versions and first release on the exception page', function () {
    $this->withoutVite();

    postNightwatchRecords([
        nightwatchExceptionIngestRecord($this->base + 60, ['deploy' => 'v10.0.0', 'php_version' => '8.4.1', 'laravel_version' => '12.35.1']),
        nightwatchExceptionIngestRecord($this->base, ['deploy' => 'v2.0.0', 'php_version' => '8.4.2', 'laravel_version' => '12.36.0']),
    ], $this->token)->assertOk();

    $group = nightwatchExceptionGroup($this->project);
    $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

    $this->actingAs($admin)
        ->get(route('projects.exceptions.show', ['project' => $this->project->slug, 'exception' => $group->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/exceptions/show')
            ->where('exception.is_handled', true)
            ->where('exception.first_release', 'v2.0.0')
            ->where('exception.php_versions.0', '8.4.1')
            ->where('exception.laravel_versions.0', '12.35.1')
        );
});

it('shows the handled flag of the filter on the exceptions page rows', function () {
    $this->withoutVite();

    postNightwatchRecords([
        nightwatchExceptionIngestRecord(time() - 120, ['handled' => false]),
        nightwatchExceptionIngestRecord(time() - 60, ['handled' => true]),
    ], $this->token)->assertOk();

    $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

    foreach (['unhandled' => false, 'handled' => true, 'all' => true] as $filter => $expected) {
        $this->actingAs($admin)
            ->get(route('projects.exceptions.index', ['project' => $this->project->slug, 'filter' => $filter]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('groups', 1)
                ->where('groups.0.is_handled', $expected)
            );
    }
});

it('filters the exceptions page on the real handled flag', function () {
    $this->withoutVite();

    postNightwatchRecords([
        nightwatchExceptionIngestRecord(time() - 120, ['handled' => true]),
        nightwatchExceptionIngestRecord(time() - 60, ['handled' => false]),
    ], $this->token)->assertOk();

    $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

    $this->actingAs($admin)
        ->get(route('projects.exceptions.index', ['project' => $this->project->slug, 'filter' => 'handled']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/exceptions/index')
            ->where('summary.totals.handled', 1)
            ->where('summary.totals.unhandled', 0)
        );
});

it('stores the handled flag of a legacy event given as handled or is_handled', function (array $flag) {
    $store = new EventStore(new Fingerprinter);

    $store->store($this->project, [
        'type' => 'exception',
        'data' => [
            'class' => 'RuntimeException',
            'file' => 'app/A.php',
            'line' => 5,
            'message' => 'boom',
            'occurred_at' => '2026-03-01T10:00:00Z',
            ...$flag,
        ],
    ]);

    expect(ErrorOccurrence::where('project_id', $this->project->id)->sole()->is_handled)->toBeTrue()
        ->and(nightwatchExceptionGroup($this->project)->is_handled)->toBeTrue();
})->with([
    'handled true' => [['handled' => true]],
    'is_handled string' => [['is_handled' => 'true']],
]);

it('stores a legacy event without a flag as unhandled', function () {
    (new EventStore(new Fingerprinter))->store($this->project, [
        'type' => 'exception',
        'data' => ['class' => 'RuntimeException', 'occurred_at' => '2026-03-01T10:00:00Z'],
    ]);

    expect(ErrorOccurrence::where('project_id', $this->project->id)->sole())
        ->is_handled->toBeFalse()
        ->language_version->toBeNull()
        ->hostname->toBeNull();
});

it('marks the trace as having errors and rolls that back with the occurrence', function () {
    $trace = Trace::factory()->create([
        'project_id' => $this->project->id,
        'correlation_id' => 'trace-1',
        'has_errors' => false,
    ]);
    $record = nightwatchExceptionIngestRecord($this->base, ['trace_id' => 'trace-1']);

    postNightwatchRecords([$record], $this->token)->assertOk();

    expect($trace->refresh()->has_errors)->toBeTrue()
        ->and(ErrorOccurrence::where('trace_id', $trace->id)->count())->toBe(1);

    $trace->forceFill(['has_errors' => false])->save();
    ErrorOccurrence::query()->delete();
    ErrorGroup::query()->delete();

    ErrorOccurrence::creating(fn () => throw new RuntimeException('insert failed'));

    expect(fn () => (new EventStore(new Fingerprinter))->store($this->project, [
        'type' => 'exception',
        'data' => [
            'request_id' => 'trace-1',
            'class' => 'RuntimeException',
            'occurred_at' => '2026-07-01T10:15:00Z',
        ],
    ]))->toThrow(RuntimeException::class, 'insert failed');

    expect($trace->refresh()->has_errors)->toBeFalse()
        ->and(ErrorOccurrence::count())->toBe(0)
        ->and(ErrorGroup::count())->toBe(0);
});
