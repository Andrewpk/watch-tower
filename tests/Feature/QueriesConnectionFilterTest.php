<?php

use App\Models\Project;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutVite();
    $this->freezeSecond();

    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]));
    $this->project = Project::factory()->create();
    $trace = Trace::factory()->create(['project_id' => $this->project->id]);

    $seed = function (string $sql, string $connection, float $duration, string $file, int $line) use ($trace): void {
        TraceQuery::factory()->create([
            'project_id' => $this->project->id,
            'trace_id' => $trace->id,
            'sql' => $sql,
            'connection_name' => $connection,
            'duration_ms' => $duration,
            'file' => $file,
            'line' => $line,
            'occurred_at' => now(),
        ]);
    };

    // The same statement on both connections, run from a different location on each.
    $seed('select * from users', 'mysql', 10.5, 'app/Users.php', 10);
    $seed('select * from users', 'mysql', 30.25, 'app/Users.php', 10);
    $seed('select * from users', 'reporting', 100.5, 'app/Reports.php', 20);
    // A statement that only runs on the reporting connection.
    $seed('select * from orders', 'reporting', 50.25, 'app/Reports.php', 30);
});

it('offers the distinct connections in range and shows everything without a filter', function () {
    $this->get(route('projects.queries.index', [$this->project]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/queries/index')
            ->where('connections', ['mysql', 'reporting'])
            ->where('filters.connection', null)
            ->where('summary.totals.total', 4)
            ->where('summary.totals.total_ms', 191.5)
            ->where('queries.total', 2));
});

it('filters the list, the totals and the charts by connection', function () {
    $response = $this->get(route('projects.queries.index', [$this->project, 'connection' => 'reporting']));

    $response
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/queries/index')
            ->where('filters.connection', 'reporting')
            ->where('summary.totals.total', 2)
            ->where('summary.totals.total_ms', 150.75)
            ->has('queries.data', 2)
            ->where('queries.data.0.connection', 'reporting')
            ->where('queries.data.0.total', 1)
            ->where('queries.data.0.total_ms', 100.5)
            ->where('queries.data.1.connection', 'reporting'));

    $buckets = $response->inertiaProps('summary')['buckets'];

    expect(array_sum(array_column($buckets, 'count')))->toBe(2);

    $this->get(route('projects.queries.index', [$this->project, 'connection' => 'mysql']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.connection', 'mysql')
            ->where('summary.totals.total', 2)
            ->where('summary.totals.total_ms', 40.75)
            ->has('queries.data', 1)
            ->where('queries.data.0.connection', 'mysql')
            ->where('queries.data.0.total', 2));
});

it('filters the locations by connection', function () {
    $this->get(route('projects.queries.index', [$this->project]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queries.data.0.locations_count', 2));

    $this->get(route('projects.queries.index', [$this->project, 'connection' => 'mysql']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queries.data.0.location', 'app/Users.php:10')
            ->where('queries.data.0.locations_count', 1));

    $this->get(route('projects.queries.index', [$this->project, 'connection' => 'reporting']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queries.data.0.location', 'app/Reports.php:20')
            ->where('queries.data.0.locations_count', 1));
});
