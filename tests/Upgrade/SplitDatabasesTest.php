<?php

use App\Watch\Databases;
use App\Watch\MovedTable;
use App\Watch\TableMover;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        ->and($this->upgrade->tables(Databases::LOGS))->toEqualCanonicalizing(['log_entries', 'log_entries_fts'])
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

it('keeps the telemetry and logs when migrate:fresh resets the app database', function () {
    $this->upgrade->legacy()->seed();
    $this->upgrade->migrate();
    $before = $this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS);

    DB::table('users')->update(['name' => 'changed']);

    Artisan::call('migrate:fresh', ['--force' => true]);

    foreach (movedTables() as $table) {
        expect(Schema::connection('sqlite')->hasTable($table))->toBeFalse();
    }

    expect(DB::table('users')->count())->toBe(0)
        ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
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

/**
 * The frozen schema of the telemetry migration, for driving a mover by hand.
 *
 * @return list<MovedTable>
 */
function telemetryTables(object $test): array
{
    return $test->upgrade->splitMigration('telemetry')->tables();
}

/**
 * Run the telemetry move with a crash after the copy, leaving a full copy beside the originals and the marker table.
 */
function leaveHalfFinishedTelemetryMove(object $test): void
{
    $test->upgrade->useSmallChunks(afterCopy: fn () => throw new RuntimeException('simulated crash'));

    expect(fn () => $test->upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');
}

describe('never destroying data', function () {
    it('keeps the telemetry and logs when only the app database was lost and migrate runs again', function () {
        $this->upgrade->legacy()->seed();
        $this->upgrade->migrate();
        $before = $this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS);
        Log::spy();

        $this->upgrade->replaceAppDatabaseWithEmptyLegacy();

        expect($this->upgrade->migrate())->toBe(0)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before)
            ->and($this->upgrade->tables('sqlite'))->not->toContain('traces', 'log_entries');

        Log::shouldHaveReceived('warning')->with(Mockery::pattern('/already holds this table/'), Mockery::on(fn (array $context): bool => $context['table'] === 'traces'))->once();
    });

    it('refuses before changing anything when both databases hold rows and nothing says which is real', function () {
        $this->upgrade->legacy()->seed();
        leaveHalfFinishedTelemetryMove($this);
        Schema::connection(Databases::TELEMETRY)->drop(TableMover::MARKER_TABLE);
        $appBefore = $this->upgrade->snapshot('sqlite', 'sqlite');
        $telemetryBefore = $this->upgrade->rows(Databases::TELEMETRY, 'traces');
        $this->upgrade->useSmallChunks();

        $message = null;

        try {
            $this->upgrade->migrate();
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toContain('traces', 'error_groups', '[sqlite]', '[telemetry]', 'Nothing was changed')
            ->and($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($appBefore)
            ->and($this->upgrade->rows(Databases::TELEMETRY, 'traces'))->toEqual($telemetryBefore)
            ->and(Schema::connection(Databases::TELEMETRY)->hasTable(TableMover::MARKER_TABLE))->toBeFalse();
    });

    it('copies again over an empty target table', function () {
        $this->upgrade->legacy()->seed();
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');
        leaveHalfFinishedTelemetryMove($this);

        Schema::connection(Databases::TELEMETRY)->drop(TableMover::MARKER_TABLE);
        Schema::connection(Databases::TELEMETRY)->disableForeignKeyConstraints();

        foreach (array_reverse(Databases::TELEMETRY_TABLES) as $table) {
            DB::connection(Databases::TELEMETRY)->table($table)->delete();
        }

        $this->upgrade->useSmallChunks();

        expect($this->upgrade->migrate())->toBe(0)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
    });

    it('recopies tables that the marker lists even when they hold rows', function () {
        $this->upgrade->legacy()->seed();
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');
        leaveHalfFinishedTelemetryMove($this);

        expect(DB::connection(Databases::TELEMETRY)->table(TableMover::MARKER_TABLE)->pluck('table_name')->all())->toEqualCanonicalizing(Databases::TELEMETRY_TABLES);

        DB::connection(Databases::TELEMETRY)->table('end_users')->update(['name' => 'half written']);
        $this->upgrade->useSmallChunks();

        expect($this->upgrade->migrate())->toBe(0)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
    });

    it('leaves no marker table behind', function () {
        $this->upgrade->legacy()->seed();
        $this->upgrade->migrate();

        expect($this->upgrade->tables(Databases::TELEMETRY))->not->toContain(TableMover::MARKER_TABLE)
            ->and($this->upgrade->tables(Databases::LOGS))->not->toContain(TableMover::MARKER_TABLE)
            ->and($this->upgrade->tables('sqlite'))->not->toContain(TableMover::MARKER_TABLE);

        $this->upgrade->rollback();

        expect($this->upgrade->tables('sqlite'))->not->toContain(TableMover::MARKER_TABLE)
            ->and($this->upgrade->tables(Databases::TELEMETRY))->not->toContain(TableMover::MARKER_TABLE);
    });

    it('removes a marker left by a crash between dropping the sources and cleaning up', function () {
        $this->upgrade->legacy()->seed();
        $this->upgrade->migrate();
        $before = $this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS);
        Schema::connection(Databases::TELEMETRY)->create(TableMover::MARKER_TABLE, fn ($table) => $table->string('table_name')->primary());
        DB::connection(Databases::TELEMETRY)->table(TableMover::MARKER_TABLE)->insert(['table_name' => 'traces']);

        $this->upgrade->splitMigration('telemetry')->up();

        expect($this->upgrade->tables(Databases::TELEMETRY))->not->toContain(TableMover::MARKER_TABLE)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
    });
});

describe('resetting the app database', function () {
    it('keeps every telemetry and log row on migrate:refresh --step=1', function () {
        $this->upgrade->legacy()->seed();
        $this->upgrade->migrate();
        $before = $this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS);

        expect(Artisan::call('migrate:refresh', ['--step' => 1, '--force' => true]))->toBe(0)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before)
            ->and(DB::connection(Databases::LOGS)->select("select count(*) as c from log_entries_fts where log_entries_fts match 'pay'")[0]->c)->toBeGreaterThan(0);
    });
});

