<?php

namespace App\Watch;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use RuntimeException;
use Throwable;

/**
 * Moves tables from one database connection to another, without losing a row.
 *
 * This is the engine behind the migrations that split telemetry and logs out of the app database. It is built to be
 * re-run safely after a crash: nothing is dropped from the source until every table has been copied and checked, and a
 * second run starts from whatever the first one left behind.
 *
 * The whole move runs under a lock ({@see MoveLock}), so two migrators never interleave, and everything else (reaching
 * the databases, comparing them, deciding what to do) happens inside it.
 *
 * What is checked: before the source tables are dropped, each copied table must have the same number of rows in both
 * databases, and its source must still have the fingerprint taken just before its copy (the row count and the highest
 * `id`, `updated_at` and rowid). That detects most writes during the copy, not all: an update that leaves `updated_at`
 * alone, or a delete followed by an insert that reuses the same values, goes unseen, and the contents of the rows are
 * never compared. Stopping everything that writes to the source is the real protection; the checks are the backstop.
 * The check and the drop run together while the source is locked against writers (SQLite `begin immediate`, MySQL
 * `LOCK TABLES`), so nothing can slip in between them. When the check fails, the copy made by this run is thrown away
 * and the source is untouched.
 *
 * Every table is classified from what both sides hold before anything changes:
 *
 * - in the source only: copied;
 * - in the target only: already moved, left alone;
 * - in neither: created empty;
 * - in both, the source empty and the target not: the target is kept and the empty source dropped;
 * - in both, the target empty: dropped and copied again;
 * - in both with rows on each side: decided by the marker table `watch_table_moves` in the target.
 *
 * The marker table records what a run did to the target, one row per table:
 *
 * - a row with no fingerprint is written before the target is touched. The target table is a disposable, possibly
 *   half-written copy; a rerun drops it and copies again;
 * - the fingerprint of the verified source is written in one transaction right before the first source table is
 *   dropped. From then on the target is the only complete copy and the source is a leftover. A rerun that finds the
 *   source unchanged just finishes dropping it; one that finds the source different (an older backup restored, say)
 *   stops, because nobody can tell which copy is the real one;
 * - no row at all: two copies with rows and no record of either. The move stops before changing anything.
 *
 * The marker table is dropped last.
 *
 * When both connections turn out to be the same database nothing can be copied. SQLite refuses (the file is shared, so
 * the move would destroy it); other servers keep the tables where they are and only drop the foreign keys to tables of
 * the other databases, or restore them when `$legacySchema` is set.
 *
 * Callers must not run inside a transaction: the move turns foreign keys off and vacuums, and neither works there.
 */
final class TableMover
{
    private const ATTACH_ALIAS = 'watch_move_target';

    /**
     * Table in the target database that records the tables of a move in progress.
     */
    public const MARKER_TABLE = 'watch_table_moves';

    /**
     * Strings are flushed to the target in one insert once a batch holds this many bytes of them.
     */
    private const INSERT_BYTE_LIMIT = 1_048_576;

    /**
     * Tables that stay in the app database and that moved tables used to reference.
     *
     * @var list<string>
     */
    private const APP_TABLES = ['projects', 'users'];

    /**
     * @param  int  $chunkSize  Rows per transaction when copying inside SQLite (a rowid range).
     * @param  int  $genericChunkSize  Rows per read and transaction when copying through PHP.
     * @param  bool  $forceGenericCopy  Copy through PHP even when both sides are SQLite files.
     * @param  (Closure(string): (int|float|false))|null  $freeDiskSpace  Free bytes in a directory. Defaults to `disk_free_space`.
     * @param  (Closure(): void)|null  $afterCopy  Called once everything is copied, before the copies are verified.
     * @param  int  $lockTimeoutSeconds  How long to wait for another move to finish before giving up.
     * @param  MoveLock|null  $lock  Use this lock instead of taking one. For tests.
     */
    public function __construct(
        private readonly int $chunkSize = 50_000,
        private readonly int $genericChunkSize = 200,
        private readonly bool $forceGenericCopy = false,
        private readonly ?Closure $freeDiskSpace = null,
        private readonly ?Closure $afterCopy = null,
        private readonly int $lockTimeoutSeconds = 3600,
        private readonly ?MoveLock $lock = null,
    ) {}

