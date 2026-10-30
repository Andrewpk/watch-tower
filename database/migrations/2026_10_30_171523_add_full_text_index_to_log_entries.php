<?php

use App\Watch\Databases;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Name of the SQLite full-text table.
     */
    private const FTS_TABLE = 'log_entries_fts';

    /**
     * Name of the MySQL full-text index.
     */
    private const FULLTEXT_INDEX = 'log_entries_message_fulltext';

    protected $connection = Databases::LOGS;

    /**
     * Building the index can take a while on a large table, and MySQL cannot roll back an ALTER TABLE anyway.
     */
    public $withinTransaction = false;

    /**
     * Index the log messages so the log search does not have to scan every row.
     *
     * SQLite gets an external-content FTS5 table with the trigram tokenizer, which finds any three-character-or-longer
     * substring regardless of case, exactly like `LIKE '%term%'`. It stores no copy of the messages. Three triggers
     * keep it in step with `log_entries`, and a final `rebuild` indexes the rows that already exist. MySQL gets a
     * FULLTEXT index that uses the ngram parser, which indexes runs of characters instead of whole words. MariaDB has
     * no ngram parser, so it gets no index and keeps searching with LIKE.
     *
     * Any later migration that alters `log_entries` on SQLite must recreate these triggers, see CONTRIBUTING.md.
     */
    public function up(): void
    {
        $connection = DB::connection(Databases::LOGS);

        match ($connection->getDriverName()) {
            'sqlite' => $this->createSqliteIndex($connection),
            'mysql' => $this->createMysqlIndex($connection),
            default => null,
        };
    }

    /**
     * Drop the index, its triggers and the shadow tables behind it.
     */
    public function down(): void
    {
        $connection = DB::connection(Databases::LOGS);

        match ($connection->getDriverName()) {
            'sqlite' => $this->dropSqliteIndex($connection),
            'mysql' => $this->dropMysqlIndex($connection),
            default => null,
        };
    }

    private function createSqliteIndex(Connection $connection): void
    {
        try {
            $connection->statement(
                'CREATE VIRTUAL TABLE IF NOT EXISTS '.self::FTS_TABLE
                ." USING fts5(message, content='log_entries', content_rowid='seq', tokenize='trigram')"
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Searching log messages needs SQLite 3.34 or newer, built with the FTS5 extension and its trigram tokenizer, '
                .'and this PHP is using SQLite '.$connection->selectOne('select sqlite_version() as version')->version.' without it. '
                .'Upgrade SQLite or PHP, then run the migrations again. ('.$exception->getMessage().')',
                previous: $exception,
            );
        }

        $connection->statement(
            'CREATE TRIGGER IF NOT EXISTS log_entries_fts_insert AFTER INSERT ON log_entries BEGIN '
            .'INSERT INTO '.self::FTS_TABLE.'(rowid, message) VALUES (new.seq, new.message); END'
        );
        $connection->statement(
            'CREATE TRIGGER IF NOT EXISTS log_entries_fts_delete AFTER DELETE ON log_entries BEGIN '
            .'INSERT INTO '.self::FTS_TABLE.'('.self::FTS_TABLE.', rowid, message) VALUES (\'delete\', old.seq, old.message); END'
        );
        $connection->statement(
            'CREATE TRIGGER IF NOT EXISTS log_entries_fts_update AFTER UPDATE OF message ON log_entries BEGIN '
            .'INSERT INTO '.self::FTS_TABLE.'('.self::FTS_TABLE.', rowid, message) VALUES (\'delete\', old.seq, old.message); '
            .'INSERT INTO '.self::FTS_TABLE.'(rowid, message) VALUES (new.seq, new.message); END'
        );

        $connection->statement('INSERT INTO '.self::FTS_TABLE.'('.self::FTS_TABLE.") VALUES ('rebuild')");
    }

    private function dropSqliteIndex(Connection $connection): void
    {
        foreach (['insert', 'delete', 'update'] as $event) {
            $connection->statement("DROP TRIGGER IF EXISTS log_entries_fts_{$event}");
        }

        $connection->statement('DROP TABLE IF EXISTS '.self::FTS_TABLE);
    }

    /**
     * Stop words are switched off for the session first: they are fixed when the index is created, and the default
     * list would make common words in log messages unsearchable.
     */
    private function createMysqlIndex(Connection $connection): void
    {
        if ($this->mysqlIndexExists()) {
            return;
        }

        $connection->statement('SET SESSION innodb_ft_enable_stopword = 0');
        $connection->statement('ALTER TABLE log_entries ADD FULLTEXT INDEX '.self::FULLTEXT_INDEX.' (message) WITH PARSER ngram');
    }

    private function dropMysqlIndex(Connection $connection): void
    {
        if ($this->mysqlIndexExists()) {
            $connection->statement('ALTER TABLE log_entries DROP INDEX '.self::FULLTEXT_INDEX);
        }
    }

    private function mysqlIndexExists(): bool
    {
        return Schema::connection(Databases::LOGS)->hasIndex('log_entries', self::FULLTEXT_INDEX);
    }
};
