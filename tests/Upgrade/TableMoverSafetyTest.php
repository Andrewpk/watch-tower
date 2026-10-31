<?php

use App\Watch\Databases;
use App\Watch\MovedTable;
use App\Watch\MoveLock;
use App\Watch\SourceChangedDuringMove;
use App\Watch\TableMover;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Upgrade\Support\SplitUpgrade;

/**
 * What the table mover does when it crashes, loses its lock or sees the source change: the data must survive.
 */
beforeEach(function () {
    $this->upgrade = new SplitUpgrade;
});

afterEach(function () {
    $this->upgrade->cleanUp();
});

/**
 * Make the move crash right after the source tables were dropped and before the marker table was, as a killed process
 * would. The copy and the fingerprints are complete at that point.
 *
 * @return object{armed: bool}
 */
function crashAfterSourceDrop(): object
{
    $switch = (object) ['armed' => true];

    DB::listen(function (QueryExecuted $query) use ($switch): void {
        if ($switch->armed && $query->connectionName === 'sqlite' && $query->sql === 'commit') {
            throw new RuntimeException('simulated crash after the source was dropped');
        }
    });

    return $switch;
}

/**
 * Rows of the telemetry tables only: the log entries are moved by the second migration, which a crash in the first
 * one never reaches.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function telemetryRows(SplitUpgrade $upgrade, string $connection): array
{
    return collect(Databases::TELEMETRY_TABLES)->mapWithKeys(fn (string $table): array => [$table => $upgrade->rows($connection, $table)])->all();
}

/**
 * Run only the telemetry move, with the given mover.
 */
function moveTelemetry(SplitUpgrade $upgrade, TableMover $mover): void
{
    $mover->move($upgrade->splitMigration('telemetry')->tables(), 'sqlite', Databases::TELEMETRY);
}

/**
 * @return array<string, string|null>
 */
function markerRows(): array
{
    return DB::connection(Databases::TELEMETRY)->table(TableMover::MARKER_TABLE)->pluck('fingerprint', 'table_name')->all();
}

/**
 * Whether each marker row has been verified, by table name.
 *
 * @return array<string, bool>
 */
function markerVerified(): array
{
    return array_map(boolval(...), DB::connection(Databases::TELEMETRY)->table(TableMover::MARKER_TABLE)->pluck('verified', 'table_name')->all());
}

describe('after a migration was killed during the same-database probe', function () {
    it('drops the leftover probe tables on both connections and nothing else', function () {
        $this->upgrade->legacy()->seed();
        $before = telemetryRows($this->upgrade, 'sqlite');

        Databases::ensureSqliteFileExists(Databases::TELEMETRY);

        foreach (['sqlite', Databases::TELEMETRY] as $connection) {
            Schema::connection($connection)->create('watch_db_probe_leftover', fn ($table) => $table->id());
            Schema::connection($connection)->create('watch_db_probes_are_not_this', fn ($table) => $table->id());
        }

        moveTelemetry($this->upgrade, new TableMover);

        foreach (['sqlite', Databases::TELEMETRY] as $connection) {
            expect($this->upgrade->tables($connection))
                ->not->toContain('watch_db_probe_leftover')
                ->toContain('watch_db_probes_are_not_this');
        }

        expect(telemetryRows($this->upgrade, Databases::TELEMETRY))->toEqual($before);
    });
});

