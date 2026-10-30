<?php

use App\Watch\Databases;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A MySQL connection array as `config/database.php` builds it.
 *
 * @return array<string, mixed>
 */
function baseMysqlConnection(array $overrides = []): array
{
    return [
        'driver' => 'mysql',
        'url' => null,
        'host' => 'db.internal',
        'port' => '3306',
        'database' => 'watch',
        'username' => 'app',
        'password' => 'secret',
        'unix_socket' => '',
        'charset' => 'utf8mb4',
        'prefix' => '',
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function baseSqliteConnection(string $database): array
{
    return [
        'driver' => 'sqlite',
        'url' => null,
        'database' => $database,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 5000,
        'journal_mode' => 'wal',
    ];
}

it('sends each table to the connection that owns it', function () {
    foreach (Databases::TELEMETRY_TABLES as $table) {
        expect(Databases::connectionFor($table))->toBe('telemetry');
    }

    expect(Databases::connectionFor('log_entries'))->toBe('logs');

    foreach (['users', 'projects', 'organizations', 'project_user', 'sessions', 'cache', 'cache_locks', 'maintenance_runs', 'jobs', 'job_batches', 'failed_jobs', 'telescope_entries', 'migrations'] as $table) {
        expect(Databases::connectionFor($table))->toBe(config('database.default'));
    }
});

it('does not list a table twice', function () {
    $tables = [...Databases::TELEMETRY_TABLES, ...Databases::LOG_TABLES];

    expect($tables)->toBe(array_values(array_unique($tables)));
});

it('keeps the queue tables in the app database', function () {
    expect(Databases::TELEMETRY_TABLES)->not->toContain('jobs', 'job_batches', 'failed_jobs');
});

it('configures telemetry and logs as connections of their own', function () {
    $connections = config('database.connections');

    expect($connections)->toHaveKeys(['telemetry', 'logs'])
        ->and(config('database.default'))->toBe('sqlite')
        ->and($connections['telemetry']['driver'])->toBe('sqlite')
        ->and($connections['telemetry']['url'])->toBeNull()
        ->and($connections['telemetry']['foreign_key_constraints'])->toBe($connections['sqlite']['foreign_key_constraints']);
});

it('keeps in-memory databases separate in the test environment', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('database.connections.telemetry.database'))->toBe(':memory:')
        ->and(config('database.connections.logs.database'))->toBe(':memory:')
        ->and(DB::connection('sqlite')->getPdo())->not->toBe(DB::connection('telemetry')->getPdo())
        ->and(Databases::sameDatabase('sqlite', 'telemetry'))->toBeFalse()
        ->and(Databases::sameDatabase('telemetry', 'logs'))->toBeFalse();
});

describe('splitting a SQLite connection', function () {
    it('puts the file beside the app database', function () {
        $config = Databases::splitConnection(baseSqliteConnection('/data/app.sqlite'), 'telemetry.sqlite');

        expect($config['database'])->toBe('/data/telemetry.sqlite')
            ->and($config['driver'])->toBe('sqlite')
            ->and($config['busy_timeout'])->toBe(5000)
            ->and($config['journal_mode'])->toBe('wal')
            ->and($config['foreign_key_constraints'])->toBeTrue()
            ->and($config['url'])->toBeNull();
    });

    it('stays in memory when the app database is in memory', function () {
        expect(Databases::splitConnection(baseSqliteConnection(':memory:'), 'logs.sqlite')['database'])->toBe(':memory:');
    });

    it('uses the configured path instead', function () {
        $config = Databases::splitConnection(baseSqliteConnection('/data/app.sqlite'), 'logs.sqlite', ['database' => '/elsewhere/l.db']);

        expect($config['database'])->toBe('/elsewhere/l.db');
    });

    it('ignores a blank configured path', function () {
        $config = Databases::splitConnection(baseSqliteConnection('/data/app.sqlite'), 'logs.sqlite', ['database' => '', 'host' => 'ignored']);

        expect($config['database'])->toBe('/data/logs.sqlite')
            ->and($config)->not->toHaveKey('host');
    });

    it('does not inherit a URL that points at the app database', function () {
        $config = Databases::splitConnection(baseSqliteConnection('/data/app.sqlite') + ['url' => 'sqlite:////data/app.sqlite'], 'logs.sqlite');

        expect($config['database'])->toBe('/data/logs.sqlite')
            ->and($config['url'])->toBeNull();
    });

    it('resolves a relative app database against the base path', function () {
        $config = Databases::splitConnection(baseSqliteConnection('storage/app.sqlite'), 'logs.sqlite');

        expect($config['database'])->toBe(base_path('storage').'/logs.sqlite');
    });
});

