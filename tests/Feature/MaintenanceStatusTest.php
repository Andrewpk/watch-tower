<?php

use App\Models\MaintenanceRun;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Watch\MaintenanceStatus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->travelTo('2026-06-15 12:00:00');
});

/**
 * @return list<array<string, mixed>>
 */
function maintenanceProblems(): array
{
    return app(MaintenanceStatus::class)->problems();
}

/**
 * Insert a `jobs` row that became available the given number of seconds ago.
 */
function insertQueuedJob(int $availableSecondsAgo, ?int $reservedAt = null): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => now()->subSeconds($availableSecondsAgo)->getTimestamp(),
        'created_at' => now()->subSeconds($availableSecondsAgo)->getTimestamp(),
    ]);
}

describe('retention prune', function () {
    it('has no problem without projects and without a record', function () {
        expect(maintenanceProblems())->toBe([]);
    });

    it('warns that the prune never ran once the oldest project is past the threshold', function () {
        Project::factory()->create(['created_at' => now()->subHours(26)]);
        Project::factory()->create(['created_at' => now()->subHour()]);

        expect(maintenanceProblems())->toBe([
            ['key' => 'prune_overdue', 'last_run_at' => null, 'threshold_hours' => 25],
        ]);
    });

    it('gives a new install time before warning', function () {
        Project::factory()->create(['created_at' => now()->subHours(2)]);

        expect(maintenanceProblems())->toBe([]);
    });

    it('accepts a run inside the threshold', function () {
        MaintenanceRun::factory()->create(['finished_at' => now()->subHours(24)->subMinutes(59)]);

        expect(maintenanceProblems())->toBe([]);
    });

    it('warns when the last run is past the threshold', function () {
        $finishedAt = now()->subHours(25)->subMinute();
        MaintenanceRun::factory()->create(['finished_at' => $finishedAt]);

        expect(maintenanceProblems())->toBe([
            ['key' => 'prune_overdue', 'last_run_at' => $finishedAt->toIso8601String(), 'threshold_hours' => 25],
        ]);
    });

    it('uses the configured threshold', function () {
        config(['watch.maintenance.prune_overdue_hours' => 48]);
        MaintenanceRun::factory()->create(['finished_at' => now()->subHours(30)]);

        expect(maintenanceProblems())->toBe([]);
    });

    it('returns the last recorded run', function () {
        expect(app(MaintenanceStatus::class)->lastPrune())->toBeNull();

        $run = MaintenanceRun::factory()->create();

        expect(app(MaintenanceStatus::class)->lastPrune()?->is($run))->toBeTrue();
    });
});

describe('queue worker', function () {
    beforeEach(function () {
        config(['queue.default' => 'database']);
    });

    it('warns when a job has waited longer than the threshold', function () {
        insertQueuedJob(11 * 60);

        expect(maintenanceProblems())->toBe([
            [
                'key' => 'queue_stalled',
                'waiting_since' => now()->subMinutes(11)->toIso8601String(),
                'threshold_minutes' => 10,
            ],
        ]);
    });

    it('reports the oldest waiting job', function () {
        insertQueuedJob(11 * 60);
        insertQueuedJob(30 * 60);

        expect(maintenanceProblems()[0]['waiting_since'])->toBe(now()->subMinutes(11)->toIso8601String());
    });

    it('accepts a job that has waited less than the threshold', function () {
        insertQueuedJob(9 * 60);

        expect(maintenanceProblems())->toBe([]);
    });

    it('ignores jobs a worker has reserved', function () {
        insertQueuedJob(30 * 60, reservedAt: now()->subMinutes(29)->getTimestamp());

        expect(maintenanceProblems())->toBe([]);
    });

    it('ignores jobs that are not available yet', function () {
        insertQueuedJob(-30 * 60);

        expect(maintenanceProblems())->toBe([]);
    });

    it('uses the configured threshold', function () {
        config(['watch.maintenance.queue_stalled_minutes' => 1]);
        insertQueuedJob(2 * 60);

        expect(maintenanceProblems())->toHaveCount(1);
    });

    it('does not inspect a queue driver other than database', function () {
        insertQueuedJob(60 * 60);
        config(['queue.default' => 'sync']);

        expect(maintenanceProblems())->toBe([]);
    });
});

describe('shared Inertia props', function () {
    beforeEach(function () {
        $this->withoutVite();
        Organization::factory()->create();
        MaintenanceRun::factory()->create(['finished_at' => now()->subDays(3)]);
    });

    it('shows problems to a super admin', function () {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin)->get(route('settings.profile.show'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('maintenanceProblems.0.key', 'prune_overdue'));
    });

    it('hides problems from an admin', function () {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('settings.profile.show'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('maintenanceProblems', []));
    });

    it('shows no problems when all is well', function () {
        MaintenanceRun::query()->update(['finished_at' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin)->get(route('settings.profile.show'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('maintenanceProblems', []));
    });

    it('exposes the last prune on the projects settings page', function () {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin)->get(route('settings.projects.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('lastPrune.finished_at', now()->subDays(3)->toISOString())
                ->where('lastPrune.rows_deleted', 0));
    });

    it('sends no last prune before the first run', function () {
        MaintenanceRun::query()->delete();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin)->get(route('settings.projects.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('lastPrune', null));
    });
});
