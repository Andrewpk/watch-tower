<?php

use App\Watch\Databases;

/*
| Rules that keep the app, telemetry and logs databases apart. They read source files, so they need no database.
*/

/**
 * Every telemetry and log table name.
 *
 * @return list<string>
 */
function splitTableNames(): array
{
    return [...Databases::TELEMETRY_TABLES, ...Databases::LOG_TABLES];
}

/**
 * Every PHP file under a directory of the project.
 *
 * @return array<string, string> path relative to the project root => source
 */
function phpSources(string $directory): array
{
    $root = dirname(__DIR__, 2);
    $sources = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($sources);

    return $sources;
}

/**
 * Lines of `$source` that query a telemetry or log table on the default connection, or open a transaction on it.
 *
 * @return list<string>
 */
function defaultConnectionViolations(string $source): array
{
    $tables = implode('|', array_map('preg_quote', splitTableNames()));
    $violations = [];

    foreach (explode("\n", $source) as $number => $line) {
        $isMovedTable = preg_match('/DB::table\(\s*[\'"]('.$tables.')[\'"]/', $line) === 1;
        $isStaticDbCall = preg_match('/\bDB::(transaction|beginTransaction|commit|rollBack|select|selectOne|statement|insert|update|delete|unprepared|affectingStatement|cursor)\(/', $line) === 1;
        $isDefaultSchema = preg_match('/\bSchema::(create|table|drop|dropIfExists|hasTable|hasColumn|getColumns|getIndexes)\(\s*[\'"]('.$tables.')[\'"]/', $line) === 1;

        if ($isMovedTable || $isStaticDbCall || $isDefaultSchema) {
            $violations[] = 'line '.($number + 1).': '.trim($line);
        }
    }

    return $violations;
}

/**
 * What is wrong with a migration written after the databases were split.
 *
 * A migration that touches a telemetry or log table must run on that table's connection, and nothing may point a
 * foreign key at a table in the app database.
 *
 * @return list<string>
 */
function migrationViolations(string $source): array
{
    $violations = [];

    foreach ([Databases::TELEMETRY => Databases::TELEMETRY_TABLES, Databases::LOGS => Databases::LOG_TABLES] as $connection => $tables) {
        $mentioned = array_filter($tables, fn (string $table): bool => preg_match('/[\'"`]'.preg_quote($table, '/').'[\'"`]/', $source) === 1);

        if ($mentioned === []) {
            continue;
        }

        $constant = $connection === Databases::TELEMETRY ? 'TELEMETRY' : 'LOGS';
        $pinned = preg_match('/\$connection\s*=\s*([\'"]'.$connection.'[\'"]|(\\\\?App\\\\Watch\\\\)?Databases::'.$constant.')\s*;/', $source) === 1;

        if (! $pinned) {
            $violations[] = 'touches '.implode(', ', $mentioned)." without `protected \$connection = Databases::{$constant};`";
        }

        if (preg_match('/->(constrained|references|on|foreignIdFor)\(\s*[\'"]?(users|projects|organizations|App\\\\Models\\\\(User|Project|Organization))\b/', $source) === 1
            || preg_match('/->foreign(Id|Uuid)\(\s*[\'"](project_id|user_id)[\'"]\s*\)\s*->(nullable\(\)\s*->)?constrained\(/', $source) === 1) {
            $violations[] = 'adds a foreign key to a table in the app database';
        }
    }

    return $violations;
}

/**
 * Migrations written after the move of the telemetry and log tables.
 *
 * @return array<string, string> path relative to the project root => source
 */
function migrationsAfterTheSplit(): array
{
    $lastSplitMigration = '2026_10_30_171522';

    return array_filter(
        phpSources('database/migrations'),
        fn (string $path): bool => substr(basename($path), 0, 17) > $lastSplitMigration,
        ARRAY_FILTER_USE_KEY,
    );
}

