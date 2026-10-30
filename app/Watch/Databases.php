<?php

namespace App\Watch;

use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

        if (filled($overrides['url'] ?? null)) {
            $config['url'] = $overrides['url'];
        }

        return $config;
    }

    /**
     * Whether two connections open the same physical database.
     *
     * Two in-memory SQLite connections never share data. File databases are compared by their resolved path, and
     * MySQL by the server and schema the session is actually using.
     */
    public static function sameDatabase(string $first, string $second): bool
    {
        if ($first === $second) {
            return true;
        }

        $driver = DB::connection($first)->getDriverName();

        if ($driver !== DB::connection($second)->getDriverName()) {
            return false;
        }

        if ($driver === 'sqlite') {
            $firstPath = self::sqlitePath($first);
            $secondPath = self::sqlitePath($second);

            return $firstPath !== null && $firstPath === $secondPath;
        }

        return self::serverIdentity($first) === self::serverIdentity($second);
    }

    /**
     * Create the SQLite file behind a connection if it does not exist yet.
     *
     * `migrate` only creates the file of the default connection, and the SQLite connector refuses a missing file.
     */
    public static function ensureSqliteFileExists(string $connection): void
    {
        $config = config("database.connections.{$connection}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'sqlite') {
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

    /**
     * Server and schema a session is connected to, as reported by the server itself, so host aliases do not matter.
     */
    private static function serverIdentity(string $connection): string
    {
        $connection = DB::connection($connection);

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $row = $connection->selectOne('select @@hostname as host, @@port as port, database() as schema_name');

            return json_encode([$row->host ?? null, $row->port ?? null, $row->schema_name ?? null]);
        }

        $config = $connection->getConfig();

        return json_encode([$config['host'] ?? null, $config['port'] ?? null, $config['database'] ?? null]);
    }
}
