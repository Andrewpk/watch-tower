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
 * re-run safely: nothing is dropped from the source until every table has been copied and every row count matches, and
 * a second run starts over from whatever the first one left behind.
 *
 * Per run: the target tables are rebuilt from the frozen schema, rows are copied in chunks (SQLite to SQLite with an
 * attached database and `INSERT ... SELECT`, anything else through PHP), the indexes are built after the copy, the
 * counts are compared, and only then are the source tables dropped. A table that is missing from the source and
 * present in the target was moved by an earlier run and is left alone.
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
     * @param  (Closure(): void)|null  $afterCopy  Called once everything is copied, before the counts are compared.
     */
    public function __construct(
        private readonly int $chunkSize = 50_000,
        private readonly int $genericChunkSize = 1_000,
        private readonly bool $forceGenericCopy = false,
        private readonly ?Closure $freeDiskSpace = null,
        private readonly ?Closure $afterCopy = null,
    ) {}

    /**
     * Move the tables from one connection to another.
     *
     * @param  list<MovedTable>  $tables  Parents before the tables that reference them.
     * @param  string  $from  Connection that holds the tables now.
     * @param  string  $to  Connection that should hold them.
     * @param  bool  $legacySchema  Build the pre-split schema in the target (foreign keys to projects and users), for rollbacks.
     */
    public function move(array $tables, string $from, string $to, bool $legacySchema = false): void
    {
        Databases::ensureSqliteFileExists($from);
        Databases::ensureSqliteFileExists($to);

        $source = DB::connection($from);
        $target = DB::connection($to);

        $this->assertReachable($source, $from);
        $this->assertReachable($target, $to);

        if (Databases::sameDatabase($from, $to)) {
            $this->keepInPlace($tables, $source, $from, $to, $legacySchema);

            return;
        }

        $sourceSchema = $source->getSchemaBuilder();
        $targetSchema = $target->getSchemaBuilder();

        $toCopy = [];
        $toCreate = [];

        foreach ($tables as $table) {
            if ($sourceSchema->hasTable($table->name)) {
                $toCopy[] = $table;
            } elseif (! $targetSchema->hasTable($table->name)) {
                $toCreate[] = $table;
            }
        }

        if ($toCopy === [] && $toCreate === []) {
            return;
        }

        if ($toCopy !== []) {
            $this->assertEnoughDiskSpace($source, $from, $target, $to, $toCopy);
        }

        $work = array_values(array_filter(
            $tables,
            fn (MovedTable $table): bool => in_array($table, $toCopy, true) || in_array($table, $toCreate, true),
        ));

        $sourceSchema->disableForeignKeyConstraints();
        $targetSchema->disableForeignKeyConstraints();

        try {
            foreach (array_reverse($toCopy) as $table) {
                $targetSchema->dropIfExists($table->name);
            }

            foreach ($work as $table) {
                $this->createTable($targetSchema, $table, $legacySchema, $target->getDriverName());
            }

            $this->copyRows($toCopy, $source, $from, $target, $to);

            foreach ($work as $table) {
                $targetSchema->table($table->name, fn (Blueprint $blueprint) => ($table->indexes)($blueprint, $legacySchema, $target->getDriverName()));
            }

            $this->settle($target);

            if ($this->afterCopy !== null) {
                ($this->afterCopy)();
            }

            $this->dropSourceTables($toCopy, $source, $target, $from, $to);
        } finally {
            $sourceSchema->enableForeignKeyConstraints();
            $targetSchema->enableForeignKeyConstraints();
        }

        if ($toCopy !== []) {
            $this->shrink($source, $from);
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
     * Refuse to start when the target's disk cannot hold a copy of the tables. Only SQLite files can be measured.
     *
     * @param  list<MovedTable>  $tables
     */
    private function assertEnoughDiskSpace(Connection $source, string $from, Connection $target, string $to, array $tables): void
    {
        if ($target->getDriverName() !== 'sqlite') {
            return;
        }

        $targetPath = Databases::sqlitePath($to);

        if ($targetPath === null) {
            return;
        }

        $directory = dirname($targetPath);
        $needed = (int) ceil($this->sourceBytes($source, $from, $tables) * 1.1);
        $free = $this->freeDiskSpace !== null ? ($this->freeDiskSpace)($directory) : @disk_free_space($directory);

        if ($free !== false && $free < $needed) {
            throw new RuntimeException(sprintf(
                'Not enough free disk space in %s: moving these tables needs about %s and only %s is free. Nothing was changed. Free up space or point the [%s] database at a bigger disk.',
                $directory,
                Number::fileSize($needed),
                Number::fileSize((int) $free),
                $to,
            ));
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
     * Copy every table's rows and log what was done.
     *
     * @param  list<MovedTable>  $tables
     */
    private function copyRows(array $tables, Connection $source, string $from, Connection $target, string $to): void
    {
        if ($tables === []) {
            return;
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
                foreach ($rows->chunk($rowsPerInsert) as $batch) {
                    $target->table($table->name)->insert($batch->map(fn (object $row): array => (array) $row)->all());
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
     * Check that every table arrived whole, then drop the originals. Nothing is dropped if any count differs.
     *
     * @param  list<MovedTable>  $tables
     */
    private function dropSourceTables(array $tables, Connection $source, Connection $target, string $from, string $to): void
    {
        if ($tables === []) {
            return;
        }

        $schema = $source->getSchemaBuilder();
        $transactional = $source->getSchemaGrammar()->supportsSchemaTransactions();

        if ($transactional) {
            $source->beginTransaction();
        }

        try {
            foreach ($tables as $table) {
                $before = $source->table($table->name)->count();
                $after = $target->table($table->name)->count();

                if ($before !== $after) {
                    throw new RuntimeException("The {$table->name} table has {$before} rows in [{$from}] but {$after} arrived in [{$to}] (rows were probably written while it was being moved). Nothing was dropped; stop the app and run the migration again.");
                }
            }

            foreach (array_reverse($tables) as $table) {
                $schema->dropIfExists($table->name);
            }

            if ($transactional) {
                $source->commit();
            }
        } catch (Throwable $exception) {
            if ($transactional && $source->transactionLevel() > 0) {
                $source->rollBack();
            }

            throw $exception;
        }
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
        } catch (Throwable $exception) {
            Log::warning('Could not vacuum the database after moving tables out of it; the free pages will be reused.', [
                'connection' => $from,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