describe('after the target became the only copy', function () {
    it('keeps the target when the app database is replaced by an empty one', function () {
        $this->upgrade->legacy()->seed();
        $before = telemetryRows($this->upgrade, 'sqlite');
        $crash = crashAfterSourceDrop();

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');

        $crash->armed = false;

        expect(Schema::connection('sqlite')->hasTable('traces'))->toBeFalse()
            ->and(markerRows())->toHaveCount(count(Databases::TELEMETRY_TABLES))
            ->and(array_filter(markerRows(), fn (?string $fingerprint): bool => $fingerprint === null))->toBe([])
            ->and(array_filter(markerVerified()))->toHaveCount(count(Databases::TELEMETRY_TABLES));

        $this->upgrade->replaceAppDatabaseWithEmptyLegacy();

        expect($this->upgrade->migrate())->toBe(0)
            ->and(telemetryRows($this->upgrade, Databases::TELEMETRY))->toEqual($before)
            ->and($this->upgrade->tables(Databases::TELEMETRY))->not->toContain(TableMover::MARKER_TABLE);
    });

    it('keeps the target when an unfinished copy is followed by an empty app database', function () {
        $this->upgrade->legacy()->seed();
        $before = telemetryRows($this->upgrade, 'sqlite');
        $this->upgrade->useSmallChunks(afterCopy: fn () => throw new RuntimeException('simulated crash'));

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');

        $this->upgrade->replaceAppDatabaseWithEmptyLegacy();
        $this->upgrade->useSmallChunks();

        expect($this->upgrade->migrate())->toBe(0)
            ->and(telemetryRows($this->upgrade, Databases::TELEMETRY))->toEqual($before);
    });

    it('refuses an older source with different rows and changes nothing', function () {
        $this->upgrade->legacy()->seed();
        $before = telemetryRows($this->upgrade, 'sqlite');
        $crash = crashAfterSourceDrop();

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');

        $crash->armed = false;
        $this->upgrade->replaceAppDatabaseWithEmptyLegacy()->seed(10);
        $olderSource = $this->upgrade->snapshot('sqlite', 'sqlite');
        $marker = markerRows();
        $message = null;

        try {
            $this->upgrade->migrate();
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toContain('already copied', 'Nothing was changed', 'delete the [telemetry] database', '-wal and -shm', 'restore the [sqlite] database from a backup taken after the move')
            ->and($message)->not->toContain('empty')
            ->and($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($olderSource)
            ->and(telemetryRows($this->upgrade, Databases::TELEMETRY))->toEqual($before)
            ->and(markerRows())->toEqual($marker);
    });

    it('finishes dropping the source after a crash in the middle of it', function () {
        $this->upgrade->legacy()->seed();
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');
        DB::statement('pragma wal_checkpoint(truncate)');
        copy($this->upgrade->path('app'), $backup = $this->upgrade->directory.'/app-backup.sqlite');
        $crash = crashAfterSourceDrop();

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');

        // A server without transactional DDL would have dropped only some of the tables: put the others back.
        $crash->armed = false;
        $this->upgrade->purge();

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->upgrade->path('app').$suffix);
        }

        copy($backup, $this->upgrade->path('app'));
        Schema::connection('sqlite')->disableForeignKeyConstraints();

        foreach (['mail_sends', 'cache_events'] as $table) {
            Schema::connection('sqlite')->drop($table);
        }

        Schema::connection('sqlite')->enableForeignKeyConstraints();

        expect(Schema::connection('sqlite')->hasTable('traces'))->toBeTrue();

        $this->upgrade->useSmallChunks();

        expect($this->upgrade->migrate())->toBe(0)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before)
            ->and(array_intersect($this->upgrade->tables('sqlite'), [...Databases::TELEMETRY_TABLES, ...Databases::LOG_TABLES]))->toBe([])
            ->and($this->upgrade->tables(Databases::TELEMETRY))->not->toContain(TableMover::MARKER_TABLE);
    });
});

describe('before the target was verified', function () {
    /**
     * Crash after the copy and before the check, leaving unverified marker rows that record the 25 row source.
     */
    function crashBeforeVerification(SplitUpgrade $upgrade): void
    {
        $upgrade->useSmallChunks(afterCopy: fn () => throw new RuntimeException('simulated crash'));

        expect(fn () => $upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');

        $upgrade->useSmallChunks();
    }

    it('records the source and leaves the rows unverified', function () {
        $this->upgrade->legacy()->seed();
        crashBeforeVerification($this->upgrade);

        expect(array_filter(markerRows()))->toHaveCount(count(Databases::TELEMETRY_TABLES))
            ->and(array_filter(markerVerified()))->toBe([]);
    });

    it('refuses an older source with different rows, changing nothing', function () {
        $this->upgrade->legacy()->seed();
        $before = telemetryRows($this->upgrade, 'sqlite');
        crashBeforeVerification($this->upgrade);

        $this->upgrade->replaceAppDatabaseWithEmptyLegacy()->seed(10);
        $olderSource = $this->upgrade->snapshot('sqlite', 'sqlite');
        $marker = markerRows();

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'Nothing was changed')
            ->and($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($olderSource)
            ->and(telemetryRows($this->upgrade, Databases::TELEMETRY))->toEqual($before)
            ->and(markerRows())->toEqual($marker);
    });

    it('refuses a new one row source, keeping the target', function () {
        $this->upgrade->legacy()->seed();
        $before = telemetryRows($this->upgrade, 'sqlite');
        crashBeforeVerification($this->upgrade);

        $this->upgrade->replaceAppDatabaseWithEmptyLegacy()->seed(1);
        $newSource = $this->upgrade->snapshot('sqlite', 'sqlite');

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'Nothing was changed')
            ->and($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($newSource)
            ->and(telemetryRows($this->upgrade, Databases::TELEMETRY))->toEqual($before);
    });

    it('copies again and finishes when the source is unchanged', function () {
        $this->upgrade->legacy()->seed();
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');
        crashBeforeVerification($this->upgrade);

        expect($this->upgrade->migrate())->toBe(0)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before)
            ->and($this->upgrade->tables(Databases::TELEMETRY))->not->toContain(TableMover::MARKER_TABLE);
    });

    it('warns that a kept copy may be partial when the source is empty', function () {
        $this->upgrade->legacy()->seed();
        $before = telemetryRows($this->upgrade, 'sqlite');
        crashBeforeVerification($this->upgrade);
        $this->upgrade->replaceAppDatabaseWithEmptyLegacy();
        Log::spy();

        expect($this->upgrade->migrate())->toBe(0)
            ->and(telemetryRows($this->upgrade, Databases::TELEMETRY))->toEqual($before);

        Log::shouldHaveReceived('warning')->with(
            Mockery::pattern('/never verified.*partial.*secondary indexes/'),
            Mockery::on(fn (array $context): bool => in_array('traces', $context['tables'], true)),
        )->once();
    });
});

