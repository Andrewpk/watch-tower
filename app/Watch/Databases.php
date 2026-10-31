<?php

namespace App\Watch;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Where each table lives.
 *
 * Watch Tower keeps three databases: the app database (the default connection: accounts, projects, sessions, cache and
 * the queue), a telemetry database for everything the agents report, and a logs database for log entries. The two
 * extra connections are derived from the default connection in `config/database.php`, so they always exist. When the
 * install does not configure a separate server or file (MySQL), they point at the app database and nothing is split.
 */
final class Databases
{
    /**
     * Connection name for the telemetry tables.
     */
    public const TELEMETRY = 'telemetry';

    /**
     * Connection name for the log tables.
     */
    public const LOGS = 'logs';

    /**
     * Tables on the telemetry connection, parents before the tables that reference them.
     *
     * @var list<string>
     */
    public const TELEMETRY_TABLES = [
        'traces',
        'error_groups',
        'trace_queries',
        'error_occurrences',
        'event_occurrences',
        'queue_job_runs',
        'command_runs',
        'scheduled_task_runs',
        'mail_sends',
        'notification_sends',
        'cache_events',
        'outgoing_requests',
        'end_users',
        'metrics',
        'issue_comments',
    ];

    /**
     * Tables on the logs connection.
     *
     * @var list<string>
     */
    public const LOG_TABLES = [
        'log_entries',
    ];

    /**
     * The connection that owns a table. Anything that is not telemetry or a log lives on the default connection.
     */
    public static function connectionFor(string $table): string
    {
        return match (true) {
            in_array($table, self::TELEMETRY_TABLES, true) => self::TELEMETRY,
            in_array($table, self::LOG_TABLES, true) => self::LOGS,
            default => (string) config('database.default'),
        };
    }

    /**
     * Derive the connection for a split database from the default connection's configuration.
     *
     * A SQLite install gets a file beside the app database, or a private in-memory database when the app database is
     * in memory. Every other driver keeps the base settings, except for the keys given in `$overrides`; a blank
     * override falls back to the base value. A URL on the base connection is expanded first, so overriding a single
     * setting is not silently undone by it.
     *
     * @param  array<string, mixed>  $base  The default connection's configuration.
     * @param  string  $sqliteFileName  File name used beside the app database on SQLite.
     * @param  array{url?: mixed, host?: mixed, port?: mixed, database?: mixed, username?: mixed, password?: mixed}  $overrides
     * @return array<string, mixed>
     */
    public static function splitConnection(array $base, string $sqliteFileName, array $overrides = []): array
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration($base);
        $config['url'] = null;

