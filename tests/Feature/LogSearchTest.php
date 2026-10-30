<?php

use App\Models\LogEntry;
use App\Models\Project;
use App\Models\User;
use App\Watch\Databases;
use App\Watch\Stats\LogStats;
use App\Watch\Stats\TimeRange;
use App\Watch\TelemetryPruner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/*
| The log search runs on an FTS5 trigram index on SQLite. MySQL has no CI job, so its SQL is only compiled here.
*/

beforeEach(function () {
    $this->travelTo('2026-06-15 12:00:00');
    $this->project = Project::factory()->create(['retention_days' => 30]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function logEntry(Project $project, string $message, array $attributes = []): LogEntry
{
    return LogEntry::create([
        'project_id' => $project->id,
        'level' => 'info',
        'message' => $message,
        'occurred_at' => now()->subMinutes(5),
        ...$attributes,
    ]);
}

/**
 * Messages matched by a search, oldest first.
 *
 * @return list<string>
 */
function searchMessages(string $term): array
{
    return LogEntry::query()->searchMessage($term)->orderBy('seq')->pluck('message')->all();
}

function checkFullTextIntegrity(): void
{
    DB::connection(Databases::LOGS)->statement("insert into log_entries_fts(log_entries_fts, rank) values('integrity-check', 1)");
}

describe('searching on SQLite', function () {
    it('finds a term in the middle of a word, ignoring case', function () {
        logEntry($this->project, 'Payment failed for order 12');
        logEntry($this->project, 'payment accepted');
        logEntry($this->project, 'Unrelated line');

        expect(searchMessages('ayment'))->toBe(['Payment failed for order 12', 'payment accepted'])
            ->and(searchMessages('PAYMENT'))->toBe(['Payment failed for order 12', 'payment accepted'])
            ->and(searchMessages('nrelat'))->toBe(['Unrelated line'])
            ->and(searchMessages('missing'))->toBe([]);
    });

    it('matches a phrase including spaces and punctuation as a plain substring', function () {
        logEntry($this->project, 'Payment failed for order 12');
        logEntry($this->project, 'Payment for failed order');
        logEntry($this->project, 'GET /api/users?page=2 took 40ms');

        expect(searchMessages('t failed f'))->toBe(['Payment failed for order 12'])
            ->and(searchMessages('failed for'))->toBe(['Payment failed for order 12'])
            ->and(searchMessages('/api/users?page=2'))->toBe(['GET /api/users?page=2 took 40ms'])
            ->and(searchMessages('order 12'))->toBe(['Payment failed for order 12']);
    });

    it('folds the case of non-ASCII letters as well', function () {
        logEntry($this->project, 'Ünïcode Straße');

        expect(searchMessages('ÜNÏCODE'))->toBe(['Ünïcode Straße'])
            ->and(searchMessages('straße'))->toBe(['Ünïcode Straße']);
    });

    it('treats full-text syntax as literal text', function (string $term, string $message) {
        logEntry($this->project, $message);
        logEntry($this->project, 'plain message without any of that');

        expect(searchMessages($term))->toBe([$message]);
    })->with([
        'double quotes' => ['say "hello"', 'Did they say "hello" twice'],
        'a lone quote' => ['"hello', 'Did they say "hello" twice'],
        'asterisk' => ['bar*baz', 'pattern foo-bar*baz here'],
        'AND' => ['a AND b', 'we saw a AND b together'],
        'OR' => ['x OR y', 'either x OR y'],
        'NOT' => ['NOT this', 'NOT this one'],
        'NEAR(' => ['NEAR(x y', 'matches NEAR(x y) here'],
        'minus' => ['-bar', 'pattern foo-bar*baz here'],
        'column filter' => ['message:foo', 'the message:foo is literal'],
        'caret' => ['^start', 'a ^start of it'],
        'parentheses' => ['(foo)', 'call (foo) now'],
        'backslash' => ['c:\\temp', 'wrote c:\\temp file'],
    ]);

    it('searches terms of one or two characters with LIKE and takes % and _ literally', function () {
        logEntry($this->project, 'disk 100% full');
        logEntry($this->project, 'user_id missing');
        logEntry($this->project, 'cache hit');
        logEntry($this->project, 'a!b');

        expect(searchMessages('%'))->toBe(['disk 100% full'])
            ->and(searchMessages('_'))->toBe(['user_id missing'])
            ->and(searchMessages('0%'))->toBe(['disk 100% full'])
            ->and(searchMessages('d_'))->toBe([])
            ->and(searchMessages('!'))->toBe(['a!b'])
            ->and(searchMessages('CA'))->toBe(['cache hit'])
            ->and(searchMessages('ca'))->toBe(['cache hit'])
            ->and(searchMessages('zz'))->toBe([]);
    });

    it('stays correct after messages are updated or deleted', function () {
        $entry = logEntry($this->project, 'first wording');
        $other = logEntry($this->project, 'first wording as well');

        $entry->update(['message' => 'second wording']);
        $other->delete();

        expect(searchMessages('first'))->toBe([])
            ->and(searchMessages('second'))->toBe(['second wording'])
            ->and(searchMessages('wording'))->toBe(['second wording']);

        checkFullTextIntegrity();
    });

    it('indexes long messages completely', function () {
        logEntry($this->project, str_repeat('lorem ipsum ', 5000).'needle-at-the-very-end');

        expect(searchMessages('needle-at-the-very'))->toHaveCount(1);
    });

    it('uses the full-text index instead of scanning the messages', function () {
        $plan = collect(DB::connection(Databases::LOGS)->select(
            'explain query plan '.LogEntry::query()->searchMessage('payment')->toSql(),
            LogEntry::query()->searchMessage('payment')->getBindings(),
        ))->pluck('detail')->implode("\n");

        expect($plan)->toContain('log_entries_fts VIRTUAL TABLE INDEX')
            ->and($plan)->not->toMatch('/^SCAN log_entries$/m');
    });

    it('does not use the full-text index for short terms', function () {
        expect(LogEntry::query()->searchMessage('ab')->toSql())->not->toContain('log_entries_fts')
            ->and(LogEntry::query()->searchMessage('abc')->toSql())->toContain('log_entries_fts');
    });

    it('can be chained with other conditions and keeps the table qualified', function () {
        logEntry($this->project, 'Payment failed', ['level' => 'error']);
        logEntry($this->project, 'Payment ok');

        expect(LogEntry::query()->where('level', 'error')->searchMessage('payment')->pluck('message')->all())->toBe(['Payment failed'])
            ->and(LogEntry::query()->searchMessage('payment')->orWhere('level', 'debug')->count())->toBe(2);
    });
});

describe('the log page filters', function () {
    function paginatedLogs(Project $project, ?string $search, ?string $level = null, ?string $user = null, int $page = 1, int $perPage = 50, string $range = '24h'): array
    {
        $paginator = app(LogStats::class)->paginated($project, TimeRange::fromLabel($range), $search, $level, $user, $page, $perPage);

        return [
            'messages' => collect($paginator->items())->pluck('message')->all(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    it('combines the search with level, user and time range', function () {
        $other = Project::factory()->create();

        logEntry($this->project, 'Payment failed one', ['level' => 'error', 'user_name' => 'Jane', 'occurred_at' => now()->subHours(2)]);
        logEntry($this->project, 'Payment failed two', ['level' => 'error', 'user_name' => 'John', 'occurred_at' => now()->subHours(3)]);
        logEntry($this->project, 'Payment accepted', ['level' => 'info', 'user_name' => 'Jane', 'occurred_at' => now()->subHours(2)]);
        logEntry($this->project, 'Payment failed old', ['level' => 'error', 'user_name' => 'Jane', 'occurred_at' => now()->subDays(3)]);
        logEntry($other, 'Payment failed elsewhere', ['level' => 'error', 'user_name' => 'Jane', 'occurred_at' => now()->subHours(2)]);

        expect(paginatedLogs($this->project, 'payment')['total'])->toBe(3)
            ->and(paginatedLogs($this->project, 'ayment fail', level: 'error')['messages'])->toBe(['Payment failed one', 'Payment failed two'])
            ->and(paginatedLogs($this->project, 'payment', user: 'Jane')['messages'])->toBe(['Payment accepted', 'Payment failed one'])
            ->and(paginatedLogs($this->project, 'payment', level: 'error', user: 'Jane')['messages'])->toBe(['Payment failed one'])
            ->and(paginatedLogs($this->project, 'payment', range: '7d')['total'])->toBe(4)
            ->and(paginatedLogs($this->project, 'payment', range: '15m')['total'])->toBe(0)
            ->and(paginatedLogs($this->project, null)['total'])->toBe(3);
    });

    it('counts and pages only the matching entries', function () {
        foreach (range(1, 7) as $number) {
            logEntry($this->project, "Payment {$number} failed", ['occurred_at' => now()->subMinutes($number)]);
        }
        logEntry($this->project, 'Something else entirely');

        $first = paginatedLogs($this->project, 'payment', page: 1, perPage: 3);
        $last = paginatedLogs($this->project, 'payment', page: 3, perPage: 3);

        expect($first['total'])->toBe(7)
            ->and($first['lastPage'])->toBe(3)
            ->and($first['messages'])->toBe(['Payment 1 failed', 'Payment 2 failed', 'Payment 3 failed'])
            ->and($last['messages'])->toBe(['Payment 7 failed']);
    });

    it('searches short terms and terms with wildcards literally', function () {
        logEntry($this->project, 'disk 100% full');
        logEntry($this->project, 'disk almost full');

        expect(paginatedLogs($this->project, '%')['messages'])->toBe(['disk 100% full'])
            ->and(paginatedLogs($this->project, 'ul')['total'])->toBe(2);
    });

    it('serves the page over HTTP', function () {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]));
        logEntry($this->project, 'Payment failed');
        logEntry($this->project, 'Other line');

        $this->get(route('projects.logs.index', ['project' => $this->project->slug, 'range' => '24h', 'search' => 'ayment']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('projects/logs/index')
                ->where('logs.total', 1)
                ->where('logs.data.0.message', 'Payment failed')
                ->where('filters.search', 'ayment'));
    });
});

describe('keeping the index in step', function () {
    it('passes the FTS integrity check after inserts, updates and deletes', function () {
        $entries = collect(range(1, 40))->map(fn (int $number) => logEntry($this->project, "Message number {$number} about payments"));

        $entries->take(10)->each->delete();
        $entries->slice(10, 10)->each(fn (LogEntry $entry) => $entry->update(['message' => 'Rewritten '.$entry->message]));
        LogEntry::query()->where('seq', '>', 30)->delete();

        checkFullTextIntegrity();

        expect(searchMessages('Message number'))->toHaveCount(20)
            ->and(searchMessages('Rewritten'))->toHaveCount(10)
            ->and(searchMessages('payments'))->toHaveCount(20);
    });

    it('stays consistent when retention pruning deletes old entries in chunks', function () {
        foreach (range(1, 12) as $number) {
            logEntry($this->project, "Old entry {$number}", ['occurred_at' => now()->subDays(40)]);
            logEntry($this->project, "Recent entry {$number}", ['occurred_at' => now()->subDays(2)]);
        }

        $this->artisan('watch:prune')->assertSuccessful();

        checkFullTextIntegrity();

        expect(LogEntry::count())->toBe(12)
            ->and(searchMessages('Old entry'))->toBe([])
            ->and(searchMessages('Recent entry'))->toHaveCount(12);
    });

    it('stays consistent when a project is purged', function () {
        logEntry($this->project, 'Doomed payment');
        $other = Project::factory()->create();
        logEntry($other, 'Surviving payment');

        app(TelemetryPruner::class)->purge($this->project->id);

        checkFullTextIntegrity();

        expect(searchMessages('payment'))->toBe(['Surviving payment']);
    });

    it('creates the table and the triggers with the migrations and removes them on rollback', function () {
        $logs = DB::connection(Databases::LOGS);
        $names = fn (string $type) => $logs->table('sqlite_master')->where('type', $type)->pluck('name')->all();
        $migration = require database_path('migrations/2026_10_30_171523_add_full_text_index_to_log_entries.php');

        expect($names('table'))->toContain('log_entries_fts')
            ->and($names('trigger'))->toEqualCanonicalizing(['log_entries_fts_insert', 'log_entries_fts_delete', 'log_entries_fts_update']);

        $migration->down();

        expect($names('table'))->not->toContain('log_entries_fts')
            ->and(collect($names('table'))->filter(fn (string $name) => str_starts_with($name, 'log_entries_fts')))->toBeEmpty()
            ->and($names('trigger'))->toBe([])
            ->and($names('table'))->toContain('log_entries');

        logEntry($this->project, 'Written while there is no index');
        $migration->down();
        $migration->up();
        $migration->up();

        expect(searchMessages('while there is no'))->toBe(['Written while there is no index'])
            ->and($names('trigger'))->toHaveCount(3);

        checkFullTextIntegrity();
    });

    it('runs with the same pinned connection and without a wrapping transaction', function () {
        $migration = require database_path('migrations/2026_10_30_171523_add_full_text_index_to_log_entries.php');

        expect($migration->getConnection())->toBe(Databases::LOGS)
            ->and($migration->withinTransaction)->toBeFalse();
    });
});

describe('searching on MySQL', function () {
    beforeEach(function () {
        config(['database.connections.mysql_search' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'watch_tower_logs',
            'username' => 'unused',
            'password' => '',
            'prefix' => '',
        ]]);
    });

    function mysqlSearch(string $term): Builder
    {
        return LogEntry::on('mysql_search')->searchMessage($term);
    }

    it('matches against the FULLTEXT index in boolean mode with a quoted phrase, then confirms with LIKE', function () {
        $query = mysqlSearch('payment');

        expect($query->toSql())->toBe("select * from `log_entries` where match (`log_entries`.`message`) against (? in boolean mode) and `log_entries`.`message` like ? escape '!'")
            ->and($query->getBindings())->toBe(['"payment"', '%payment%']);
    });

    it('drops double quotes, which cannot be escaped inside a boolean phrase, and escapes LIKE wildcards', function () {
        expect(mysqlSearch('50%_"done"!')->getBindings())->toBe(['"50%_done!"', '%50!%!_"done"!!%']);
    });

    it('falls back to LIKE for terms shorter than the ngram size', function () {
        $query = mysqlSearch('ab');

        expect($query->toSql())->toBe("select * from `log_entries` where `log_entries`.`message` like ? escape '!'")
            ->and($query->getBindings())->toBe(['%ab%'])
            ->and(mysqlSearch('%_')->getBindings())->toBe(['%!%!_%']);
    });

    it('counts characters, not bytes, when deciding whether a term is short', function () {
        expect(mysqlSearch('日本')->toSql())->not->toContain('match')
            ->and(mysqlSearch('日本語')->toSql())->toContain('match');
    });

    it('keeps using LIKE on MariaDB, which has no ngram parser', function () {
        config(['database.connections.mariadb_search' => [...config('database.connections.mysql_search'), 'driver' => 'mariadb']]);

        expect(LogEntry::on('mariadb_search')->searchMessage('payment')->toSql())->not->toContain('match');
    });
});
