<?php

use App\Watch\Databases;
use App\Watch\TableMover;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Upgrade\Support\SplitUpgrade;

/**
 * Upgrading an install that keeps everything in one database: the data must end up in three files, intact.
 */
beforeEach(function () {
    $this->upgrade = new SplitUpgrade;
});

afterEach(function () {
    $this->upgrade->cleanUp();
});

function movedTables(): array
{
    return [...Databases::TELEMETRY_TABLES, ...Databases::LOG_TABLES];
}

it('moves every row to the right file and drops the originals', function (bool $genericCopy) {
    $this->upgrade->legacy()->seed();
    $this->upgrade->useSmallChunks(genericCopy: $genericCopy);
    $before = $this->upgrade->snapshot('sqlite', 'sqlite');
    $appSizeBefore = filesize($this->upgrade->path('app'));
    $untouched = [
        'users' => DB::table('users')->orderBy('id')->get()->all(),
        'projects' => DB::table('projects')->orderBy('id')->get()->all(),
    ];

    expect($this->upgrade->migrate())->toBe(0);

    expect($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);

    foreach (movedTables() as $table) {
        expect($before[$table])->toHaveCount($this->upgrade->rows)
            ->and(Schema::connection('sqlite')->hasTable($table))->toBeFalse("{$table} should be gone from the app database");
    }

    expect($this->upgrade->tables(Databases::TELEMETRY))->toEqualCanonicalizing([...Databases::TELEMETRY_TABLES])
        ->and($this->upgrade->tables(Databases::LOGS))->toEqualCanonicalizing(['log_entries'])
        ->and(DB::table('users')->orderBy('id')->get()->all())->toEqual($untouched['users'])
        ->and(DB::table('projects')->orderBy('id')->get()->all())->toEqual($untouched['projects'])
        ->and(Schema::connection('sqlite')->hasTable('jobs'))->toBeTrue()
        ->and(Schema::connection('sqlite')->hasTable('failed_jobs'))->toBeTrue()
        ->and(Schema::connection('sqlite')->hasTable('sessions'))->toBeTrue()
        ->and(filesize($this->upgrade->path('app')))->toBeLessThan($appSizeBefore);
})->with([
    'copied inside SQLite' => false,
    'copied through PHP' => true,
]);

it('gives log entries a seq primary key in the logs database', function () {
    $this->upgrade->legacy()->seed();

    $this->upgrade->migrate();

    $schema = $this->upgrade->schema(Databases::LOGS, 'log_entries');

    expect($schema['columns']['seq']['auto_increment'])->toBeTrue()
        ->and($schema['indexes']['primary']['columns'])->toBe(['seq'])
        ->and($schema['indexes']['log_entries_id_unique']['unique'])->toBeTrue()
        ->and(DB::connection(Databases::LOGS)->table('log_entries')->orderBy('seq')->pluck('seq')->all())
        ->toBe(range(1, $this->upgrade->rows));
});

it('keeps the foreign keys between telemetry tables and drops the ones to projects and users', function () {
    $this->upgrade->legacy()->seed();
    $this->upgrade->migrate();

    foreach (Databases::TELEMETRY_TABLES as $table) {
        foreach (Schema::connection(Databases::TELEMETRY)->getForeignKeys($table) as $foreignKey) {
            expect(Databases::TELEMETRY_TABLES)->toContain($foreignKey['foreign_table']);
        }
    }

    foreach (Schema::connection(Databases::LOGS)->getForeignKeys('log_entries') as $foreignKey) {
        $this->fail("log_entries still references {$foreignKey['foreign_table']}");
    }

    expect(Schema::connection(Databases::TELEMETRY)->getForeignKeys('trace_queries'))->not->toBeEmpty();
});

