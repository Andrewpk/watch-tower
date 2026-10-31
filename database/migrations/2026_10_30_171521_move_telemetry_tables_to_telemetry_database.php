<?php

use App\Watch\Databases;
use App\Watch\MovedTable;
use App\Watch\TableMover;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Moving tables turns foreign keys off and vacuums, which neither work inside a transaction.
     */
    public $withinTransaction = false;

    /**
     * Move the telemetry tables out of the app database into the telemetry database.
     *
     * The tables keep their foreign keys to each other (so deleting a trace still cascades) and lose the ones to
     * `projects` and `users`, which stay behind. Everything is copied and counted before the originals are dropped,
     * and running it again after a crash starts over safely. See {@see TableMover}.
     */
    public function up(): void
    {
        app(TableMover::class)->move($this->tables(), config('database.default'), Databases::TELEMETRY, shrinkSource: false);
    }

    /**
     * Move the tables back into the app database with their original foreign keys.
     */
    public function down(): void
    {
        app(TableMover::class)->move($this->tables(), Databases::TELEMETRY, config('database.default'), legacySchema: true);
    }

    /**
     * The schema of every moved table as it stood when the databases were split, parents before children.
     *
     * Frozen on purpose: later migrations must change these tables through the telemetry connection, and this
     * migration must keep producing the schema the data was written against.
     *
     * @return list<MovedTable>
     */
    public function tables(): array
    {
        $project = ['project_id', 'projects', 'cascade'];

        return [
            new MovedTable(
                name: 'traces',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->string('correlation_id');
                    $table->string('method', 10);
                    $table->text('uri');
                    $table->unsignedSmallInteger('status_code')->nullable();
                    $table->string('user_identifier')->nullable();
                    $table->string('user_email')->nullable();
                    $table->string('user_name')->nullable();
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->unsignedInteger('db_queries_count')->default(0);
                    $table->decimal('db_time_ms', 15, 3)->default(0);
                    $table->unsignedInteger('memory_used_kb')->nullable();
                    $table->unsignedInteger('memory_peak_kb')->nullable();
                    $table->string('environment', 50)->nullable();
                    $table->string('release_version')->nullable();
                    $table->string('hostname')->nullable();
                    $table->string('ip_address', 45)->nullable();
                    $table->text('user_agent')->nullable();
                    $table->json('headers')->nullable();
                    $table->json('request_data')->nullable();
                    $table->json('response_data')->nullable();
                    $table->boolean('has_errors')->default(false);
                    $table->boolean('has_slow_queries')->default(false);
                    $table->boolean('is_stub')->default(false);
                    $table->string('route_name', 255)->nullable();
                    $table->string('route_methods', 100)->nullable();
                    $table->string('route_domain', 255)->nullable();
                    $table->string('route_action', 255)->nullable();
                    $table->unsignedInteger('request_size_bytes')->nullable();
                    $table->unsignedInteger('response_size_bytes')->nullable();
                    $table->decimal('bootstrap_ms', 12, 3)->nullable();
                    $table->decimal('before_middleware_ms', 12, 3)->nullable();
                    $table->decimal('action_ms', 12, 3)->nullable();
                    $table->decimal('render_ms', 12, 3)->nullable();
                    $table->decimal('after_middleware_ms', 12, 3)->nullable();
                    $table->decimal('sending_ms', 12, 3)->nullable();
                    $table->decimal('terminating_ms', 12, 3)->nullable();
                    $table->unsignedInteger('lazy_loads')->nullable();
                    $table->unsignedInteger('hydrated_models')->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                },
                indexes: function (Blueprint $table): void {
                    $table->unique(['project_id', 'correlation_id']);
                    $table->index(['project_id', 'occurred_at']);
                    $table->index(['project_id', 'status_code']);
                    $table->index(['project_id', 'environment']);
                    $table->index(['project_id', 'duration_ms']);
                    $table->index(['project_id', 'user_identifier']);
                    $table->index(['project_id', 'user_identifier'], 'traces_project_user_index');
                },
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'error_groups',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->string('fingerprint', 64);
                    $table->unsignedInteger('display_number')->nullable();
                    $table->string('exception_class');
                    $table->text('first_message')->nullable();
                    $table->string('first_file')->nullable();
                    $table->unsignedInteger('first_line')->nullable();
                    $table->unsignedBigInteger('total_count')->default(1);
                    $table->timestamp('first_occurrence_at');
                    $table->timestamp('last_occurrence_at');
                    $table->string('status')->default('unresolved');
                    $table->string('priority', 20)->default('none');
                    $table->text('description')->nullable();
                    $table->boolean('is_handled')->default(false);
                    $table->string('linear_issue_url')->nullable();
                    $table->json('subscriber_ids')->nullable();
                    $table->string('framework_version', 50)->nullable();
                    $table->string('language_version', 50)->nullable();
                    $table->timestamp('resolved_at')->nullable();
                    $table->unsignedBigInteger('resolved_by_user_id')->nullable();
                    $table->unsignedBigInteger('assigned_to_user_id')->nullable();
                    $table->text('resolution_note')->nullable();
                    $table->json('tags')->nullable();
                    $table->timestamps();
                },
                indexes: function (Blueprint $table): void {
                    $table->unique(['project_id', 'fingerprint']);
                    $table->index(['project_id', 'status']);
                    $table->index(['project_id', 'last_occurrence_at']);
                    $table->unique(['project_id', 'display_number']);
                    $table->index(['project_id', 'priority']);
                    $table->index(['project_id', 'assigned_to_user_id']);
                },
                legacyForeignKeys: [
                    $project,
                    ['resolved_by_user_id', 'users', 'set null'],
                    ['assigned_to_user_id', 'users', 'set null'],
                ],
            ),

            new MovedTable(
                name: 'trace_queries',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->uuid('trace_id');
                    $table->string('query_type', 20)->nullable();
                    $table->text('sql');
                    $table->json('bindings')->nullable();
                    $table->string('connection_name', 100)->nullable();
                    $table->decimal('duration_ms', 12, 3);
                    $table->unsignedBigInteger('row_count')->nullable();
                    $table->boolean('is_n_plus_one')->default(false);
                    $table->string('n_plus_one_group')->nullable();
                    $table->boolean('is_slow')->default(false);
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                    $table->string('file')->nullable();
                    $table->unsignedInteger('line')->nullable();
                    $table->string('connection_type', 10)->nullable();
                    $table->string('source_type', 50)->nullable();
                    $table->string('source_label')->nullable();
                },
                indexes: function (Blueprint $table): void {
                    $table->index('trace_id');
                    $table->index(['project_id', 'is_slow', 'occurred_at']);
                },
                foreignKeys: [['trace_id', 'traces', 'cascade']],
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'error_occurrences',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->uuid('trace_id')->nullable();
                    $table->uuid('error_group_id')->nullable();
                    $table->string('exception_class');
                    $table->text('message')->nullable();
                    $table->json('stacktrace');
                    $table->string('fingerprint', 64);
                    $table->string('user_identifier')->nullable();
                    $table->string('user_email')->nullable();
                    $table->string('user_name')->nullable();
                    $table->string('file')->nullable();
                    $table->unsignedInteger('line')->nullable();
                    $table->boolean('is_handled')->default(false);
                    $table->string('environment', 50)->nullable();
                    $table->string('release_version')->nullable();
                    $table->string('language_version', 50)->nullable();
                    $table->string('framework_version', 50)->nullable();
                    $table->string('hostname')->nullable();
                    $table->json('context')->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                    $table->string('source_type', 50)->nullable();
                    $table->string('source_label')->nullable();
                },
                indexes: function (Blueprint $table): void {
                    $table->index(['project_id', 'occurred_at']);
                    $table->index(['project_id', 'fingerprint']);
                    $table->index('error_group_id');
                    $table->index(['project_id', 'user_identifier'], 'errors_project_user_index');
                },
                foreignKeys: [
                    ['trace_id', 'traces', 'set null'],
                    ['error_group_id', 'error_groups', 'set null'],
                ],
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'event_occurrences',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->uuid('trace_id')->nullable();
                    $table->string('event_class');
                    $table->string('fired_by')->nullable();
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->unsignedInteger('listeners_count')->default(0);
                    $table->json('payload')->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                },
                indexes: function (Blueprint $table): void {
                    $table->index('trace_id');
                    $table->index(['project_id', 'event_class']);
                },
                foreignKeys: [['trace_id', 'traces', 'cascade']],
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'queue_job_runs',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->uuid('trace_id')->nullable();
                    $table->string('job_id', 64)->nullable();
                    $table->string('job_class');
                    $table->string('queue', 100)->nullable();
                    $table->string('connection', 100)->nullable();
                    $table->timestamp('dispatched_at')->nullable();
                    $table->timestamp('started_at')->nullable();
                    $table->timestamp('completed_at')->nullable();
                    $table->timestamp('failed_at')->nullable();
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->unsignedInteger('attempts')->default(0);
                    $table->string('status', 50);
                    $table->json('payload')->nullable();
                    $table->string('user_identifier')->nullable();
                    $table->string('user_email')->nullable();
                    $table->string('user_name')->nullable();
                    $table->json('exception')->nullable();
                    $table->string('environment', 50)->nullable();
                    $table->timestamps();
                    $table->unsignedInteger('lazy_loads')->nullable();
                    $table->unsignedInteger('hydrated_models')->nullable();
                    $table->unsignedInteger('memory_peak_kb')->nullable();
                },
                indexes: function (Blueprint $table): void {
                    $table->index(['project_id', 'status']);
                    $table->index(['project_id', 'job_class']);
                    $table->index(['project_id', 'user_identifier'], 'queue_jobs_project_user_index');
                    $table->unique(['project_id', 'job_id'], 'queue_job_runs_project_job_unique');
                },
                foreignKeys: [['trace_id', 'traces', 'set null']],
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'command_runs',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->string('command');
                    $table->json('arguments')->nullable();
                    $table->json('options')->nullable();
                    $table->string('status', 50);
                    $table->integer('exit_code')->nullable();
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->longText('output')->nullable();
                    $table->string('environment', 50)->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                    $table->decimal('bootstrap_ms', 12, 3)->nullable();
                    $table->decimal('action_ms', 12, 3)->nullable();
                    $table->decimal('terminating_ms', 12, 3)->nullable();
                    $table->unsignedInteger('lazy_loads')->nullable();
                    $table->unsignedInteger('hydrated_models')->nullable();
                    $table->unsignedInteger('memory_peak_kb')->nullable();
                },
                indexes: function (Blueprint $table): void {
                    $table->index('occurred_at');
                    $table->index(['project_id', 'command']);
                    $table->index(['project_id', 'occurred_at']);
                },
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'scheduled_task_runs',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->string('task');
                    $table->string('task_hash', 40);
                    $table->string('schedule')->nullable();
                    $table->string('schedule_summary')->nullable();
                    $table->timestamp('next_run_at')->nullable();
                    $table->string('status', 50);
                    $table->integer('exit_code')->nullable();
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->unsignedInteger('threshold_ms')->nullable();
                    $table->longText('output')->nullable();
                    $table->string('environment', 50)->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                    $table->unsignedInteger('lazy_loads')->nullable();
                    $table->unsignedInteger('hydrated_models')->nullable();
                    $table->unsignedInteger('memory_peak_kb')->nullable();
                    $table->string('timezone', 64)->nullable();
                    $table->unsignedSmallInteger('repeat_seconds')->nullable();
                    $table->boolean('without_overlapping')->nullable();
                    $table->boolean('on_one_server')->nullable();
                    $table->boolean('run_in_background')->nullable();
                    $table->boolean('even_in_maintenance_mode')->nullable();
                },
                indexes: function (Blueprint $table): void {
                    $table->index('task_hash');
                    $table->index('occurred_at');
                    $table->index(['project_id', 'task_hash']);
                    $table->index(['project_id', 'occurred_at']);
                },
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'mail_sends',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->string('trace_id', 36)->nullable();
                    $table->string('mailable_class');
                    $table->string('mailer', 50)->nullable();
                    $table->string('subject')->nullable();
                    $table->string('from_address')->nullable();
                    $table->string('from_name')->nullable();
                    $table->text('recipients_to')->nullable();
                    $table->text('recipients_cc')->nullable();
                    $table->text('recipients_bcc')->nullable();
                    $table->unsignedInteger('recipients_count')->default(0);
                    $table->unsignedInteger('attachments_count')->default(0);
                    $table->string('queue', 100)->nullable();
                    $table->string('status', 50);
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->string('source_type', 50)->nullable();
                    $table->string('source_id')->nullable();
                    $table->string('source_label')->nullable();
                    $table->string('environment', 50)->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                },
                indexes: function (Blueprint $table): void {
                    $table->index('trace_id');
                    $table->index('occurred_at');
                    $table->index(['project_id', 'mailable_class']);
                    $table->index(['project_id', 'occurred_at']);
                },
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'notification_sends',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->string('trace_id', 36)->nullable();
                    $table->string('notification_class');
                    $table->string('channel', 50);
                    $table->string('notifiable_type')->nullable();
                    $table->string('notifiable_id')->nullable();
                    $table->string('queue', 100)->nullable();
                    $table->string('status', 50);
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->string('source_type', 50)->nullable();
                    $table->string('source_id')->nullable();
                    $table->string('source_label')->nullable();
                    $table->string('environment', 50)->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                },
                indexes: function (Blueprint $table): void {
                    $table->index('trace_id');
                    $table->index('occurred_at');
                    $table->index(['project_id', 'notification_class']);
                    $table->index(['project_id', 'occurred_at']);
                },
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'cache_events',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->string('trace_id', 36)->nullable();
                    $table->string('key');
                    $table->string('store', 50)->nullable();
                    $table->string('operation', 20);
                    $table->boolean('succeeded')->default(true);
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->string('environment', 50)->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                    $table->string('source_type', 50)->nullable();
                    $table->string('source_label')->nullable();
                },
                indexes: function (Blueprint $table): void {
                    $table->index('trace_id');
                    $table->index('occurred_at');
                    $table->index(['project_id', 'key']);
                    $table->index(['project_id', 'occurred_at']);
                    $table->index(['project_id', 'operation']);
                },
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'outgoing_requests',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->string('trace_id', 36)->nullable();
                    $table->string('method', 10);
                    $table->string('host');
                    $table->string('url', 2048);
                    $table->unsignedSmallInteger('status_code')->nullable();
                    $table->unsignedInteger('duration_ms')->nullable();
                    $table->unsignedInteger('request_size_bytes')->nullable();
                    $table->unsignedInteger('response_size_bytes')->nullable();
                    $table->string('source_type', 50)->nullable();
                    $table->string('source_id')->nullable();
                    $table->string('source_label')->nullable();
                    $table->string('environment', 50)->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                },
                indexes: function (Blueprint $table): void {
                    $table->index('trace_id');
                    $table->index('occurred_at');
                    $table->index(['project_id', 'host']);
                    $table->index(['project_id', 'occurred_at']);
                    $table->index(['project_id', 'status_code']);
                },
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'end_users',
                columns: function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('project_id');
                    $table->string('identifier');
                    $table->string('name')->nullable();
                    $table->string('email')->nullable();
                    $table->timestamp('last_seen_at');
                    $table->timestamps();
                },
                indexes: function (Blueprint $table): void {
                    $table->unique(['project_id', 'identifier']);
                    $table->index(['project_id', 'last_seen_at']);
                },
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'metrics',
                columns: function (Blueprint $table): void {
                    $table->id();
                    $table->uuid('project_id');
                    $table->timestamp('period_start');
                    $table->timestamp('period_end');
                    $table->string('aggregation_level', 20);
                    $table->string('environment', 50)->nullable();
                    $table->unsignedBigInteger('requests_count')->default(0);
                    $table->unsignedBigInteger('errors_count')->default(0);
                    $table->unsignedBigInteger('slow_requests_count')->default(0);
                    $table->decimal('avg_response_time_ms', 12, 2)->nullable();
                    $table->unsignedInteger('p50_response_time_ms')->nullable();
                    $table->unsignedInteger('p95_response_time_ms')->nullable();
                    $table->unsignedInteger('p99_response_time_ms')->nullable();
                    $table->unsignedBigInteger('total_queries')->default(0);
                    $table->decimal('avg_query_time_ms', 12, 3)->nullable();
                    $table->timestamps();
                },
                indexes: function (Blueprint $table): void {
                    $table->unique(['project_id', 'period_start', 'aggregation_level', 'environment'], 'metrics_project_period_unique');
                    $table->index(['project_id', 'period_start']);
                },
                legacyForeignKeys: [$project],
            ),

            new MovedTable(
                name: 'issue_comments',
                columns: function (Blueprint $table): void {
                    $table->id();
                    $table->uuid('error_group_id');
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('type')->default('comment');
                    $table->text('body')->nullable();
                    $table->json('meta')->nullable();
                    $table->timestamps();
                },
                indexes: function (Blueprint $table): void {
                    $table->index(['error_group_id', 'created_at']);
                },
                foreignKeys: [['error_group_id', 'error_groups', 'cascade']],
                legacyForeignKeys: [['user_id', 'users', 'set null']],
            ),
        ];
    }
};