describe('locking the move', function () {
    it('refuses to start a second move while one is running, and leaves both databases intact', function () {
        $this->upgrade->legacy()->seed();
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');
        $caught = null;
        $seen = null;

        $this->upgrade->useSmallChunks(afterCopy: function () use (&$caught, &$seen): void {
            if ($caught !== null) {
                return;
            }

            try {
                (new TableMover(lockTimeoutSeconds: 0))->move(telemetryTables($this), config('database.default'), Databases::TELEMETRY);
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $seen = [
                DB::table('traces')->count(),
                DB::connection(Databases::TELEMETRY)->table('traces')->count(),
                Schema::connection(Databases::TELEMETRY)->hasTable(TableMover::MARKER_TABLE),
            ];
        });

        expect($this->upgrade->migrate())->toBe(0)
            ->and($caught)->toBeInstanceOf(RuntimeException::class)
            ->and($caught->getMessage())->toContain('Another process is moving tables', 'Nothing was changed')
            ->and($seen)->toBe([$this->upgrade->rows, $this->upgrade->rows, true])
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
    });

    it('does nothing when it runs after another move finished, and keeps the lock file', function () {
        $this->upgrade->legacy()->seed();
        $this->upgrade->migrate();
        $after = $this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS);

        (new TableMover(lockTimeoutSeconds: 0))->move(telemetryTables($this), config('database.default'), Databases::TELEMETRY);

        expect($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($after)
            ->and($this->upgrade->tables('sqlite'))->not->toContain('traces', TableMover::MARKER_TABLE)
            ->and(file_exists($this->upgrade->directory.'/.watch-tower-move.lock'))->toBeTrue();
    });

    it('fails with a clear error when a table has no column in common with its new schema', function () {
        $this->upgrade->legacy();
        Schema::connection('sqlite')->create('only_derived', fn ($table) => $table->integer('seq'));
        $mover = new TableMover(forceGenericCopy: true);
        $table = new MovedTable(
            name: 'only_derived',
            columns: fn ($blueprint) => $blueprint->string('other'),
            indexes: fn () => null,
            derivedColumns: ['seq'],
        );

        expect(fn () => $mover->move([$table], 'sqlite', Databases::TELEMETRY))->toThrow(RuntimeException::class, 'no column in common');
        expect(Schema::connection('sqlite')->hasTable('only_derived'))->toBeTrue();
    });
});

describe('refusing a database reached through another path', function () {
    it('refuses when the telemetry file is a hard link to the app file', function () {
        $this->upgrade->legacy()->seed();
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');
        $app = $this->upgrade->path('app');
        $link = $this->upgrade->directory.'/hardlink.sqlite';
        link($app, $link);
        $this->upgrade->pointConnectionsAt($app, $link, $this->upgrade->path('logs'));

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'same SQLite file');

        expect($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($before)
            ->and(Schema::connection('sqlite')->hasTable('traces'))->toBeTrue();
    });
});

describe('noticing changes during the copy', function () {
    it('drops nothing when a row was updated and the count did not change', function () {
        $this->upgrade->legacy()->seed();
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');

        $this->upgrade->useSmallChunks(afterCopy: fn () => DB::table('mail_sends')->orderBy('id')->limit(1)->update(['updated_at' => '2027-01-01 00:00:00']));

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'Nothing was dropped');
        expect(Schema::connection('sqlite')->hasTable('mail_sends'))->toBeTrue()
            ->and(DB::table('mail_sends')->count())->toBe($this->upgrade->rows);

        DB::table('mail_sends')->update(['updated_at' => '2026-10-01 12:00:00']);
        $this->upgrade->useSmallChunks();

        expect($this->upgrade->migrate())->toBe(0)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
    });

    it('drops nothing when a row was deleted and replaced by an identical one', function () {
        $this->upgrade->legacy()->seed();

        $this->upgrade->useSmallChunks(afterCopy: function (): void {
            $row = (array) DB::table('mail_sends')->orderBy('id')->skip(3)->first();
            DB::table('mail_sends')->where('id', $row['id'])->delete();
            DB::table('mail_sends')->insert($row);
        });

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'Nothing was dropped')
            ->and(DB::table('mail_sends')->count())->toBe($this->upgrade->rows);
    });
});