        if (($config['driver'] ?? null) === 'sqlite') {
            $config['database'] = filled($overrides['database'] ?? null)
                ? $overrides['database']
                : self::siblingSqlitePath((string) ($config['database'] ?? ''), $sqliteFileName);

            return $config;
        }

        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (filled($overrides[$key] ?? null)) {
                $config[$key] = $overrides[$key];
            }
        }

        if (filled($overrides['host'] ?? null)) {
            $config['unix_socket'] = '';
        }

        if (filled($overrides['url'] ?? null)) {
            $config['url'] = $overrides['url'];
        }

        return $config;
    }

    /**
     * Whether two connections open the same physical database.
     *
     * Decided without trusting what a session reports about itself (a proxy or cluster makes every server look alike):
     * the same connection is one database, and so are two with the same resolved settings; connections of different
     * drivers are never one. SQLite files are compared by device and inode, which also catches hard links, and an
     * in-memory database is never shared. Anything else is settled by {@see sharesProbeTable()}.
     */
    public static function sameDatabase(string $first, string $second): bool
    {
        if ($first === $second) {
            return true;
        }

        $firstConfig = self::identity($first);
        $secondConfig = self::identity($second);

        if ($firstConfig['driver'] !== $secondConfig['driver']) {
            return false;
        }

        if ($firstConfig['driver'] === 'sqlite') {
            return ! self::isInMemory($first) && ! self::isInMemory($second)
                && self::sameFile(self::openedSqliteFile($first, $firstConfig), self::openedSqliteFile($second, $secondConfig));
        }

        if ($firstConfig === $secondConfig) {
            return true;
        }

        return self::sharesProbeTable($first, $second);
    }

    /**
     * A connection's configuration with its `url` expanded, the way the connection factory reads it. Reading the raw
     * config would compare settings the URL overrides.
     *
     * @return array<string, mixed>
     */
    private static function resolvedConfig(string $connection): array
    {
        $config = config("database.connections.{$connection}");
        $config = is_array($config) ? $config : [];

        try {
            return (new ConfigurationUrlParser)->parseConfiguration($config);
        } catch (Throwable) {
            return $config;
        }
    }

    /**
     * The settings that decide which database a connection opens.
     *
     * @return array{driver: mixed, host: mixed, port: mixed, database: mixed, unix_socket: mixed}
     */
    private static function identity(string $connection): array
    {
        $config = self::resolvedConfig($connection);

        return [
            'driver' => $config['driver'] ?? null,
            'host' => $config['host'] ?? null,
            'port' => isset($config['port']) ? (string) $config['port'] : null,
            'database' => $config['database'] ?? null,
            'unix_socket' => ($config['unix_socket'] ?? null) ?: null,
        ];
    }

    /**
     * The file a SQLite connection opens: what the connection reports when it can be opened, otherwise the path its
     * settings name.
     *
     * @param  array{database: mixed}  $identity
     */
    private static function openedSqliteFile(string $connection, array $identity): mixed
    {
        try {
            return self::sqlitePath($connection) ?? $identity['database'];
        } catch (Throwable) {
            return $identity['database'];
        }
    }

    /**
     * Whether a SQLite connection has no file behind it (in memory or temporary).
     */
    private static function isInMemory(string $connection): bool
    {
        $path = self::resolvedConfig($connection)['database'] ?? null;

        return ! is_string($path) || $path === '' || $path === ':memory:' || str_contains($path, 'mode=memory');
    }

    /**
     * Whether two paths are one file on disk, as seen by the file system (a hard link has another path, not another
     * file; an SQLite database must never be opened through two of them, so a probe table would not be reliable).
     */
    private static function sameFile(mixed $first, mixed $second): bool
    {
        $firstStat = is_string($first) ? @stat($first) : false;
        $secondStat = is_string($second) ? @stat($second) : false;

        return $firstStat !== false && $secondStat !== false
            && $firstStat['dev'] === $secondStat['dev']
            && $firstStat['ino'] === $secondStat['ino'];
    }

    /**
     * Create a uniquely named table through the first connection and check whether the second can see it. The table is
     * always dropped again.
     */
    public static function sharesProbeTable(string $first, string $second): bool
    {
        $name = 'watch_db_probe_'.Str::lower(Str::random(12));
        $schema = DB::connection($first)->getSchemaBuilder();

        try {
            $schema->create($name, fn (Blueprint $table) => $table->id());

            return DB::connection($second)->getSchemaBuilder()->hasTable($name);
        } catch (Throwable $exception) {
            throw new RuntimeException("Cannot tell whether the [{$first}] and [{$second}] connections are the same database, so nothing was changed: ".$exception->getMessage(), previous: $exception);
        } finally {
            try {
                $schema->dropIfExists($name);
            } catch (Throwable) {
                // An unreachable server already failed above with a clearer message.
            }
        }
    }

    /**
     * Create the SQLite file behind a connection if it does not exist yet.
     *
     * `migrate` only creates the file of the default connection, and the SQLite connector refuses a missing file.
     */
    public static function ensureSqliteFileExists(string $connection): void
    {
        $config = self::resolvedConfig($connection);

        if (($config['driver'] ?? null) !== 'sqlite') {
            return;
        }

        $path = (string) ($config['database'] ?? '');

        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory') || file_exists($path)) {
            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the SQLite database for the [{$connection}] connection: the directory {$directory} does not exist and could not be created.");
        }

        if (@touch($path) === false) {
            throw new RuntimeException("Cannot create the SQLite database file {$path} for the [{$connection}] connection. Check that the directory is writable.");
        }
    }

    /**
     * The file a SQLite connection has open, resolved through links, or null for an in-memory or temporary database.
     */
    public static function sqlitePath(string $connection): ?string
    {
        $file = DB::connection($connection)->selectOne('PRAGMA database_list');

        $path = is_object($file) ? (string) ($file->file ?? '') : '';

        if ($path === '') {
            return null;
        }

        return realpath($path) ?: $path;
    }

    /**
     * Where a split SQLite database goes by default: beside the app database, or in memory with it.
     */
    private static function siblingSqlitePath(string $appDatabase, string $fileName): string
    {
        if ($appDatabase === '' || $appDatabase === ':memory:' || str_contains($appDatabase, 'mode=memory')) {
            return ':memory:';
        }

        $directory = dirname($appDatabase);

        if (! str_starts_with($directory, '/') && ! preg_match('~^[A-Za-z]:[\\\\/]~', $directory) && function_exists('base_path')) {
            $directory = base_path($directory);
        }

        return $directory.DIRECTORY_SEPARATOR.$fileName;
    }
}
