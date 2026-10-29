<?php

use App\Http\Controllers\Project\JobsController;
use App\Models\CacheEvent;
use App\Models\CommandRun;
use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\MailSend;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\ScheduledTaskRun;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

const EXEC_SOURCE_JOB_ID = '66666666-6666-4666-8666-666666666666';

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->token = ingestToken($this->project->id);
    $this->at = CarbonImmutable::now()->subMinutes(10);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function execSourceAttempt(CarbonImmutable $at, array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'job-attempt',
        'timestamp' => $at->getTimestamp() + 0.5,
        'trace_id' => (string) Str::uuid(),
        'job_id' => EXEC_SOURCE_JOB_ID,
        'attempt_id' => (string) Str::uuid(),
        'attempt' => 1,
        'name' => 'App\\Jobs\\SendInvoice',
        'connection' => 'redis',
        'queue' => 'default',
        'status' => 'processed',
        'duration' => 1_000_000,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function execSourceException(CarbonImmutable $at, array $overrides = []): array
{
    return array_merge([
        'v' => 3,
        't' => 'exception',
        'timestamp' => $at->getTimestamp() + 0.5,
        '_group' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'trace_id' => (string) Str::uuid(),
        'class' => 'RuntimeException',
        'file' => 'app/Services/Example.php',
        'message' => 'Something broke',
        'line' => 12,
        'trace' => '[]',
        'handled' => false,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function execSourceRequest(CarbonImmutable $at, string $traceId, array $overrides = []): array
{
    return array_merge([
        'v' => 1,
        't' => 'request',
        'timestamp' => $at->getTimestamp() + 0.5,
        'trace_id' => $traceId,
        'method' => 'GET',
        'url' => 'https://example.com/orders',
        'route_path' => '/orders',
        'status_code' => 200,
        'duration' => 12_000,
    ], $overrides);
}

it('stores the peak memory of commands, job attempts and scheduled tasks', function () {
    postNightwatchRecords([
        ['v' => 1, 't' => 'command', 'timestamp' => $this->at->getTimestamp() + 0.5, 'trace_id' => (string) Str::uuid(), 'name' => 'app:sync-users', 'command' => 'app:sync-users', 'exit_code' => 0, 'duration' => 1_000_000, 'peak_memory_usage' => 10_485_760],
        execSourceAttempt($this->at, ['peak_memory_usage' => 20_971_520]),
        ['v' => 1, 't' => 'scheduled-task', 'timestamp' => $this->at->getTimestamp() + 0.5, 'trace_id' => (string) Str::uuid(), 'name' => 'php artisan app:prune', 'cron' => '0 * * * *', 'timezone' => 'UTC', 'repeat_seconds' => 0, 'status' => 'processed', 'duration' => 1_000_000, 'peak_memory_usage' => 5_242_880],
    ], $this->token)->assertOk();

    expect(CommandRun::sole()->memory_peak_kb)->toBe(10_240)
        ->and(QueueJobRun::sole()->memory_peak_kb)->toBe(20_480)
        ->and(ScheduledTaskRun::sole()->memory_peak_kb)->toBe(5_120);
});

it('stores no peak memory for records that send none', function () {
    postNightwatchRecords([execSourceAttempt($this->at)], $this->token)->assertOk();

    expect(QueueJobRun::sole()->memory_peak_kb)->toBeNull();
});

it('keeps the peak memory of the newest job attempt', function () {
    postNightwatchRecords([execSourceAttempt($this->at, ['peak_memory_usage' => 10_485_760])], $this->token)->assertOk();

    postNightwatchRecords([execSourceAttempt($this->at->addMinute(), ['attempt' => 2, 'peak_memory_usage' => 20_971_520])], $this->token)->assertOk();

    expect(QueueJobRun::sole()->memory_peak_kb)->toBe(20_480);

    // An older attempt arriving late leaves the newest one in place.
    postNightwatchRecords([execSourceAttempt($this->at->subMinute(), ['attempt' => 1, 'peak_memory_usage' => 1_048_576])], $this->token)->assertOk();

    expect(QueueJobRun::sole()->memory_peak_kb)->toBe(20_480);

    // A newer attempt that reports no memory does not erase it.
    postNightwatchRecords([execSourceAttempt($this->at->addMinutes(2), ['attempt' => 3])], $this->token)->assertOk();

    $run = QueueJobRun::sole();

    expect($run->memory_peak_kb)->toBe(20_480)
        ->and($run->attempts)->toBe(3);
});

it('stores the scheduled task options and passes them to the detail page', function () {
    $this->withoutVite();

    postNightwatchRecords([[
        'v' => 1,
        't' => 'scheduled-task',
        'timestamp' => $this->at->getTimestamp() + 0.5,
        'trace_id' => (string) Str::uuid(),
        'name' => 'php artisan app:prune',
        'cron' => '0 * * * *',
        'timezone' => 'Europe/Amsterdam',
        'repeat_seconds' => 30,
        'without_overlapping' => true,
        'on_one_server' => false,
        'run_in_background' => true,
        'even_in_maintenance_mode' => false,
        'status' => 'processed',
        'duration' => 1_000_000,
        'peak_memory_usage' => 2_097_152,
    ]], $this->token)->assertOk();

    $run = ScheduledTaskRun::sole();

    expect($run->timezone)->toBe('Europe/Amsterdam')
        ->and($run->repeat_seconds)->toBe(30)
        ->and($run->without_overlapping)->toBeTrue()
        ->and($run->on_one_server)->toBeFalse()
        ->and($run->run_in_background)->toBeTrue()
        ->and($run->even_in_maintenance_mode)->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
        ->get(route('projects.scheduled-tasks.show', [$this->project, $run->task_hash]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/scheduled-tasks/show')
            ->where('detail.options.timezone', 'Europe/Amsterdam')
            ->where('detail.options.repeat_seconds', 30)
            ->where('detail.options.without_overlapping', true)
            ->where('detail.options.on_one_server', false)
            ->where('detail.options.run_in_background', true)
            ->where('detail.options.even_in_maintenance_mode', false)
            ->where('detail.runs.0.memory_peak_kb', 2_048));
});

it('shows the peak memory on the command and job detail pages', function () {
    $this->withoutVite();
    $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

    postNightwatchRecords([
        ['v' => 1, 't' => 'command', 'timestamp' => $this->at->getTimestamp() + 0.5, 'trace_id' => (string) Str::uuid(), 'name' => 'app:sync-users', 'command' => 'app:sync-users', 'exit_code' => 0, 'duration' => 1_000_000, 'peak_memory_usage' => 10_485_760],
        execSourceAttempt($this->at, ['peak_memory_usage' => 20_971_520]),
    ], $this->token)->assertOk();

    $this->actingAs($admin)
        ->get(route('projects.commands.show', [$this->project, 'app:sync-users']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('detail.calls.0.memory_peak_kb', 10_240));

    $this->actingAs($admin)
        ->get(route('projects.jobs.show', [$this->project, JobsController::encodeJobClass('App\\Jobs\\SendInvoice')]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('detail.attempts.0.memory_peak_kb', 20_480));
});

it('stores the execution source of queries, cache events and exceptions', function () {
    $traceId = (string) Str::uuid();

    postNightwatchRecords([
        ['v' => 1, 't' => 'query', 'timestamp' => $this->at->getTimestamp() + 0.5, '_group' => 'q1', 'trace_id' => $traceId, 'sql' => 'select 1', 'duration' => 500, 'connection' => 'mysql', 'execution_source' => 'job', 'execution_preview' => 'App\\Jobs\\SendInvoice', 'execution_stage' => 'action', 'execution_id' => 'attempt-1'],
        ['v' => 1, 't' => 'cache-event', 'timestamp' => $this->at->getTimestamp() + 0.5, '_group' => 'c1', 'trace_id' => $traceId, 'key' => 'users', 'store' => 'redis', 'type' => 'hit', 'duration' => 500, 'execution_source' => 'job', 'execution_preview' => 'App\\Jobs\\SendInvoice'],
        execSourceException($this->at, ['trace_id' => $traceId, 'execution_source' => 'job', 'execution_preview' => 'App\\Jobs\\SendInvoice']),
    ], $this->token)->assertOk();

    expect(TraceQuery::sole())
        ->source_type->toBe('job')
        ->source_label->toBe('App\\Jobs\\SendInvoice')
        ->and(CacheEvent::sole())
        ->source_type->toBe('job')
        ->source_label->toBe('App\\Jobs\\SendInvoice')
        ->and(ErrorOccurrence::sole())
        ->source_type->toBe('job')
        ->source_label->toBe('App\\Jobs\\SendInvoice');
});

it('stores no source for child records that carry none', function () {
    postNightwatchRecords([
        execSourceException($this->at),
    ], $this->token)->assertOk();

    expect(ErrorOccurrence::sole())
        ->source_type->toBeNull()
        ->source_label->toBeNull();
});

it('names the job as the trigger even when the request trace of the dispatcher exists', function () {
    $this->withoutVite();
    $traceId = (string) Str::uuid();

    postNightwatchRecords([
        execSourceRequest($this->at, $traceId),
        execSourceException($this->at, ['trace_id' => $traceId, 'execution_source' => 'job', 'execution_preview' => 'App\\Jobs\\SendInvoice']),
    ], $this->token)->assertOk();

    expect(Trace::where('correlation_id', $traceId)->sole()->is_stub)->toBeFalse();

    $group = ErrorGroup::sole();
    $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

    $this->actingAs($admin)
        ->get(route('projects.exceptions.show', ['project' => $this->project->slug, 'exception' => $group->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exception.occurrence_list.0.source_type', 'job')
            ->where('exception.occurrence_list.0.source_label', 'App\\Jobs\\SendInvoice'));

    $this->actingAs($admin)
        ->get(route('projects.issues.show', ['project' => $this->project->slug, 'issue' => $group->display_number]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('issue.occurrence_list.0.source_type', 'job')
            ->where('issue.occurrence_list.0.source_label', 'App\\Jobs\\SendInvoice'));
});

it('uses the request trace when the stored source is a request', function () {
    $traceId = (string) Str::uuid();

    postNightwatchRecords([
        execSourceRequest($this->at, $traceId, ['method' => 'POST', 'route_path' => '/orders/{order}']),
        execSourceException($this->at, ['trace_id' => $traceId, 'execution_source' => 'request', 'execution_preview' => 'POST /orders/1']),
    ], $this->token)->assertOk();

    expect(ErrorOccurrence::sole()->load('trace')->triggeredBy())->toBe(['type' => 'POST', 'label' => '/orders/{order}']);
});

it('falls back to the stored request label while the request record is missing', function () {
    postNightwatchRecords([
        execSourceException($this->at, ['execution_source' => 'request', 'execution_preview' => 'GET /orders']),
    ], $this->token)->assertOk();

    expect(ErrorOccurrence::sole()->load('trace')->triggeredBy())->toBe(['type' => 'request', 'label' => 'GET /orders']);
});

it('names the command as the trigger of an exception under the stub trace of a command', function () {
    $this->withoutVite();

    postNightwatchRecords([
        execSourceException($this->at, ['execution_source' => 'command', 'execution_preview' => 'app:sync-users']),
    ], $this->token)->assertOk();

    expect(Trace::sole()->is_stub)->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
        ->get(route('projects.exceptions.show', ['project' => $this->project->slug, 'exception' => ErrorGroup::sole()->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exception.occurrence_list.0.source_type', 'command')
            ->where('exception.occurrence_list.0.source_label', 'app:sync-users'));
});

it('keeps the old trigger output for an exception stored without a source', function () {
    $this->withoutVite();
    $requestTraceId = (string) Str::uuid();

    postNightwatchRecords([
        execSourceRequest($this->at, $requestTraceId, ['route_path' => '/orders']),
        execSourceException($this->at, ['trace_id' => $requestTraceId]),
        execSourceException($this->at, ['class' => 'LogicException']),
    ], $this->token)->assertOk();

    $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

    $this->actingAs($admin)
        ->get(route('projects.exceptions.show', ['project' => $this->project->slug, 'exception' => ErrorGroup::where('exception_class', 'RuntimeException')->value('id')]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exception.occurrence_list.0.source_type', 'GET')
            ->where('exception.occurrence_list.0.source_label', '/orders'));

    $this->actingAs($admin)
        ->get(route('projects.exceptions.show', ['project' => $this->project->slug, 'exception' => ErrorGroup::where('exception_class', 'LogicException')->value('id')]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exception.occurrence_list.0.source_type', 'COMMAND')
            ->where('exception.occurrence_list.0.source_label', 'Example.php'));
});

it('labels a scheduled task exception with the file, because the agent sends no preview for a schedule', function () {
    $this->withoutVite();

    postNightwatchRecords([
        execSourceException($this->at, ['execution_source' => 'schedule', 'execution_preview' => '']),
    ], $this->token)->assertOk();

    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
        ->get(route('projects.exceptions.show', ['project' => $this->project->slug, 'exception' => ErrorGroup::sole()->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exception.occurrence_list.0.source_type', 'schedule')
            ->where('exception.occurrence_list.0.source_label', 'Example.php'));
});

it('labels an exception of a request trace with an empty uri with the file', function () {
    $traceId = (string) Str::uuid();

    postNightwatchRecords([
        execSourceRequest($this->at, $traceId, ['route_path' => '']),
        execSourceException($this->at, ['trace_id' => $traceId]),
    ], $this->token)->assertOk();

    expect(ErrorOccurrence::sole()->load('trace')->triggeredBy())->toBe(['type' => 'GET', 'label' => 'Example.php']);
});

it('stores no peak memory for a skipped scheduled task', function () {
    $record = fn (string $status) => [
        'v' => 1,
        't' => 'scheduled-task',
        'timestamp' => $this->at->getTimestamp() + 0.5,
        'trace_id' => (string) Str::uuid(),
        'name' => 'php artisan app:prune',
        'cron' => '0 * * * *',
        'timezone' => 'UTC',
        'repeat_seconds' => 0,
        'status' => $status,
        'duration' => 1_000_000,
        'peak_memory_usage' => 2_097_152,
    ];

    postNightwatchRecords([$record('skipped'), $record('processed')], $this->token)->assertOk();

    expect(ScheduledTaskRun::where('status', 'skipped')->sole()->memory_peak_kb)->toBeNull()
        ->and(ScheduledTaskRun::where('status', 'processed')->sole()->memory_peak_kb)->toBe(2_048);
});

it('stores a scheduled task with an unrepresentable repeat interval', function () {
    postNightwatchRecords([[
        'v' => 1,
        't' => 'scheduled-task',
        'timestamp' => $this->at->getTimestamp() + 0.5,
        'trace_id' => (string) Str::uuid(),
        'name' => 'php artisan app:prune',
        'cron' => '0 * * * *',
        'timezone' => 'UTC',
        'repeat_seconds' => 1e20,
        'status' => 'processed',
    ]], $this->token)->assertOk();

    $run = ScheduledTaskRun::sole();

    expect($run->repeat_seconds)->toBeNull()
        ->and($run->task_hash)->toBe(sha1('php artisan app:prune,0 * * * *,UTC'));
});

it('names the trigger of an occurrence without a trace after its file', function () {
    $occurrence = ErrorOccurrence::factory()->make(['trace_id' => null, 'file' => 'app/Services/Example.php', 'source_type' => null, 'source_label' => null]);

    expect($occurrence->triggeredBy())->toBe(['type' => 'COMMAND', 'label' => 'Example.php']);
});

it('stores no source from legacy events that send arrays or oversized values', function () {
    $project = Project::factory()->withApiKey('pk_test_exec_source')->create();
    $correlationId = (string) Str::uuid();

    $this->withHeader('Authorization', 'Bearer pk_test_exec_source')
        ->postJson('/api/ingest/sync', [
            'events' => [
                ['type' => 'mail', 'data' => ['request_id' => $correlationId, 'class' => 'App\\Mail\\Receipt', 'source_type' => ['x'], 'source_id' => ['y'], 'source_label' => ['z']]],
                ['type' => 'query', 'data' => ['request_id' => $correlationId, 'sql' => 'select 1', 'source_type' => ['x'], 'source_label' => ['z']]],
                ['type' => 'exception', 'data' => ['request_id' => $correlationId, 'class' => 'RuntimeException', 'message' => 'Broke', 'source_type' => ['x'], 'source_label' => ['z']]],
                ['type' => 'cache-event', 'data' => ['request_id' => $correlationId, 'key' => 'users', 'operation' => 'hit', 'source_type' => str_repeat('s', 80), 'source_label' => str_repeat('l', 300)]],
                ['type' => 'scheduled-task', 'data' => ['task' => 'backup:run', 'status' => 'processed', 'timezone' => ['x'], 'repeat_seconds' => -5, 'without_overlapping' => ['x'], 'memory_peak_kb' => 99_999_999_999]],
                ['type' => 'command', 'data' => ['command' => 'app:run', 'memory_peak_kb' => ['x']]],
            ],
        ])
        ->assertOk()
        ->assertJson(['success' => true, 'events_stored' => 6]);

    $mail = MailSend::where('project_id', $project->id)->sole();

    expect($mail->source_type)->toBeNull()
        ->and($mail->source_id)->toBeNull()
        ->and($mail->source_label)->toBeNull()
        ->and(TraceQuery::where('project_id', $project->id)->sole()->source_type)->toBeNull()
        ->and(ErrorOccurrence::where('project_id', $project->id)->sole()->source_type)->toBeNull();

    $cache = CacheEvent::where('project_id', $project->id)->sole();

    expect($cache->source_type)->toHaveLength(50)
        ->and($cache->source_label)->toHaveLength(255);

    $task = ScheduledTaskRun::where('project_id', $project->id)->sole();

    expect($task->timezone)->toBeNull()
        ->and($task->repeat_seconds)->toBeNull()
        ->and($task->without_overlapping)->toBeNull()
        ->and($task->memory_peak_kb)->toBeNull()
        ->and(CommandRun::where('project_id', $project->id)->sole()->memory_peak_kb)->toBeNull();
});

it('seeds peak memory, task options and execution sources for the demo project', function () {
    $seeder = new DemoDataSeeder;
    $call = fn (string $method, array $arguments) => (new ReflectionMethod($seeder, $method))->invoke($seeder, ...$arguments);
    $start = CarbonImmutable::now()->subDay();
    $trace = Trace::factory()->create(['project_id' => $this->project->id, 'method' => 'GET', 'uri' => '/orders']);
    $traces = [['id' => $trace->id, 'method' => 'GET', 'uri' => '/orders', 'occurred_at' => $start->toDateTimeString(), 'has_errors' => true, 'user_identifier' => null]];

    $call('seedCommands', [$this->project, $start]);
    $call('seedJobs', [$this->project, $start, []]);
    $call('seedScheduledTasks', [$this->project, $start, CarbonImmutable::now()]);
    $call('seedCache', [$this->project, $start]);
    $call('seedQueries', [$this->project, $traces]);
    $call('seedExceptions', [$this->project, $traces, $start, [User::factory()->create()], []]);

    expect(CommandRun::whereNull('memory_peak_kb')->exists())->toBeFalse()
        ->and(QueueJobRun::whereNotNull('memory_peak_kb')->exists())->toBeTrue()
        ->and(ScheduledTaskRun::where('timezone', 'UTC')->whereNotNull('without_overlapping')->exists())->toBeTrue()
        ->and(TraceQuery::whereNull('source_type')->exists())->toBeFalse()
        ->and(TraceQuery::where('source_label', 'GET /orders')->exists())->toBeTrue()
        ->and(CacheEvent::whereNull('source_type')->exists())->toBeFalse()
        ->and(ErrorOccurrence::where('source_type', 'job')->exists())->toBeTrue()
        ->and(ErrorOccurrence::where('source_type', 'command')->where('source_label', 'cache:warm')->exists())->toBeTrue()
        ->and(ErrorOccurrence::where('source_type', 'request')->exists())->toBeTrue();
});