    /**
     * Move the tables from one connection to another.
     *
     * @param  list<MovedTable>  $tables  Parents before the tables that reference them.
     * @param  string  $from  Connection that holds the tables now.
     * @param  string  $to  Connection that should hold them.
     * @param  bool  $legacySchema  Build the pre-split schema in the target (foreign keys to projects and users), for rollbacks.
     * @param  bool  $shrinkSource  Vacuum the source file afterwards (SQLite). Pass false when the caller is about to move
     *                              more tables out of it.
     */
    public function move(array $tables, string $from, string $to, bool $legacySchema = false, bool $shrinkSource = true): void
    {
        $source = DB::connection($from);
        $target = DB::connection($to);

        if ($source->pretending() || $target->pretending()) {
            return;
        }

        Databases::ensureSqliteFileExists($from);
        Databases::ensureSqliteFileExists($to);

        $lock = $this->lock ?? MoveLock::acquire($source, $from, $target, $to, $this->lockTimeoutSeconds);

        try {
            $copied = $this->moveLocked($tables, $source, $from, $target, $to, $legacySchema, $lock);
        } finally {
            $lock->release();
        }

        if ($copied && $shrinkSource) {
            $this->shrink($source, $from);
        }
    }

    /**
     * Classify the tables, then copy and verify them. Returns whether any table left the source.
     *
     * @param  list<MovedTable>  $tables
     */
    private function moveLocked(array $tables, Connection $source, string $from, Connection $target, string $to, bool $legacySchema, MoveLock $lock): bool
    {
        $this->assertReachable($source, $from);
        $this->assertReachable($target, $to);

        if (Databases::sameDatabase($from, $to)) {
            $this->keepInPlace($tables, $source, $from, $to, $legacySchema);

            return false;
        }

        $sourceSchema = $source->getSchemaBuilder();
        $targetSchema = $target->getSchemaBuilder();

        $plan = $this->plan($tables, $source, $from, $target, $to);
        $toCopy = $plan['copy'];
        $emptied = $plan['emptySource'];
        $resumed = array_values(array_filter($tables, fn (MovedTable $table): bool => isset($plan['resume'][$table->name])));

        if ($toCopy === [] && $plan['create'] === [] && $emptied === [] && $resumed === []) {
            $targetSchema->dropIfExists(self::MARKER_TABLE);

            return false;
        }

        foreach ($emptied as $table) {
            Log::warning('The new database already holds this table and the old one is empty; keeping the new rows and dropping the old table.', [
                'table' => $table->name,
                'from' => $from,
                'to' => $to,
            ]);
        }

        if ($toCopy !== []) {
            $this->assertEnoughDiskSpace($source, $from, $target, $to, $toCopy, $plan['dropTarget']);
        }

        $work = array_values(array_filter(
            $tables,
            fn (MovedTable $table): bool => in_array($table, $toCopy, true) || in_array($table, $plan['create'], true),
        ));

        $this->writeMarker($targetSchema, $target, $work);

        $sourceSchema->disableForeignKeyConstraints();
        $targetSchema->disableForeignKeyConstraints();

        try {
            foreach (array_reverse($plan['dropTarget']) as $table) {
                $lock->assertHeld();
                $targetSchema->dropIfExists($table->name);
            }

            foreach ($work as $table) {
                $this->createTable($targetSchema, $table, $legacySchema, $target->getDriverName());
            }

            $verified = $this->copyRows($toCopy, $source, $from, $target, $to);

            foreach ($work as $table) {
                $targetSchema->table($table->name, fn (Blueprint $blueprint) => ($table->indexes)($blueprint, $legacySchema, $target->getDriverName()));
            }

            $this->settle($target);

            if ($this->afterCopy !== null) {
                ($this->afterCopy)();
            }

            $leaving = array_values(array_filter(
                $tables,
                fn (MovedTable $table): bool => in_array($table, $toCopy, true) || in_array($table, $emptied, true) || in_array($table, $resumed, true),
            ));

            try {
                $this->dropSourceTables($leaving, $toCopy, $emptied, [...$plan['resume'], ...$verified], $source, $target, $from, $to, $lock);
            } catch (SourceChangedDuringMove $exception) {
                $this->discardCopy($target, $work);

                throw $exception;
            }

            $targetSchema->dropIfExists(self::MARKER_TABLE);
        } finally {
            $sourceSchema->enableForeignKeyConstraints();
            $targetSchema->enableForeignKeyConstraints();
        }

        return $leaving !== [];
    }