describe('splitting a MySQL connection', function () {
    it('falls back to the app database when nothing is configured', function () {
        $config = Databases::splitConnection(baseMysqlConnection(), 'telemetry.sqlite');

        expect($config)->toEqual(baseMysqlConnection());
    });

    it('takes only the settings that are configured', function () {
        $config = Databases::splitConnection(baseMysqlConnection(), 'telemetry.sqlite', [
            'host' => 'telemetry.internal',
            'database' => 'watch_telemetry',
            'port' => null,
            'username' => '',
            'password' => null,
        ]);

        expect($config['host'])->toBe('telemetry.internal')
            ->and($config['database'])->toBe('watch_telemetry')
            ->and($config['port'])->toBe('3306')
            ->and($config['username'])->toBe('app')
            ->and($config['password'])->toBe('secret');
    });

    it('expands a base URL so a single override is not undone by it', function () {
        $config = Databases::splitConnection(
            baseMysqlConnection(['url' => 'mysql://user:pass@urlhost:3307/urldb']),
            'logs.sqlite',
            ['database' => 'watch_logs'],
        );

        expect($config['host'])->toBe('urlhost')
            ->and($config['port'])->toBe(3307)
            ->and($config['username'])->toBe('user')
            ->and($config['password'])->toBe('pass')
            ->and($config['database'])->toBe('watch_logs')
            ->and($config['url'])->toBeNull();
    });

    it('lets a URL of its own replace the discrete settings', function () {
        $config = Databases::splitConnection(baseMysqlConnection(), 'logs.sqlite', ['url' => 'mysql://u:p@elsewhere/other']);

        expect($config['url'])->toBe('mysql://u:p@elsewhere/other');
    });

    it('applies to MariaDB as well', function () {
        $config = Databases::splitConnection(baseMysqlConnection(['driver' => 'mariadb']), 'logs.sqlite', ['database' => 'watch_logs']);

        expect($config['driver'])->toBe('mariadb')
            ->and($config['database'])->toBe('watch_logs');
    });
});

describe('comparing SQLite databases', function () {
    beforeEach(function () {
        $this->directory = sys_get_temp_dir().'/watch-tower-databases-'.Str::lower(Str::random(8));
        File::ensureDirectoryExists($this->directory);

        foreach (['one', 'two'] as $name) {
            config(["database.connections.{$name}" => baseSqliteConnection("{$this->directory}/{$name}.sqlite")]);
            touch("{$this->directory}/{$name}.sqlite");
        }
    });

    afterEach(function () {
        DB::purge('one');
        DB::purge('two');
        DB::purge('alias');
        File::deleteDirectory($this->directory);
    });

    it('tells different files apart', function () {
        expect(Databases::sameDatabase('one', 'two'))->toBeFalse();
    });

    it('recognises one connection as itself', function () {
        expect(Databases::sameDatabase('one', 'one'))->toBeTrue();
    });

    it('recognises the same file under another name or path', function () {
        symlink("{$this->directory}/one.sqlite", "{$this->directory}/link.sqlite");
        config(['database.connections.alias' => baseSqliteConnection("{$this->directory}/sub/../link.sqlite")]);
        mkdir("{$this->directory}/sub");

        expect(Databases::sameDatabase('one', 'alias'))->toBeTrue();
    });

    it('never treats in-memory databases as shared', function () {
        config([
            'database.connections.one' => baseSqliteConnection(':memory:'),
            'database.connections.two' => baseSqliteConnection(':memory:'),
        ]);

        expect(Databases::sameDatabase('one', 'two'))->toBeFalse();
    });
});

describe('creating SQLite files', function () {
    beforeEach(function () {
        $this->directory = sys_get_temp_dir().'/watch-tower-databases-'.Str::lower(Str::random(8));
    });

    afterEach(function () {
        File::deleteDirectory($this->directory);
    });

    it('creates the file and any missing directory', function () {
        config(['database.connections.fresh' => baseSqliteConnection("{$this->directory}/nested/fresh.sqlite")]);

        Databases::ensureSqliteFileExists('fresh');

        expect(file_exists("{$this->directory}/nested/fresh.sqlite"))->toBeTrue();
    });

    it('leaves an existing file alone', function () {
        mkdir($this->directory);
        file_put_contents("{$this->directory}/kept.sqlite", 'contents');
        config(['database.connections.kept' => baseSqliteConnection("{$this->directory}/kept.sqlite")]);

        Databases::ensureSqliteFileExists('kept');

        expect(file_get_contents("{$this->directory}/kept.sqlite"))->toBe('contents');
    });

    it('does nothing for in-memory databases and other drivers', function () {
        config([
            'database.connections.memory' => baseSqliteConnection(':memory:'),
            'database.connections.server' => baseMysqlConnection(),
        ]);

        Databases::ensureSqliteFileExists('memory');
        Databases::ensureSqliteFileExists('server');
        Databases::ensureSqliteFileExists('not-configured');

        expect(file_exists(':memory:'))->toBeFalse();
    });
});