it('still cascades inside the telemetry database', function () {
    $this->upgrade->legacy()->seed();
    $this->upgrade->migrate();

    $telemetry = DB::connection(Databases::TELEMETRY);
    $traceId = $telemetry->table('traces')->orderBy('id')->value('id');
    $groupId = $telemetry->table('error_groups')->orderBy('id')->value('id');

    expect($telemetry->table('trace_queries')->where('trace_id', $traceId)->exists())->toBeTrue()
        ->and($telemetry->table('issue_comments')->where('error_group_id', $groupId)->exists())->toBeTrue();

    $telemetry->table('traces')->where('id', $traceId)->delete();
    $telemetry->table('error_groups')->where('id', $groupId)->delete();

    expect($telemetry->table('trace_queries')->where('trace_id', $traceId)->exists())->toBeFalse()
        ->and($telemetry->table('event_occurrences')->where('trace_id', $traceId)->exists())->toBeFalse()
        ->and($telemetry->table('queue_job_runs')->count())->toBe($this->upgrade->rows)
        ->and($telemetry->table('queue_job_runs')->whereNull('trace_id')->count())->toBe(1)
        ->and($telemetry->table('error_occurrences')->count())->toBe($this->upgrade->rows)
        ->and($telemetry->table('error_occurrences')->whereNull('trace_id')->count())->toBe(1)
        ->and($telemetry->table('error_occurrences')->whereNull('error_group_id')->count())->toBe(1)
        ->and($telemetry->table('issue_comments')->where('error_group_id', $groupId)->exists())->toBeFalse();
});