    /**
     * Decide what happens to every table before anything is changed, and refuse when that cannot be decided safely.
     *
     * `resume` holds the tables whose source is a verified leftover of a move that crashed while dropping it, with the
     * fingerprint recorded for each.
     *
     * @param  list<MovedTable>  $tables
     * @return array{copy: list<MovedTable>, create: list<MovedTable>, dropTarget: list<MovedTable>, emptySource: list<MovedTable>, resume: array<string, string>}
     */
    private function plan(array $tables, Connection $source, string $from, Connection $target, string $to): array
    {
        $sourceSchema = $source->getSchemaBuilder();
        $targetSchema = $target->getSchemaBuilder();

        /** @var array<string, string|null> $markers */
        $markers = $targetSchema->hasTable(self::MARKER_TABLE)
            ? $target->table(self::MARKER_TABLE)->pluck('fingerprint', 'table_name')->all()
            : [];

        $plan = ['copy' => [], 'create' => [], 'dropTarget' => [], 'emptySource' => [], 'resume' => []];
        $unmarked = [];
        $changed = [];

        foreach ($tables as $table) {
            $inSource = $sourceSchema->hasTable($table->name);
            $inTarget = $targetSchema->hasTable($table->name);

            if (! $inSource && ! $inTarget) {
                $plan['create'][] = $table;
            } elseif (! $inSource) {
                continue;
            } elseif (! $inTarget) {
                $plan['copy'][] = $table;
            } elseif (! $source->table($table->name)->exists()) {
                $targetHasRows = $target->table($table->name)->exists();

                if ($targetHasRows) {
                    $plan['emptySource'][] = $table;
                } else {
                    $plan['copy'][] = $table;
                    $plan['dropTarget'][] = $table;
                }
            } elseif (! $target->table($table->name)->exists()) {
                $plan['copy'][] = $table;
                $plan['dropTarget'][] = $table;
            } elseif (! array_key_exists($table->name, $markers)) {
                $unmarked[] = $table->name;
            } elseif ($markers[$table->name] === null) {
                $plan['copy'][] = $table;
                $plan['dropTarget'][] = $table;
            } elseif ($this->fingerprint($source, $table, $this->hasUpdatedAt($source, $table)) === $markers[$table->name]) {
                $plan['resume'][$table->name] = $markers[$table->name];
            } else {
                $changed[] = $table->name;
            }
        }

        $this->assertNoConflict($unmarked, $changed, $from, $to);

        return $plan;
    }

    /**
     * @param  list<string>  $unmarked  Tables with rows on both sides and no record of either.
     * @param  list<string>  $changed  Tables whose source is no longer the one that was verified.
     */
    private function assertNoConflict(array $unmarked, array $changed, string $from, string $to): void
    {
        $reasons = [];

        if ($unmarked !== []) {
            $reasons[] = sprintf('Both the [%s] and [%s] databases hold rows in %s, and nothing says which copy is the real one.', $from, $to, implode(', ', $unmarked));
        }

        if ($changed !== []) {
            $reasons[] = sprintf('The rows in %s were already copied from [%s] to [%s] and verified, but [%s] holds different rows now (an older database restored, or rows written since?).', implode(', ', $changed), $from, $to, $from);
        }

        if ($reasons !== []) {
            throw new RuntimeException(implode(' ', $reasons).' Nothing was changed. Stop the app, keep the database that has the data you want, empty or drop these tables in the other one, and run the migration again.');
        }
    }

