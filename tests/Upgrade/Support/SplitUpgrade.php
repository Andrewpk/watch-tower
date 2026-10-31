<?php

namespace Tests\Upgrade\Support;

use App\Watch\Databases;
use App\Watch\TableMover;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A throwaway install on three SQLite files, for exercising the migrations that split the databases.
 *
 * `legacy()` builds the single-database schema by running every migration that predates the split; `seed()` fills
 * every table that is going to move; the tests then run `migrate` (or `rollback`) and compare what ends up where.
 */
final class SplitUpgrade
{
    public const FIRST_SPLIT_MIGRATION = '_move_telemetry_tables_to_telemetry_database.php';

    public string $directory;

    /**
     * Rows seeded per table by {@see seed()}.
     */
    public int $rows = 0;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/watch-tower-upgrade-'.Str::lower(Str::random(10));
        File::ensureDirectoryExists($this->directory);

        $this->pointConnectionsAt($this->path('app'), $this->path('telemetry'), $this->path('logs'));
    }

    /**
     * Use a pair of fresh files for the connections, named after `$name` ('app', 'telemetry', 'logs').
     */
    public function path(string $name): string
    {
        return "{$this->directory}/{$name}.sqlite";
    }

    /**
     * Point the three connections at the given files and drop any open handle.
     */
    public function pointConnectionsAt(string $app, string $telemetry, string $logs): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $app,
            'database.connections.telemetry.database' => $telemetry,
            'database.connections.logs.database' => $logs,
        ]);

        $this->purge();
    }

    public function purge(): void
    {
        foreach (['sqlite', Databases::TELEMETRY, Databases::LOGS] as $connection) {
            DB::purge($connection);
        }
    }

    /**
     * Build the single-database schema: an empty app file with every migration that predates the split applied.
     */
    public function legacy(): self
    {
        touch($this->path('app'));

        Artisan::call('migrate:install');
        app('migrator')->run($this->legacyMigrationFiles());

        return $this;
    }

    /**
     * Lose the app database and start over with an empty pre-split schema, as `migrate` does on a new app volume.
     */
    public function replaceAppDatabaseWithEmptyLegacy(): self
    {
        $this->purge();

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path('app').$suffix);
        }

        return $this->legacy();
    }

    /**
     * @return list<string>
     */
    public function legacyMigrationFiles(): array
    {
        $first = basename($this->splitMigrationFile('telemetry'));

        return array_values(array_filter(
            glob(database_path('migrations/*.php')),
            fn (string $file): bool => basename($file) < $first,
        ));
    }

    public function splitMigrationFile(string $which): string
    {
        $suffix = $which === 'telemetry' ? self::FIRST_SPLIT_MIGRATION : '_move_log_entries_to_logs_database.php';

        return glob(database_path("migrations/*{$suffix}"))[0];
    }

    public function splitMigration(string $which): Migration
    {
        return require $this->splitMigrationFile($which);
    }

    /**
     * Run `migrate` and return the exit code. Exceptions from a failing migration propagate.
     */
    public function migrate(): int
    {
        return Artisan::call('migrate', ['--force' => true]);
    }

    public function rollback(): int
    {
        return Artisan::call('migrate:rollback', ['--force' => true]);
    }

    /**
     * Make the next `app(TableMover::class)` use small chunks so every table spans several of them.
     */
    public function useSmallChunks(bool $genericCopy = false, ?callable $freeDiskSpace = null, ?callable $afterCopy = null): void
    {
        app()->bind(TableMover::class, fn (): TableMover => new TableMover(
            chunkSize: 7,
            genericChunkSize: 5,
            forceGenericCopy: $genericCopy,
            freeDiskSpace: $freeDiskSpace === null ? null : $freeDiskSpace(...),
            afterCopy: $afterCopy === null ? null : $afterCopy(...),
        ));
    }

    /**
     * Fill every table that moves (and the app tables they point at) with `$count` rows each, spanning two projects.
     */
    public function seed(int $count = 25): self
    {
        $this->rows = $count;
        $now = '2026-10-01 12:00:00';
        $timestamps = ['created_at' => $now, 'updated_at' => $now];

        DB::table('organizations')->insert(['id' => $organization = (string) Str::uuid(), 'name' => 'Org', 'slug' => 'org', ...$timestamps]);

        foreach ([1, 2] as $userId) {
            DB::table('users')->insert([
                'id' => $userId,
                'organization_id' => $organization,
                'name' => "User {$userId}",
                'email' => "user{$userId}@example.test",
                'password' => 'x',
                ...$timestamps,
            ]);
        }

        $projects = [];

        foreach ([1, 2] as $number) {
            $projects[] = (string) Str::uuid();
            DB::table('projects')->insert(['id' => $projects[$number - 1], 'organization_id' => $organization, 'name' => "Project {$number}", 'slug' => "project-{$number}", ...$timestamps]);
        }

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $project = $projects[$i % 2];
            $at = sprintf('2026-10-01 12:%02d:%02d', intdiv($i, 60), $i % 60);
            $id = fn (string $kind): string => sprintf('%s-%04d-0000-0000-000000000000', substr(md5($kind), 0, 8), $i);
            $scope = ['project_id' => $project, ...$timestamps];
            $base = [...$scope, 'occurred_at' => $at];

            $rows['traces'][] = [...$base, 'id' => $id('trace'), 'correlation_id' => "corr-{$i}", 'method' => 'GET', 'uri' => "/pagé/{$i}?q=ü", 'status_code' => 200 + $i, 'duration_ms' => 10 + $i, 'db_time_ms' => 1.25 + $i, 'headers' => '{"a":["b"]}', 'has_errors' => $i % 2, 'lazy_loads' => $i, 'bootstrap_ms' => 0.125];
            $rows['error_groups'][] = ['id' => $id('group'), 'project_id' => $project, 'fingerprint' => "fp-{$i}", 'display_number' => intdiv($i, 2) + 1, 'exception_class' => 'RuntimeException', 'first_message' => str_repeat('boom ', 50), 'total_count' => $i + 1, 'first_occurrence_at' => $at, 'last_occurrence_at' => $at, 'tags' => '["x"]', 'assigned_to_user_id' => $i % 2 === 0 ? 1 : null, 'resolved_by_user_id' => $i % 3 === 0 ? 2 : null, ...$timestamps];
            $rows['trace_queries'][] = [...$base, 'id' => $id('query'), 'trace_id' => $id('trace'), 'sql' => 'select * from `users` where id = ?', 'bindings' => '[1]', 'duration_ms' => 0.512 + $i, 'row_count' => $i, 'is_slow' => $i % 2];
            $rows['error_occurrences'][] = [...$base, 'id' => $id('occurrence'), 'trace_id' => $id('trace'), 'error_group_id' => $id('group'), 'exception_class' => 'RuntimeException', 'message' => "Boom {$i}", 'stacktrace' => '[{"file":"a.php","line":1}]', 'fingerprint' => "fp-{$i}", 'context' => '{"k":"v"}', 'hostname' => 'web-1'];
            $rows['event_occurrences'][] = [...$base, 'id' => $id('event'), 'trace_id' => $id('trace'), 'event_class' => 'App\\Events\\Thing', 'payload' => '{"n":1}'];
            $rows['queue_job_runs'][] = [...$scope, 'id' => $id('job'), 'trace_id' => $id('trace'), 'job_id' => "job-{$i}", 'job_class' => 'App\\Jobs\\Thing', 'status' => 'processed', 'payload' => '{"n":1}', 'user_name' => 'Jane'];
            $rows['command_runs'][] = [...$base, 'id' => $id('command'), 'command' => 'inspire', 'status' => 'ok', 'output' => str_repeat('line'.PHP_EOL, 20), 'action_ms' => 3.5];
            $rows['scheduled_task_runs'][] = [...$base, 'id' => $id('task'), 'task' => 'inspire', 'task_hash' => sha1('inspire'), 'status' => 'ok', 'on_one_server' => 1];
            $rows['mail_sends'][] = [...$base, 'id' => $id('mail'), 'trace_id' => $id('trace'), 'mailable_class' => 'App\\Mail\\Thing', 'status' => 'sent', 'recipients_to' => 'a@example.test'];
            $rows['notification_sends'][] = [...$base, 'id' => $id('notification'), 'trace_id' => $id('trace'), 'notification_class' => 'App\\Notifications\\Thing', 'channel' => 'mail', 'status' => 'sent'];
            $rows['cache_events'][] = [...$base, 'id' => $id('cache'), 'trace_id' => $id('trace'), 'key' => "k{$i}", 'operation' => 'hit'];
            $rows['outgoing_requests'][] = [...$base, 'id' => $id('request'), 'trace_id' => $id('trace'), 'method' => 'GET', 'host' => 'example.test', 'url' => 'https://example.test/'.$i];
            $rows['end_users'][] = [...$scope, 'id' => $id('end-user'), 'identifier' => "user-{$i}", 'name' => 'Jane', 'last_seen_at' => $at];
            $rows['metrics'][] = ['project_id' => $project, 'period_start' => $at, 'period_end' => $at, 'aggregation_level' => 'hour', 'environment' => 'production', 'requests_count' => $i, 'avg_response_time_ms' => 12.34, ...$timestamps];
            $rows['issue_comments'][] = ['error_group_id' => $id('group'), 'user_id' => $i % 2 === 0 ? 1 : null, 'type' => 'comment', 'body' => "Comment {$i} é", 'meta' => '{"m":1}', ...$timestamps];
            $rows['log_entries'][] = [...$base, 'id' => $id('log'), 'trace_id' => $id('trace'), 'level' => 'info', 'message' => "Payment {$i} accepted", 'context' => '{"c":1}'];
        }

        foreach ($rows as $table => $tableRows) {
            DB::table($table)->insert($tableRows);
        }

        return $this;
    }

    /**
     * Every row of a table, ordered by key, as arrays. The generated `seq` column is left out so rows compare across
     * the move.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $connection, string $table): array
    {
        return DB::connection($connection)->table($table)->orderBy('id')->get()
            ->map(function (object $row): array {
                $row = (array) $row;
                unset($row['seq']);

                return $row;
            })
            ->all();
    }

    /**
     * Rows of every moved table, keyed by table name.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function snapshot(string $telemetryConnection, string $logsConnection): array
    {
        $snapshot = [];

        foreach (Databases::TELEMETRY_TABLES as $table) {
            $snapshot[$table] = $this->rows($telemetryConnection, $table);
        }

        foreach (Databases::LOG_TABLES as $table) {
            $snapshot[$table] = $this->rows($logsConnection, $table);
        }

        return $snapshot;
    }

    /**
     * Columns, indexes and foreign keys of a table in a comparable shape.
     *
     * @return array{columns: array<string, array<string, mixed>>, indexes: array<string, array<string, mixed>>, foreignKeys: list<array<string, mixed>>}
     */
    public function schema(string $connection, string $table): array
    {
        $schema = Schema::connection($connection);

        $columns = [];

        foreach ($schema->getColumns($table) as $column) {
            $columns[$column['name']] = [
                'type' => $column['type'],
                'nullable' => $column['nullable'],
                'default' => $column['default'],
                'auto_increment' => $column['auto_increment'],
            ];
        }

        $indexes = [];

        foreach ($schema->getIndexes($table) as $index) {
            $indexes[$index['name']] = [
                'columns' => $index['columns'],
                'unique' => $index['unique'],
                'primary' => $index['primary'],
            ];
        }

        $foreignKeys = array_map(fn (array $foreignKey): array => [
            'columns' => $foreignKey['columns'],
            'foreign_table' => $foreignKey['foreign_table'],
            'foreign_columns' => $foreignKey['foreign_columns'],
            'on_delete' => $foreignKey['on_delete'],
        ], $schema->getForeignKeys($table));

        usort($foreignKeys, fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
        ksort($columns);
        ksort($indexes);

        return ['columns' => $columns, 'indexes' => $indexes, 'foreignKeys' => $foreignKeys];
    }

    /**
     * Names of the tables in a database.
     *
     * @return list<string>
     */
    public function tables(string $connection): array
    {
        return array_values(array_filter(
            array_column(Schema::connection($connection)->getTables(), 'name'),
            fn (string $name): bool => ! str_starts_with($name, 'sqlite_'),
        ));
    }

    public function cleanUp(): void
    {
        $this->purge();
        File::deleteDirectory($this->directory);
    }
}