describe('resuming a verified move', function () {
    it('refuses when a target table lost rows since, leaving the source whole', function () {
        $this->upgrade->legacy()->seed();
        DB::statement('pragma wal_checkpoint(truncate)');
        copy($this->upgrade->path('app'), $backup = $this->upgrade->directory.'/app-backup.sqlite');
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');
        $crash = crashAfterSourceDrop();

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');

        $crash->armed = false;
        $this->upgrade->purge();

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->upgrade->path('app').$suffix);
        }

        copy($backup, $this->upgrade->path('app'));
        DB::connection(Databases::TELEMETRY)->table('cache_events')->orderBy('id')->limit(3)->delete();
        $this->upgrade->useSmallChunks();

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'fewer rows')
            ->and($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($before)
            ->and(DB::connection(Databases::TELEMETRY)->table('cache_events')->count())->toBe($this->upgrade->rows - 3);
    });

    it('finishes when the target holds more rows than the source', function () {
        $this->upgrade->legacy()->seed();
        DB::statement('pragma wal_checkpoint(truncate)');
        copy($this->upgrade->path('app'), $backup = $this->upgrade->directory.'/app-backup.sqlite');
        $crash = crashAfterSourceDrop();

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');

        $crash->armed = false;
        $this->upgrade->purge();

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->upgrade->path('app').$suffix);
        }

        copy($backup, $this->upgrade->path('app'));
        $row = (array) DB::connection(Databases::TELEMETRY)->table('end_users')->first();
        unset($row['seq']);
        DB::connection(Databases::TELEMETRY)->table('end_users')->insert([...$row, 'id' => 'written-after-upgrade', 'identifier' => 'late']);
        $this->upgrade->useSmallChunks();

        expect($this->upgrade->migrate())->toBe(0)
            ->and(DB::connection(Databases::TELEMETRY)->table('end_users')->count())->toBe($this->upgrade->rows + 1)
            ->and(array_intersect($this->upgrade->tables('sqlite'), Databases::TELEMETRY_TABLES))->toBe([]);
    });
});

describe('sharing the marker table with another move', function () {
    /**
     * A marker row of a move that this one knows nothing about.
     */
    function addForeignMarker(SplitUpgrade $upgrade): void
    {
        touch($upgrade->path('telemetry'));
        $schema = Schema::connection(Databases::TELEMETRY);

        if (! $schema->hasTable(TableMover::MARKER_TABLE)) {
            $schema->create(TableMover::MARKER_TABLE, function ($table): void {
                $table->string('table_name')->primary();
                $table->text('fingerprint')->nullable();
                $table->boolean('verified')->default(false);
            });
        }

        DB::connection(Databases::TELEMETRY)->table(TableMover::MARKER_TABLE)->insert(['table_name' => 'another_moves_table', 'fingerprint' => 'abc', 'verified' => false]);
    }

    it('deletes only its own rows when it finishes', function () {
        $this->upgrade->legacy()->seed();
        addForeignMarker($this->upgrade);

        expect($this->upgrade->migrate())->toBe(0)
            ->and(markerRows())->toBe(['another_moves_table' => 'abc']);
    });

    it('deletes only its own rows when it has nothing left to move', function () {
        $this->upgrade->legacy()->seed();
        $this->upgrade->migrate();
        addForeignMarker($this->upgrade);
        DB::connection(Databases::TELEMETRY)->table(TableMover::MARKER_TABLE)->insert(['table_name' => 'traces', 'fingerprint' => 'x', 'verified' => true]);

        $this->upgrade->splitMigration('telemetry')->up();

        expect(markerRows())->toBe(['another_moves_table' => 'abc']);
    });
});