    /**
     * Record the tables this run is about to (re)create in the target, so a rerun after a crash knows they are leftovers.
     * Their fingerprint stays empty until the copy is verified.
     *
     * @param  list<MovedTable>  $work
     */
    private function writeMarker(Builder $targetSchema, Connection $target, array $work): void
    {
        if ($work === []) {
            return;
        }

        if (! $targetSchema->hasTable(self::MARKER_TABLE)) {
            $targetSchema->create(self::MARKER_TABLE, function (Blueprint $blueprint): void {
                $blueprint->string('table_name')->primary();
                $blueprint->text('fingerprint')->nullable();
            });
        }

        $target->table(self::MARKER_TABLE)->upsert(
            array_map(fn (MovedTable $table): array => ['table_name' => $table->name, 'fingerprint' => null], $work),
            ['table_name'],
            ['fingerprint'],
        );
    }

    /**
     * Throw away what a run that failed its verification put in the target, so a writer on the new version cannot add
     * rows to a copy that the next run would discard. The source is untouched at that point. Failures here are ignored:
     * the verification error is the one to report.
     *
     * @param  list<MovedTable>  $work  The tables this run created.
     */
    private function discardCopy(Connection $target, array $work): void
    {
        try {
            $schema = $target->getSchemaBuilder();

            foreach (array_reverse($work) as $table) {
                $schema->dropIfExists($table->name);
            }

            if ($schema->hasTable(self::MARKER_TABLE)) {
                $target->table(self::MARKER_TABLE)->whereIn('table_name', array_map(fn (MovedTable $table): string => $table->name, $work))->delete();

                if (! $target->table(self::MARKER_TABLE)->exists()) {
                    $schema->dropIfExists(self::MARKER_TABLE);
                }
            }
        } catch (Throwable) {
            // The next run treats whatever is left as a disposable copy.
        }
    }

    /**
     * Both connections are one database: leave the tables where they are.
     *
     * @param  list<MovedTable>  $tables
     */
    private function keepInPlace(array $tables, Connection $source, string $from, string $to, bool $legacySchema): void
    {
        if ($source->getDriverName() === 'sqlite') {
            $split = in_array($to, [Databases::TELEMETRY, Databases::LOGS], true) ? $to : $from;
            $variable = 'DB_'.strtoupper($split).'_DATABASE';

            throw new RuntimeException(sprintf(
                'The [%s] and [%s] connections open the same SQLite file (%s). Moving tables onto themselves would destroy them, so nothing was changed. Set %s to a different file, or leave it unset to use the default beside the app database.',
                $from,
                $to,
                Databases::sqlitePath($from) ?? 'unknown',
                $variable,
            ));
        }

        $schema = $source->getSchemaBuilder();

        foreach ($tables as $table) {
            if (! $schema->hasTable($table->name)) {
                continue;
            }

            if ($legacySchema) {
                $this->restoreLegacyForeignKeys($schema, $table);
            } else {
                $this->dropLegacyForeignKeys($schema, $table);
            }
        }
    }

