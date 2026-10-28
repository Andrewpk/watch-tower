<?php

use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\Project;
use App\Models\Trace;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Insert one telemetry row stamped at `$at` into every per-project telemetry table
 * (13 rows), with the query and event rows attached to the trace.
 */
function seedTelemetry(Project $project, CarbonInterface $at): void
{
    $trace = Trace::factory()->create(['project_id' => $project->id, 'occurred_at' => $at]);
    $group = ErrorGroup::factory()->create(['project_id' => $project->id]);
    ErrorOccurrence::factory()->create([
        'project_id' => $project->id,
        'trace_id' => $trace->id,
        'error_group_id' => $group->id,
        'occurred_at' => $at,
    ]);

    $base = ['project_id' => $project->id, 'created_at' => $at, 'updated_at' => $at];
    $rows = [
        'trace_queries' => ['trace_id' => $trace->id, 'sql' => 'select 1', 'duration_ms' => 1, 'occurred_at' => $at],
        'event_occurrences' => ['trace_id' => $trace->id, 'event_class' => 'App\\Events\\Example', 'occurred_at' => $at],
        'queue_job_runs' => ['job_class' => 'App\\Jobs\\Example', 'status' => 'processed'],
        'command_runs' => ['command' => 'inspire', 'status' => 'ok', 'occurred_at' => $at],
        'scheduled_task_runs' => ['task' => 'inspire', 'task_hash' => sha1('inspire'), 'status' => 'ok', 'occurred_at' => $at],
        'mail_sends' => ['mailable_class' => 'App\\Mail\\Example', 'status' => 'sent', 'occurred_at' => $at],
        'notification_sends' => ['notification_class' => 'App\\Notifications\\Example', 'channel' => 'mail', 'status' => 'sent', 'occurred_at' => $at],
        'cache_events' => ['key' => 'k', 'operation' => 'hit', 'occurred_at' => $at],
        'outgoing_requests' => ['method' => 'GET', 'host' => 'example.test', 'url' => 'https://example.test', 'occurred_at' => $at],
        'log_entries' => ['level' => 'info', 'message' => 'hello', 'occurred_at' => $at],
    ];

    foreach ($rows as $table => $row) {
        DB::table($table)->insert([...$base, 'id' => (string) Str::uuid(), ...$row]);
    }

    DB::table('metrics')->insert([
        'project_id' => $project->id,
        'period_start' => $at,
        'period_end' => $at,
        'aggregation_level' => 'hour',
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}
