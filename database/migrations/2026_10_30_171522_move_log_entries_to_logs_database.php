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
     * Move the log entries out of the app database into the logs database.
     *
     * The foreign key to `projects` is dropped. On SQLite the table also gets an integer `seq` primary key and `id`
     * becomes a plain unique column: the full-text index added later is keyed on a rowid, and a table with a uuid
     * primary key only has an implicit one, which SQLite may renumber when the file is vacuumed. See {@see TableMover}.
     */
    public function up(): void
    {
        app(TableMover::class)->move($this->tables(), config('database.default'), Databases::LOGS);
    }

    /**
     * Move the entries back into the app database with their original schema.
     */
    public function down(): void
    {
        app(TableMover::class)->move($this->tables(), Databases::LOGS, config('database.default'), legacySchema: true);
    }

    /**
     * The schema of the moved table as it stood when the databases were split.
     *
     * @return list<MovedTable>
     */
    public function tables(): array
    {
        return [
            new MovedTable(
                name: 'log_entries',
                columns: function (Blueprint $table, bool $legacy, string $driver): void {
                    if ($driver === 'sqlite' && ! $legacy) {
                        $table->id('seq');
                        $table->uuid('id');
                    } else {
                        $table->uuid('id')->primary();
                    }

                    $table->uuid('project_id');
                    $table->string('trace_id', 36)->nullable();
                    $table->string('level', 20);
                    $table->text('message');
                    $table->string('source_type', 50)->nullable();
                    $table->string('source_label')->nullable();
                    $table->string('user_name')->nullable();
                    $table->json('context')->nullable();
                    $table->string('environment', 50)->nullable();
                    $table->timestamp('occurred_at');
                    $table->timestamps();
                },
                indexes: function (Blueprint $table, bool $legacy, string $driver): void {
                    if ($driver === 'sqlite' && ! $legacy) {
                        $table->unique('id');
                    }

                    $table->index('trace_id');
                    $table->index('occurred_at');
                    $table->index(['project_id', 'occurred_at']);
                    $table->index(['project_id', 'level']);
                    $table->index(['project_id', 'source_type']);
                    $table->index(['project_id', 'user_name']);
                },
                legacyForeignKeys: [['project_id', 'projects', 'cascade']],
                derivedColumns: ['seq'],
            ),
        ];
    }
};