describe('application code', function () {
    it('never queries a telemetry or log table, or opens a transaction, on the default connection', function () {
        $violations = [];

        foreach (phpSources('app') as $path => $source) {
            foreach (defaultConnectionViolations($source) as $violation) {
                $violations[] = "{$path} {$violation}";
            }
        }

        expect($violations)->toBe([]);
    });

    it('recognises the calls it forbids', function () {
        expect(defaultConnectionViolations("DB::table('traces')->count();"))->toHaveCount(1)
            ->and(defaultConnectionViolations('DB::table("log_entries")->delete();'))->toHaveCount(1)
            ->and(defaultConnectionViolations('DB::transaction(function () {});'))->toHaveCount(1)
            ->and(defaultConnectionViolations("DB::select('select * from traces');"))->toHaveCount(1)
            ->and(defaultConnectionViolations("Schema::hasTable('metrics');"))->toHaveCount(1)
            ->and(defaultConnectionViolations("DB::connection(Databases::TELEMETRY)->transaction(fn () => 1);\nDB::connection(\$name)->table(\$table)->get();\nDB::table('users')->get();"))->toBe([]);
    });
});

describe('migrations after the split', function () {
    it('pin every telemetry and log table to its connection and avoid foreign keys to the app database', function () {
        $violations = [];

        foreach (migrationsAfterTheSplit() as $path => $source) {
            foreach (migrationViolations($source) as $violation) {
                $violations[] = "{$path} {$violation}";
            }
        }

        expect($violations)->toBe([]);
    });

    it('flags a migration that forgets its connection', function () {
        $migration = <<<'PHP'
        return new class extends Migration {
            public function up(): void
            {
                Schema::table('log_entries', fn (Blueprint $table) => $table->string('channel')->nullable());
                Schema::table('traces', fn (Blueprint $table) => $table->string('region')->nullable());
            }
        };
        PHP;

        $violations = migrationViolations($migration);

        expect($violations)->toHaveCount(2)
            ->and($violations[0])->toContain('traces')->toContain('Databases::TELEMETRY')
            ->and($violations[1])->toContain('log_entries')->toContain('Databases::LOGS');
    });

    it('accepts a migration on the right connection, written either way', function () {
        $named = "return new class extends Migration { protected \$connection = 'logs'; public function up(): void { Schema::connection('logs')->table('log_entries', fn (\$t) => \$t->string('a')->nullable()); } };";
        $constant = 'return new class extends Migration { protected $connection = Databases::TELEMETRY; public function up(): void { Schema::connection(Databases::TELEMETRY)->table(\'traces\', fn ($t) => $t->string(\'a\')->nullable()); } };';
        $qualified = 'return new class extends Migration { protected $connection = \App\Watch\Databases::TELEMETRY; public function up(): void { Schema::connection(\'telemetry\')->table(\'metrics\', fn ($t) => $t->string(\'a\')->nullable()); } };';
        $appOnly = "return new class extends Migration { public function up(): void { Schema::table('users', fn (\$t) => \$t->string('a')->nullable()); } };";

        expect(migrationViolations($named))->toBe([])
            ->and(migrationViolations($constant))->toBe([])
            ->and(migrationViolations($qualified))->toBe([])
            ->and(migrationViolations($appOnly))->toBe([]);
    });

    it('rejects a table pinned to the wrong connection', function () {
        $wrong = "return new class extends Migration { protected \$connection = 'logs'; public function up(): void { Schema::table('traces', fn (\$t) => \$t->string('a')->nullable()); } };";

        expect(migrationViolations($wrong))->toHaveCount(1);
    });

    it('rejects a foreign key to the app database', function () {
        $constrained = "return new class extends Migration { protected \$connection = 'telemetry'; public function up(): void { Schema::create('metrics', function (\$t) { \$t->foreignUuid('project_id')->constrained('projects'); }); } };";
        $referenced = "return new class extends Migration { protected \$connection = 'telemetry'; public function up(): void { Schema::table('metrics', function (\$t) { \$t->foreign('user_id')->references('id')->on('users'); }); } };";

        expect(migrationViolations($constrained))->not->toBe([])
            ->and(migrationViolations($referenced))->not->toBe([]);
    });
});