describe('checking the privilege to lock tables', function () {
    /**
     * @return array{0: Connection&MockInterface, 1: MovedTable}
     */
    function fakeMoverSourceConnection(string $driver): array
    {
        $connection = Mockery::mock(Connection::class);
        $grammar = Mockery::mock(Grammar::class);
        $grammar->shouldReceive('wrap')->andReturnUsing(fn (string $name): string => "`{$name}`");
        $connection->shouldReceive('getDriverName')->andReturn($driver);
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);
        $connection->shouldReceive('getDatabaseName')->andReturn('watch');

        return [$connection, new MovedTable('traces', fn () => null, fn () => null)];
    }

    function assertMoverCanLockSource(Connection $connection, MovedTable $table): void
    {
        (new ReflectionMethod(TableMover::class, 'assertCanLockSource'))->invoke(new TableMover, $connection, 'mysql', $table);
    }

    it('read locks the first table to copy and unlocks it again', function () {
        [$connection, $table] = fakeMoverSourceConnection('mysql');
        $connection->shouldReceive('unprepared')->once()->ordered()->with('lock tables `traces` read');
        $connection->shouldReceive('unprepared')->once()->ordered()->with('unlock tables');

        assertMoverCanLockSource($connection, $table);
    });

    it('names the grant when the lock is refused', function () {
        [$connection, $table] = fakeMoverSourceConnection('mariadb');
        $connection->shouldReceive('unprepared')->once()->with('lock tables `traces` read')->andThrow(new RuntimeException('Access denied for user'));

        expect(fn () => assertMoverCanLockSource($connection, $table))
            ->toThrow(RuntimeException::class, 'LOCK TABLES');
    });

    it('does nothing on SQLite', function () {
        [$connection, $table] = fakeMoverSourceConnection('sqlite');
        $connection->shouldNotReceive('unprepared');

        assertMoverCanLockSource($connection, $table);
    });
});

describe('fingerprinting a table', function () {
    it('tells two sources apart even when their ids are not valid UTF-8', function () {
        $this->upgrade->legacy();
        Schema::connection('sqlite')->create('fingerprint_probe', fn ($table) => $table->string('id')->primary());
        $fingerprint = fn (): string => (new ReflectionMethod(TableMover::class, 'fingerprint'))
            ->invoke(new TableMover, DB::connection('sqlite'), new MovedTable('fingerprint_probe', fn () => null, fn () => null), false);

        DB::table('fingerprint_probe')->insert(['id' => "\xff"]);
        $first = $fingerprint();
        DB::table('fingerprint_probe')->delete();
        DB::table('fingerprint_probe')->insert(['id' => "\xfe"]);

        expect($fingerprint())->not->toBe($first)
            ->and($first)->not->toBe('');
    });
});

