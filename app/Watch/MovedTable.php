<?php

namespace App\Watch;

use Closure;
use Illuminate\Database\Schema\Blueprint;

/**
 * A table's schema as a migration froze it, for {@see TableMover}.
 *
 * Migrations that move tables between databases cannot call the old create migrations, and must not follow later
 * changes to them, so each one carries its own copy of the schema. Columns and the primary key are created first and
 * the secondary indexes after the bulk copy, because building an index once is much cheaper than maintaining it row by
 * row.
 */
final readonly class MovedTable
{
    /**
     * @param  string  $name  Table name.
     * @param  Closure(Blueprint, bool, string): void  $columns  Adds the columns and primary key. Receives the blueprint,
     *                                                           whether the legacy single-database schema is being
     *                                                           built, and the target driver name.
     * @param  Closure(Blueprint, bool, string): void  $indexes  Adds the secondary and unique indexes, same arguments.
     * @param  list<array{0: string, 1: string, 2: string}>  $foreignKeys  Foreign keys to other moved tables as
     *                                                                     [column, referenced table, on delete action].
     * @param  list<array{0: string, 1: string, 2: string}>  $legacyForeignKeys  Foreign keys to tables that stay in the app
     *                                                                           database. Only the legacy schema has them.
     * @param  list<string>  $derivedColumns  Columns that exist on one side only and are generated, not copied.
     */
    public function __construct(
        public string $name,
        public Closure $columns,
        public Closure $indexes,
        public array $foreignKeys = [],
        public array $legacyForeignKeys = [],
        public array $derivedColumns = [],
    ) {}
}