it('rolls back to a single database with identical data and the original schema', function (bool $genericCopy) {
    $this->upgrade->legacy()->seed();
    $before = $this->upgrade->snapshot('sqlite', 'sqlite');
    $legacySchemas = collect(movedTables())->mapWithKeys(fn (string $table) => [$table => $this->upgrade->schema('sqlite', $table)])->all();

    $this->upgrade->useSmallChunks(genericCopy: $genericCopy);
    $this->upgrade->migrate();

    expect($this->upgrade->rollback())->toBe(0);

    expect($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($before)
        ->and($this->upgrade->tables(Databases::TELEMETRY))->toBe([])
        ->and($this->upgrade->tables(Databases::LOGS))->toBe([]);

    foreach (movedTables() as $table) {
        expect($this->upgrade->schema('sqlite', $table))->toEqual($legacySchemas[$table], "{$table} schema after rollback");
    }
})->with([
    'copied inside SQLite' => false,
    'copied through PHP' => true,
]);

it('rolls back the whole schema and migrates again', function () {
    $this->upgrade->legacy()->seed();
    $this->upgrade->migrate();
    $this->upgrade->rollback();

    expect($this->upgrade->migrate())->toBe(0)
        ->and(Schema::connection('sqlite')->hasTable('traces'))->toBeFalse()
        ->and(Schema::connection(Databases::TELEMETRY)->hasTable('traces'))->toBeTrue()
        ->and(DB::connection(Databases::TELEMETRY)->table('traces')->count())->toBe($this->upgrade->rows);
});

it('does nothing the second time', function () {
    $this->upgrade->legacy()->seed();
    $this->upgrade->migrate();
    $after = $this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS);

    $this->upgrade->migrate();
    foreach (['telemetry', 'logs'] as $which) {
        $this->upgrade->splitMigration($which)->up();
    }

    expect($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($after)
        ->and(Schema::connection('sqlite')->hasTable('traces'))->toBeFalse();
});

it('creates the new schema equal to the old one, minus the foreign keys to the app tables', function () {
    $this->upgrade->legacy();
    $legacy = collect(movedTables())->mapWithKeys(fn (string $table) => [$table => $this->upgrade->schema('sqlite', $table)])->all();

    $this->upgrade->migrate();

    foreach (Databases::TELEMETRY_TABLES as $table) {
        $expected = $legacy[$table];
        $expected['foreignKeys'] = array_values(array_filter(
            $expected['foreignKeys'],
            fn (array $foreignKey): bool => ! in_array($foreignKey['foreign_table'], ['projects', 'users'], true),
        ));

        expect($this->upgrade->schema(Databases::TELEMETRY, $table))->toEqual($expected, "{$table} schema");
    }

    $logs = $legacy['log_entries'];
    $logs['foreignKeys'] = [];
    $logs['columns']['id']['nullable'] = false;
    $logs['columns']['seq'] = ['type' => 'integer', 'nullable' => false, 'default' => null, 'auto_increment' => true];
    ksort($logs['columns']);
    unset($logs['indexes']['sqlite_autoindex_log_entries_1']);
    $logs['indexes']['primary'] = ['columns' => ['seq'], 'unique' => true, 'primary' => true];
    $logs['indexes']['log_entries_id_unique'] = ['columns' => ['id'], 'unique' => true, 'primary' => false];
    ksort($logs['indexes']);

    expect($this->upgrade->schema(Databases::LOGS, 'log_entries'))->toEqual($logs);
});

it('freezes the same tables the application treats as moved', function () {
    $telemetry = collect($this->upgrade->splitMigration('telemetry')->tables())->pluck('name')->all();
    $logs = collect($this->upgrade->splitMigration('logs')->tables())->pluck('name')->all();

    expect($telemetry)->toBe(Databases::TELEMETRY_TABLES)
        ->and($logs)->toBe(Databases::LOG_TABLES);
});

it('picks up again after a crash that left a half-written copy behind', function () {
    $this->upgrade->legacy()->seed();
    $before = $this->upgrade->snapshot('sqlite', 'sqlite');

    $this->upgrade->useSmallChunks(afterCopy: fn () => throw new RuntimeException('simulated crash'));
    expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');

    // The copy exists but nothing was dropped, and the migration was not recorded.
    expect(Schema::connection('sqlite')->hasTable('traces'))->toBeTrue()
        ->and(Schema::connection(Databases::TELEMETRY)->hasTable('traces'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', 'like', '%move_telemetry%')->exists())->toBeFalse();

    // Junk in a target table must not survive the re-run.
    DB::connection(Databases::TELEMETRY)->table('end_users')->truncate();
    DB::connection(Databases::TELEMETRY)->table('traces')->where('correlation_id', 'corr-0')->delete();

    $this->upgrade->useSmallChunks();
    expect($this->upgrade->migrate())->toBe(0)
        ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
});

it('picks up again when only some tables are still in the old database', function () {
    $this->upgrade->legacy()->seed();
    $before = $this->upgrade->snapshot('sqlite', 'sqlite');
    $this->upgrade->migrate();

    // Put two tables back, as if a crash had interrupted dropping the sources on a server without transactional DDL.
    $partial = collect($this->upgrade->splitMigration('telemetry')->tables())
        ->filter(fn ($table) => in_array($table->name, ['mail_sends', 'cache_events'], true))
        ->values()->all();
    app(TableMover::class)->move($partial, Databases::TELEMETRY, 'sqlite', legacySchema: true);
    DB::table('migrations')->where('migration', 'like', '%move_telemetry%')->delete();

    expect(Schema::connection('sqlite')->hasTable('mail_sends'))->toBeTrue()
        ->and(Schema::connection(Databases::TELEMETRY)->hasTable('mail_sends'))->toBeFalse();

    expect($this->upgrade->migrate())->toBe(0)
        ->and(Schema::connection('sqlite')->hasTable('mail_sends'))->toBeFalse()
        ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
});

it('drops nothing when a row shows up in the source after the copy', function () {
    $this->upgrade->legacy()->seed();
    $before = $this->upgrade->snapshot('sqlite', 'sqlite');

    $this->upgrade->useSmallChunks(afterCopy: fn () => DB::table('mail_sends')->insert([
        'id' => 'late-arrival',
        'project_id' => DB::table('projects')->value('id'),
        'mailable_class' => 'App\\Mail\\Late',
        'status' => 'sent',
        'occurred_at' => '2026-10-02 00:00:00',
    ]));

    expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'Nothing was dropped');

    foreach (Databases::TELEMETRY_TABLES as $table) {
        expect(Schema::connection('sqlite')->hasTable($table))->toBeTrue("{$table} must still be in the app database");
    }

    DB::table('mail_sends')->where('id', 'late-arrival')->delete();
    expect($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($before);

    $this->upgrade->useSmallChunks();
    $this->upgrade->migrate();

    expect($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
});

it('refuses to run when the telemetry database is the app database file', function () {
    $this->upgrade->legacy()->seed();
    $before = $this->upgrade->snapshot('sqlite', 'sqlite');
    $app = $this->upgrade->path('app');
    $this->upgrade->pointConnectionsAt($app, $app, $this->upgrade->path('logs'));

    expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'same SQLite file');

    expect($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($before);
});

it('refuses to run when the logs database is the app file reached through a link', function () {
    $this->upgrade->legacy()->seed();
    $app = $this->upgrade->path('app');
    mkdir($this->upgrade->directory.'/nested');
    symlink($app, $this->upgrade->directory.'/nested/alias.sqlite');
    $this->upgrade->pointConnectionsAt($app, $this->upgrade->path('telemetry'), $this->upgrade->directory.'/nested/../nested/alias.sqlite');

    expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'DB_LOGS_DATABASE');

    expect(Schema::connection('sqlite')->hasTable('log_entries'))->toBeTrue()
        ->and(DB::table('log_entries')->count())->toBe($this->upgrade->rows);
});

it('stops before changing anything when the disk is too small', function () {
    $this->upgrade->legacy()->seed();
    $before = $this->upgrade->snapshot('sqlite', 'sqlite');
    $this->upgrade->useSmallChunks(freeDiskSpace: fn (string $directory) => 1_000);

    expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'Not enough free disk space');

    expect($this->upgrade->tables(Databases::TELEMETRY))->toBe([])
        ->and(file_exists($this->upgrade->path('logs')))->toBeFalse()
        ->and($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($before);
});

it('measures the free space in the directory of the new database', function () {
    $this->upgrade->legacy()->seed();
    $asked = [];
    $this->upgrade->useSmallChunks(freeDiskSpace: function (string $directory) use (&$asked) {
        $asked[] = $directory;

        return PHP_INT_MAX;
    });

    $this->upgrade->migrate();

    expect($asked)->toBe([realpath($this->upgrade->directory), realpath($this->upgrade->directory)]);
});

it('creates the telemetry and logs files when they do not exist', function () {
    $this->upgrade->legacy();

    expect(file_exists($this->upgrade->path('telemetry')))->toBeFalse();

    $this->upgrade->migrate();

    expect(file_exists($this->upgrade->path('telemetry')))->toBeTrue()
        ->and(file_exists($this->upgrade->path('logs')))->toBeTrue();
});

it('leaves the telemetry empty after migrate:fresh, whatever the old files held', function () {
    $this->upgrade->legacy()->seed();
    $this->upgrade->migrate();

    expect(DB::connection(Databases::TELEMETRY)->table('traces')->count())->toBe($this->upgrade->rows);

    Artisan::call('migrate:fresh', ['--force' => true]);

    foreach (movedTables() as $table) {
        expect(Schema::connection('sqlite')->hasTable($table))->toBeFalse();
    }

    foreach (Databases::TELEMETRY_TABLES as $table) {
        expect(DB::connection(Databases::TELEMETRY)->table($table)->count())->toBe(0, $table);
    }

    expect(DB::connection(Databases::LOGS)->table('log_entries')->count())->toBe(0);
});

it('moves an install that only has the empty schema', function () {
    $this->upgrade->legacy();

    expect($this->upgrade->migrate())->toBe(0)
        ->and($this->upgrade->tables(Databases::TELEMETRY))->toEqualCanonicalizing(Databases::TELEMETRY_TABLES);
});

it('works on in-memory databases, which are always separate', function () {
    config([
        'database.connections.sqlite.database' => ':memory:',
        'database.connections.telemetry.database' => ':memory:',
        'database.connections.logs.database' => ':memory:',
    ]);
    $this->upgrade->purge();
    Artisan::call('migrate:install');
    app('migrator')->run($this->upgrade->legacyMigrationFiles());
    $this->upgrade->seed();

    expect($this->upgrade->migrate())->toBe(0)
        ->and(Schema::connection('sqlite')->hasTable('traces'))->toBeFalse()
        ->and(DB::connection(Databases::TELEMETRY)->table('traces')->count())->toBe($this->upgrade->rows)
        ->and(DB::connection(Databases::LOGS)->table('log_entries')->count())->toBe($this->upgrade->rows);
});

it('is resolvable from the container with its defaults', function () {
    expect(app(TableMover::class))->toBeInstanceOf(TableMover::class);
});