describe('losing the lock', function () {
    /**
     * A lock that records every check in `$events`, and fails the check numbered `$failAt` (1 based).
     *
     * @param  list<string>  $events
     */
    function recordingLock(array &$events, ?int $failAt = null): MoveLock
    {
        $checks = 0;

        return new MoveLock(
            release: fn () => $events[] = 'release',
            assertHeld: function () use (&$events, &$checks, $failAt): void {
                $events[] = 'check';

                if (++$checks === $failAt) {
                    throw new RuntimeException('the migration lost its database lock');
                }
            },
        );
    }

    function recordDrops(array &$events): void
    {
        DB::listen(function (QueryExecuted $query) use (&$events): void {
            if (str_starts_with($query->sql, 'drop table') && ! str_contains($query->sql, TableMover::MARKER_TABLE)) {
                $events[] = "drop:{$query->connectionName}";
            }
        });
    }

    it('checks the lock right before every table it drops, in the target and in the source', function () {
        $this->upgrade->legacy()->seed();
        $events = [];
        recordDrops($events);
        moveTelemetry($this->upgrade, new TableMover(lock: recordingLock($events)));

        $drops = array_keys(array_filter($events, fn (string $event): bool => str_starts_with($event, 'drop:sqlite')));

        expect($drops)->toHaveCount(count(Databases::TELEMETRY_TABLES));

        foreach ($drops as $position) {
            expect($events[$position - 1])->toBe('check');
        }
    });

    it('checks the lock before dropping a leftover copy in the target', function () {
        $this->upgrade->legacy()->seed();
        $this->upgrade->useSmallChunks(afterCopy: fn () => throw new RuntimeException('simulated crash'));

        expect(fn () => $this->upgrade->migrate())->toThrow(RuntimeException::class, 'simulated crash');

        $events = [];
        recordDrops($events);
        moveTelemetry($this->upgrade, new TableMover(lock: recordingLock($events)));

        $targetDrops = array_keys(array_filter($events, fn (string $event): bool => $event === 'drop:'.Databases::TELEMETRY));

        expect($targetDrops)->not->toBeEmpty();

        foreach ($targetDrops as $position) {
            expect($events[$position - 1])->toBe('check');
        }
    });

    it('drops nothing further when the lock is lost, and a rerun finishes', function () {
        $this->upgrade->legacy()->seed();
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');
        $events = [];

        expect(fn () => moveTelemetry($this->upgrade, new TableMover(lock: recordingLock($events, failAt: 4))))
            ->toThrow(RuntimeException::class, 'lost its database lock');

        foreach (Databases::TELEMETRY_TABLES as $table) {
            expect(Schema::connection('sqlite')->hasTable($table))->toBeTrue($table);
        }

        $this->upgrade->useSmallChunks();

        expect($this->upgrade->migrate())->toBe(0)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
    });
});

describe('keeping writers out between the check and the drop', function () {
    it('takes the write lock before verifying and holds it until the last drop', function () {
        $this->upgrade->legacy()->seed();
        $events = [];

        DB::listen(function (QueryExecuted $query) use (&$events): void {
            if ($query->connectionName !== 'sqlite') {
                return;
            }

            $events[] = match (true) {
                $query->sql === 'begin immediate' => 'begin',
                $query->sql === 'commit' => 'commit',
                $query->sql === 'rollback' => 'rollback',
                str_starts_with($query->sql, 'drop table') => 'drop',
                str_starts_with($query->sql, 'select count(*) as aggregate') => 'count',
                default => 'other',
            };
        });

        moveTelemetry($this->upgrade, new TableMover(afterCopy: function () use (&$events): void {
            $events[] = 'after-copy';
        }));

        $afterCopy = array_search('after-copy', $events, true);
        $tail = array_slice($events, $afterCopy + 1, preserve_keys: true);
        $begin = array_search('begin', $tail, true);
        $commit = array_search('commit', $tail, true);

        $positions = fn (string $kind): array => array_keys(array_filter($tail, fn (string $event): bool => $event === $kind));

        expect($positions('begin'))->toHaveCount(1)
            ->and($positions('commit'))->toHaveCount(1)
            ->and($positions('count'))->not->toBeEmpty()
            ->and($positions('drop'))->toHaveCount(count(Databases::TELEMETRY_TABLES))
            ->and(min($positions('count')))->toBeGreaterThan($begin)
            ->and(max($positions('count')))->toBeLessThan(min($positions('drop')))
            ->and(min($positions('drop')))->toBeGreaterThan($begin)
            ->and(max($positions('drop')))->toBeLessThan($commit);
    });

    it('rolls back when the check fails, leaving every source table in place', function () {
        $this->upgrade->legacy()->seed();
        $events = [];

        DB::listen(function (QueryExecuted $query) use (&$events): void {
            if ($query->connectionName === 'sqlite' && in_array($query->sql, ['begin immediate', 'commit', 'rollback'], true)) {
                $events[] = $query->sql;
            }
        });
        $this->upgrade->useSmallChunks(afterCopy: fn () => DB::table('mail_sends')->orderBy('id')->limit(1)->update(['updated_at' => '2027-01-01 00:00:00']));

        expect(fn () => $this->upgrade->migrate())->toThrow(SourceChangedDuringMove::class);

        expect($events)->toBe(['begin immediate', 'rollback'])
            ->and(DB::connection('sqlite')->getPdo()->inTransaction())->toBeFalse();

        foreach (Databases::TELEMETRY_TABLES as $table) {
            expect(Schema::connection('sqlite')->hasTable($table))->toBeTrue($table);
        }
    });

    it('does not let another connection write to the source between the check and the drops', function () {
        $this->upgrade->legacy()->seed();
        DB::statement('pragma wal_checkpoint(truncate)');
        $blocked = null;
        $events = [];

        $lock = new MoveLock(
            release: fn () => null,
            assertHeld: function () use (&$blocked, &$events): void {
                $events[] = 'check';

                try {
                    $other = new PDO('sqlite:'.$this->upgrade->path('app'));
                    $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $other->exec('pragma busy_timeout = 0');
                    $other->exec("update users set name = 'sneaked in' where id = 1");
                    $blocked = false;
                } catch (PDOException $exception) {
                    $blocked ??= str_contains($exception->getMessage(), 'locked');
                }
            },
        );
        moveTelemetry($this->upgrade, new TableMover(lock: $lock));

        expect($blocked)->toBeTrue()
            ->and(DB::table('users')->where('id', 1)->value('name'))->toBe('User 1');
    });
});