    /**
     * Drop the foreign keys that point at tables of the app database.
     */
    private function dropLegacyForeignKeys(Builder $schema, MovedTable $table): void
    {
        foreach ($schema->getForeignKeys($table->name) as $foreignKey) {
            if (! in_array($foreignKey['foreign_table'], self::APP_TABLES, true) || $foreignKey['name'] === null) {
                continue;
            }

            $schema->table($table->name, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreignKey['name']));
        }
    }

    /**
     * Put back the foreign keys to the app tables that a split removed.
     */
    private function restoreLegacyForeignKeys(Builder $schema, MovedTable $table): void
    {
        $existing = array_map(
            fn (array $foreignKey): string => implode(',', $foreignKey['columns']).'>'.$foreignKey['foreign_table'],
            $schema->getForeignKeys($table->name),
        );

        foreach ($table->legacyForeignKeys as $foreignKey) {
            if (in_array($foreignKey[0].'>'.$foreignKey[1], $existing, true)) {
                continue;
            }

            try {
                $schema->table($table->name, fn (Blueprint $blueprint) => $this->addForeignKey($blueprint, $foreignKey));
            } catch (Throwable $exception) {
                throw new RuntimeException("Could not restore the foreign key {$table->name}.{$foreignKey[0]} to {$foreignKey[1]}. Rows that point at a deleted {$foreignKey[1]} row must be removed first: ".$exception->getMessage(), previous: $exception);
            }
        }
    }

    /**
     * A clear failure for an unreachable database, instead of a driver error from the middle of the move.
     */
    private function assertReachable(Connection $connection, string $name): void
    {
        try {
            $connection->getPdo();
        } catch (Throwable $exception) {
            throw new RuntimeException("Cannot connect to the [{$name}] database, so nothing was moved: ".$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Refuse to start when the target's disk cannot hold a copy of the tables. Only SQLite files can be measured. The
     * pages of target tables that are about to be dropped are reused by the copy, so they do not count.
     *
     * @param  list<MovedTable>  $tables
     * @param  list<MovedTable>  $reusable  Tables of the target that will be dropped first.
     */
    private function assertEnoughDiskSpace(Connection $source, string $from, Connection $target, string $to, array $tables, array $reusable): void
    {
        if ($target->getDriverName() !== 'sqlite') {
            return;
        }

        $targetPath = Databases::sqlitePath($to);

        if ($targetPath === null) {
            return;
        }

        $directory = dirname($targetPath);
        $needed = max(0, (int) ceil($this->sourceBytes($source, $from, $tables) * 1.1) - $this->tableBytes($target, $reusable));
        $free = $this->freeDiskSpace !== null ? ($this->freeDiskSpace)($directory) : @disk_free_space($directory);

        if ($free !== false && $free < $needed) {
            throw new RuntimeException(sprintf(
                'Not enough free disk space in %s for the [%s] database file %s: moving these tables needs about %s and only %s is free. Nothing was changed. Free up space or point the [%s] database at a bigger disk.',
                $directory,
                $to,
                $targetPath,
                Number::fileSize($needed),
                Number::fileSize((int) $free),
                $to,
            ));
        }
    }

    /**
     * Bytes the tables and their indexes occupy, or 0 when SQLite was built without the `dbstat` table.
     *
     * @param  list<MovedTable>  $tables
     */
    private function tableBytes(Connection $connection, array $tables): int
    {
        if ($tables === []) {
            return 0;
        }

        $names = array_map(fn (MovedTable $table): string => $table->name, $tables);
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        try {
            $row = $connection->selectOne(
                "select coalesce(sum(pgsize), 0) as bytes from dbstat where name in (select name from sqlite_master where tbl_name in ({$placeholders}))",
                $names,
            );

            return (int) ($row->bytes ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Bytes the tables and their indexes occupy in the source. Falls back to the size of the whole file (plus its
     * write-ahead log) when SQLite was built without the `dbstat` table.
     *
     * @param  list<MovedTable>  $tables
     */
    private function sourceBytes(Connection $source, string $from, array $tables): int
    {
        $names = array_map(fn (MovedTable $table): string => $table->name, $tables);
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        try {
            $row = $source->selectOne(
                "select coalesce(sum(pgsize), 0) as bytes from dbstat where name in (select name from sqlite_master where tbl_name in ({$placeholders}))",
                $names,
            );

            return (int) ($row->bytes ?? 0);
        } catch (Throwable) {
            $path = Databases::sqlitePath($from);

            if ($path === null) {
                return 0;
            }

            return (int) (@filesize($path) ?: 0) + (int) (@filesize($path.'-wal') ?: 0);
        }
    }

    /**
     * Create a table from its frozen schema.
     */
    private function createTable(Builder $schema, MovedTable $table, bool $legacySchema, string $driver): void
    {
        $schema->create($table->name, function (Blueprint $blueprint) use ($table, $legacySchema, $driver): void {
            ($table->columns)($blueprint, $legacySchema, $driver);

            foreach ($table->foreignKeys as $foreignKey) {
                $this->addForeignKey($blueprint, $foreignKey);
            }

            if ($legacySchema) {
                foreach ($table->legacyForeignKeys as $foreignKey) {
                    $this->addForeignKey($blueprint, $foreignKey);
                }
            }
        });
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $foreignKey  Column, referenced table, on delete action.
     */
    private function addForeignKey(Blueprint $blueprint, array $foreignKey): void
    {
        $blueprint->foreign($foreignKey[0])->references('id')->on($foreignKey[1])->onDelete($foreignKey[2]);
    }

    /**
     * Copy every table's rows and log what was done. Returns each table's fingerprint from just before its copy.
     *
     * @param  list<MovedTable>  $tables
     * @return array<string, string>
     */
    private function copyRows(array $tables, Connection $source, string $from, Connection $target, string $to): array
    {
        $fingerprints = [];

        if ($tables === []) {
            return $fingerprints;
        }

        $targetPath = $this->forceGenericCopy || $source->getDriverName() !== 'sqlite' || $target->getDriverName() !== 'sqlite'
            ? null
            : Databases::sqlitePath($to);

        $attached = $targetPath !== null && Databases::sqlitePath($from) !== null;

        if ($attached) {
            $source->statement('attach database ? as '.self::ATTACH_ALIAS, [$targetPath]);
        }

        try {
            foreach ($tables as $table) {
                $startedAt = microtime(true);
                $columns = $this->copyColumns($table, $source->getSchemaBuilder(), $target->getSchemaBuilder());
                $fingerprints[$table->name] = $this->fingerprint($source, $table, $this->hasUpdatedAt($source, $table));

                $rows = $attached
                    ? $this->copyAttached($table, $source, $columns)
                    : $this->copyThroughPhp($table, $source, $target, $columns);

                Log::info('Copied table to its new database.', [
                    'table' => $table->name,
                    'from' => $from,
                    'to' => $to,
                    'rows' => $rows,
                    'seconds' => round(microtime(true) - $startedAt, 3),
                ]);
            }
        } finally {
            if ($attached) {
                $source->statement('detach database '.self::ATTACH_ALIAS);
            }
        }

        return $fingerprints;
    }

    /**
     * A cheap summary of a table that changes when most writes happen: the row count and the highest `id`, `updated_at`
     * and (SQLite) rowid, as JSON. It is not a checksum. It misses an update that leaves `updated_at` alone, and a delete
     * followed by an insert that restores the same maximums, so it narrows the doubt without removing it.
     */
    private function fingerprint(Connection $connection, MovedTable $table, bool $withUpdatedAt): string
    {
        $grammar = $connection->getQueryGrammar();
        $selects = ['count(*) as row_count', 'max('.$grammar->wrap('id').') as max_id'];

        if ($withUpdatedAt) {
            $selects[] = 'max('.$grammar->wrap('updated_at').') as max_updated_at';
        }

        if ($connection->getDriverName() === 'sqlite') {
            $selects[] = 'max(rowid) as max_rowid';
        }

        return (string) json_encode((array) $connection->table($table->name)->selectRaw(implode(', ', $selects))->first());
    }

    /**
     * Looked up apart from the fingerprint because a locked MySQL session may only read the tables it locked.
     */
    private function hasUpdatedAt(Connection $connection, MovedTable $table): bool
    {
        return $connection->getSchemaBuilder()->hasColumn($table->name, 'updated_at');
    }

    /**
     * Columns present on both sides, in source order. Fails if the source has a column the target lacks, so a schema
     * that drifted from the frozen one can never silently lose data.
     *
     * @return list<string>
     */
    private function copyColumns(MovedTable $table, Builder $sourceSchema, Builder $targetSchema): array
    {
        $sourceColumns = $sourceSchema->getColumnListing($table->name);
        $targetColumns = $targetSchema->getColumnListing($table->name);

        $lost = array_diff($sourceColumns, $targetColumns, $table->derivedColumns);

        if ($lost !== []) {
            throw new RuntimeException("The {$table->name} table has columns the new schema does not: ".implode(', ', $lost).'. Nothing was changed in the source.');
        }

        return array_values(array_intersect($sourceColumns, $targetColumns));
    }

    /**
     * SQLite to SQLite: copy rowid ranges inside the engine, each in its own transaction.
     *
     * @param  list<string>  $columns
     */
    private function copyAttached(MovedTable $table, Connection $source, array $columns): int
    {
        $grammar = $source->getQueryGrammar();
        $list = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));
        $name = $grammar->wrap($table->name);
        $sql = 'insert into '.self::ATTACH_ALIAS.".{$name} ({$list}) select {$list} from main.{$name} where rowid >= ? and rowid < ? order by rowid";

        $bounds = $source->selectOne("select min(rowid) as first_rowid, max(rowid) as last_rowid from main.{$name}");

        if ($bounds === null || $bounds->first_rowid === null) {
            return 0;
        }

        for ($start = (int) $bounds->first_rowid; $start <= (int) $bounds->last_rowid; $start += $this->chunkSize) {
            $source->transaction(fn () => $source->statement($sql, [$start, $start + $this->chunkSize]));
        }

        return (int) $source->scalar('select count(*) from '.self::ATTACH_ALIAS.".{$name}");
    }

    /**
     * Any driver to any driver: page through the source by `id` and insert into the target.
     *
     * @param  list<string>  $columns
     */
    private function copyThroughPhp(MovedTable $table, Connection $source, Connection $target, array $columns): int
    {
        if ($columns === []) {
            throw new RuntimeException("The {$table->name} table has no column in common with its new schema, so there is nothing to copy. Nothing was changed in the source.");
        }

        $rowsPerInsert = max(1, intdiv(5_000, count($columns)));
        $copied = 0;
        $last = null;

        while (true) {
            $query = $source->table($table->name)->select($columns)->orderBy('id')->limit($this->genericChunkSize);

            if ($last !== null) {
                $query->where('id', '>', $last);
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                break;
            }

            $target->transaction(function () use ($target, $table, $rows, $rowsPerInsert): void {
                $batch = [];
                $bytes = 0;

                foreach ($rows as $row) {
                    $row = (array) $row;
                    $batch[] = $row;

                    foreach ($row as $value) {
                        $bytes += is_string($value) ? strlen($value) : 0;
                    }

                    if (count($batch) >= $rowsPerInsert || $bytes >= self::INSERT_BYTE_LIMIT) {
                        $target->table($table->name)->insert($batch);
                        $batch = [];
                        $bytes = 0;
                    }
                }

                if ($batch !== []) {
                    $target->table($table->name)->insert($batch);
                }
            });

            $copied += $rows->count();
            $last = $rows->last()->id;
        }

        return $copied;
    }

    /**
     * Give the file back what the bulk load grew and refresh the planner statistics (SQLite only).
     */
    private function settle(Connection $target): void
    {
        if ($target->getDriverName() !== 'sqlite') {
            return;
        }

        $target->select('pragma wal_checkpoint(truncate)');
        $target->statement('pragma optimize');
    }

    /**
     * Check that every table is still what was verified, then drop the originals, all while writers are kept out of the
     * source. Nothing is dropped if any table changed since its copy started or its row count differs.
     *
     * Just before the first drop the verified fingerprints go into the marker table of the target in one committed
     * transaction: from then on the target is the only complete copy, and a rerun may finish the job but never copy back.
     *
     * @param  list<MovedTable>  $leaving  Tables that leave the source, in dependency order.
     * @param  list<MovedTable>  $copied  Tables copied by this run.
     * @param  list<MovedTable>  $emptied  Empty source tables whose target keeps its rows.
     * @param  array<string, string>  $expected  The fingerprint each non-empty source table must still have.
     */
    private function dropSourceTables(array $leaving, array $copied, array $emptied, array $expected, Connection $source, Connection $target, string $from, string $to, MoveLock $lock): void
    {
        if ($leaving === []) {
            return;
        }

        $withUpdatedAt = [];

        foreach ($leaving as $table) {
            $withUpdatedAt[$table->name] = $this->hasUpdatedAt($source, $table);
        }

        $this->withSourceLocked($source, $leaving, function () use ($leaving, $copied, $emptied, $expected, $withUpdatedAt, $source, $target, $from, $to, $lock): void {
            foreach ($leaving as $table) {
                if (in_array($table, $emptied, true)) {
                    $this->assertStillEmpty($source, $table, $from);
                } else {
                    $this->assertUnchanged($source, $target, $table, $expected[$table->name], $withUpdatedAt[$table->name], in_array($table, $copied, true), $from, $to);
                }
            }

            $lock->assertHeld();
            $this->recordVerified($target, $copied, $expected);

            foreach (array_reverse($leaving) as $table) {
                $lock->assertHeld();
                $source->getSchemaBuilder()->dropIfExists($table->name);
            }
        });
    }

    /**
     * Run `$callback` with the source tables kept from changing underneath it, undoing it when it throws.
     *
     * SQLite takes the write lock with `begin immediate` (foreign keys are already off; the pragma cannot change inside a
     * transaction), MySQL and MariaDB lock the tables, and other servers use a transaction.
     *
     * @param  list<MovedTable>  $tables
     */
    private function withSourceLocked(Connection $source, array $tables, Closure $callback): void
    {
        $driver = $source->getDriverName();

        if ($driver === 'sqlite') {
            $source->unprepared('begin immediate');

            try {
                $callback();
                $source->unprepared('commit');
            } catch (Throwable $exception) {
                try {
                    $source->unprepared('rollback');
                } catch (Throwable) {
                    // The original error is the one that matters.
                }

                throw $exception;
            }

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $grammar = $source->getQueryGrammar();

            $source->unprepared('lock tables '.implode(', ', array_map(fn (MovedTable $table): string => $grammar->wrap($table->name).' write', $tables)));

            try {
                $callback();
            } finally {
                try {
                    $source->unprepared('unlock tables');
                } catch (Throwable) {
                    // Ending the session releases them.
                }
            }

            return;
        }

        $source->getSchemaGrammar()->supportsSchemaTransactions() ? $source->transaction($callback) : $callback();
    }

    private function assertStillEmpty(Connection $source, MovedTable $table, string $from): void
    {
        if ($source->table($table->name)->exists()) {
            throw new SourceChangedDuringMove("The {$table->name} table received rows in [{$from}] while the move was running. Nothing was dropped; stop the app and run the migration again.");
        }
    }

    /**
     * A table copied by this run must also have as many rows in the target as it has in the source. A table finished by
     * an earlier run is compared with the fingerprint that run recorded; its target may have grown since.
     */
    private function assertUnchanged(Connection $source, Connection $target, MovedTable $table, string $expected, bool $withUpdatedAt, bool $copiedNow, string $from, string $to): void
    {
        if ($copiedNow) {
            $before = $source->table($table->name)->count();
            $after = $target->table($table->name)->count();

            if ($before !== $after) {
                throw new SourceChangedDuringMove("The {$table->name} table has {$before} rows in [{$from}] but {$after} arrived in [{$to}] (rows were probably written while it was being moved). Nothing was dropped; stop the app and run the migration again.");
            }
        }

        if ($this->fingerprint($source, $table, $withUpdatedAt) !== $expected) {
            throw new SourceChangedDuringMove("The {$table->name} table was changed in [{$from}] while it was being moved to [{$to}] (a row was updated, or deleted and replaced). Nothing was dropped; stop the app and run the migration again.");
        }
    }

    /**
     * Mark the copied tables as verified, which makes the target authoritative.
     *
     * @param  list<MovedTable>  $copied
     * @param  array<string, string>  $expected
     */
    private function recordVerified(Connection $target, array $copied, array $expected): void
    {
        if ($copied === []) {
            return;
        }

        $target->transaction(function () use ($target, $copied, $expected): void {
            foreach ($copied as $table) {
                $target->table(self::MARKER_TABLE)->where('table_name', $table->name)->update(['fingerprint' => $expected[$table->name]]);
            }
        });
    }

    /**
     * Return the space the moved tables used to occupy. A failed vacuum (no room to rewrite the file) is harmless:
     * the pages are reused by the app database.
     */
    private function shrink(Connection $source, string $from): void
    {
        if ($source->getDriverName() !== 'sqlite' || Databases::sqlitePath($from) === null) {
            return;
        }

        try {
            $source->statement('vacuum');
            $source->select('pragma wal_checkpoint(truncate)');
        } catch (Throwable $exception) {
            Log::warning('Could not vacuum the database after moving tables out of it; the free pages will be reused.', [
                'connection' => $from,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