it('counts the pages of a leftover copy as free when checking the disk', function () {
    $this->upgrade->legacy()->seed();
    $names = "'".implode("','", Databases::TELEMETRY_TABLES)."'";
    $bytes = fn (string $connection): int => (int) DB::connection($connection)->selectOne("select sum(pgsize) as bytes from dbstat where name in (select name from sqlite_master where tbl_name in ({$names}))")->bytes;
    $source = $bytes('sqlite');

    leaveHalfFinishedTelemetryMove($this);

    expect($bytes(Databases::TELEMETRY))->toBeGreaterThan($source * 0.9);

    $this->upgrade->useSmallChunks(freeDiskSpace: fn (string $directory) => (int) ($source * 1.05));

    expect($this->upgrade->migrate())->toBe(0);
});

it('names the target file when the disk is too small', function () {
    $this->upgrade->legacy()->seed();
    $this->upgrade->useSmallChunks(freeDiskSpace: fn (string $directory) => 1_000);

    try {
        $this->upgrade->migrate();
        $this->fail('The migration should have been refused.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain($this->upgrade->path('telemetry'), '[telemetry]');
    }
});

describe('copying through PHP', function () {
    it('defaults to small reads', function () {
        expect((new ReflectionProperty(TableMover::class, 'genericChunkSize'))->getValue(new TableMover))->toBe(200);
    });

    it('copies large rows in batches below the packet limit', function () {
        $this->upgrade->legacy()->seed(10);
        DB::table('log_entries')->update(['message' => str_repeat('x', 300_000)]);
        $before = $this->upgrade->rows('sqlite', 'log_entries');
        app()->bind(TableMover::class, fn (): TableMover => new TableMover(genericChunkSize: 200, forceGenericCopy: true));
        $batches = [];

        DB::listen(function (QueryExecuted $query) use (&$batches): void {
            if ($query->connectionName === Databases::LOGS && str_starts_with($query->sql, 'insert into "log_entries"')) {
                $batches[] = count(array_filter($query->bindings, fn ($binding) => is_string($binding) && strlen($binding) >= 300_000));
            }
        });

        expect($this->upgrade->migrate())->toBe(0)
            ->and($this->upgrade->rows(Databases::LOGS, 'log_entries'))->toEqual($before)
            ->and($batches)->toBe([4, 4, 2]);
    });
});

describe('vacuuming', function () {
    it('vacuums the app database once for the whole upgrade, then checkpoints it', function () {
        $this->upgrade->legacy()->seed();
        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            if ($query->connectionName === 'sqlite') {
                $statements[] = strtolower($query->sql);
            }
        });

        $this->upgrade->migrate();

        $vacuums = array_keys(array_filter($statements, fn (string $sql): bool => $sql === 'vacuum'));
        $afterVacuum = array_slice($statements, $vacuums[0] + 1);

        expect($vacuums)->toHaveCount(1)
            ->and($afterVacuum[0])->toBe('pragma wal_checkpoint(truncate)');
    });
});

it('changes nothing with --pretend', function () {
    $this->upgrade->legacy()->seed();
    $before = $this->upgrade->snapshot('sqlite', 'sqlite');

    expect(Artisan::call('migrate', ['--pretend' => true, '--force' => true]))->toBe(0);

    expect($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($before)
        ->and(file_exists($this->upgrade->path('telemetry')))->toBeFalse()
        ->and(file_exists($this->upgrade->path('logs')))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', 'like', '%move_telemetry%')->exists())->toBeFalse();
});