describe('a copy that failed its verification', function () {
    it('is thrown away, leaving the source whole and a rerun possible', function () {
        $this->upgrade->legacy()->seed();
        $before = $this->upgrade->snapshot('sqlite', 'sqlite');

        $this->upgrade->useSmallChunks(afterCopy: fn () => DB::table('mail_sends')->insert([
            'id' => 'late-arrival',
            'project_id' => DB::table('projects')->value('id'),
            'mailable_class' => 'App\\Mail\\Late',
            'status' => 'sent',
            'occurred_at' => '2026-10-02 00:00:00',
        ]));

        expect(fn () => $this->upgrade->migrate())->toThrow(SourceChangedDuringMove::class, 'Nothing was dropped');

        expect($this->upgrade->tables(Databases::TELEMETRY))->toBe([])
            ->and(Schema::connection(Databases::TELEMETRY)->hasTable(TableMover::MARKER_TABLE))->toBeFalse();

        DB::table('mail_sends')->where('id', 'late-arrival')->delete();

        expect($this->upgrade->snapshot('sqlite', 'sqlite'))->toEqual($before);

        $this->upgrade->useSmallChunks();

        expect($this->upgrade->migrate())->toBe(0)
            ->and($this->upgrade->snapshot(Databases::TELEMETRY, Databases::LOGS))->toEqual($before);
    });

    it('does not hide the verification error when the cleanup fails too', function () {
        $this->upgrade->legacy()->seed();

        $this->upgrade->useSmallChunks(afterCopy: function (): void {
            DB::table('mail_sends')->orderBy('id')->limit(1)->update(['updated_at' => '2027-01-01 00:00:00']);
            Schema::connection(Databases::TELEMETRY)->dropIfExists('watch_table_moves');
        });

        expect(fn () => $this->upgrade->migrate())->toThrow(SourceChangedDuringMove::class, 'Nothing was dropped');
    });
});

describe('taking the lock first', function () {
    /**
     * Hold the move lock the way another migrator would.
     *
     * @return resource
     */
    function holdMoveLock(SplitUpgrade $upgrade)
    {
        $handle = fopen($upgrade->directory.'/.watch-tower-move.lock', 'c');
        flock($handle, LOCK_EX);

        return $handle;
    }

    it('waits for the lock before deciding that both connections are one database', function () {
        $this->upgrade->legacy()->seed();
        $link = $this->upgrade->directory.'/hardlink.sqlite';
        link($this->upgrade->path('app'), $link);
        $this->upgrade->pointConnectionsAt($this->upgrade->path('app'), $link, $this->upgrade->path('logs'));
        $handle = holdMoveLock($this->upgrade);

        try {
            expect(fn () => (new TableMover(lockTimeoutSeconds: 0))->move($this->upgrade->splitMigration('telemetry')->tables(), 'sqlite', Databases::TELEMETRY))
                ->toThrow(RuntimeException::class, 'Another process is moving tables');
        } finally {
            flock($handle, LOCK_UN);
        }
    });

    it('waits for the lock before connecting to the databases', function () {
        $this->upgrade->legacy();
        $this->upgrade->pointConnectionsAt($this->upgrade->directory, $this->upgrade->path('telemetry'), $this->upgrade->path('logs'));
        touch($this->upgrade->path('telemetry'));
        $handle = holdMoveLock($this->upgrade);

        try {
            expect(fn () => (new TableMover(lockTimeoutSeconds: 0))->move($this->upgrade->splitMigration('telemetry')->tables(), 'sqlite', Databases::TELEMETRY))
                ->toThrow(RuntimeException::class, 'Another process is moving tables');
        } finally {
            flock($handle, LOCK_UN);
        }
    });
});
