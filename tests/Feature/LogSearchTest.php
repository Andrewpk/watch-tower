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
function searchMessages(string $term, ?bool $useIndex = true): array
{
    return LogEntry::query()->searchMessage($term, $useIndex)->orderBy('seq')->pluck('message')->all();
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
            'explain query plan '.LogEntry::query()->searchMessage('payment', true)->toSql(),
            LogEntry::query()->searchMessage('payment', true)->getBindings(),
        ))->pluck('detail')->implode("\n");

        expect($plan)->toContain('log_entries_fts VIRTUAL TABLE INDEX')
            ->and($plan)->not->toMatch('/^SCAN log_entries$/m');
    });

    it('does not use the full-text index for short terms', function () {
        expect(LogEntry::query()->searchMessage('ab', true)->toSql())->not->toContain('log_entries_fts')
            ->and(LogEntry::query()->searchMessage('abc', true)->toSql())->toContain('log_entries_fts');
    });

    it('falls back to LIKE for a term with a NUL byte, which FTS5 rejects', function () {
        logEntry($this->project, "nul\0byte here");
        logEntry($this->project, 'Payment failed');

        expect(LogEntry::query()->searchMessage("nul\0byte", true)->toSql())->not->toContain('log_entries_fts')
            ->and(searchMessages("nul\0byte"))->toBe(["nul\0byte here"])
            ->and(searchMessages("pay\0ment"))->toBe([]);
    });

    it('can be chained with other conditions and keeps the table qualified', function () {
        logEntry($this->project, 'Payment failed', ['level' => 'error']);
        logEntry($this->project, 'Payment ok');

        expect(LogEntry::query()->where('level', 'error')->searchMessage('payment', true)->pluck('message')->all())->toBe(['Payment failed'])
            ->and(LogEntry::query()->searchMessage('payment', true)->orWhere('level', 'debug')->count())->toBe(2);
    });

    it('returns the same entries from both paths', function () {
        $messages = [
            'Payment failed for order 12', 'payment ACCEPTED', 'GET /api/users?page=2 took 40ms', 'disk 100% full', 'user_id missing',
            'say "hello" twice', 'a AND b', 'NEAR(x y) here', 'foo-bar*baz', 'c:\\temp file', 'it\'s 50% done_ok!', 'Unrelated line', 'aaa aaa aaaa',
        ];

        foreach ($messages as $message) {
            logEntry($this->project, $message);
        }

        $terms = ['payment', 'PAYMENT', 'ayment fail', 'order 1', '/api/users', 'page=2', '100%', '0% f', '_id', 'user_id', '"hello"', 'AND', 'a AND b', 'NEAR(', 'bar*', 'foo-', 'c:\\t', "'s 50%", 'done_ok!', 'aaa', 'aaaa', 'nothing like this', 'ab', '%', '_', '!'];

        foreach ($terms as $term) {
            $viaIndex = LogEntry::query()->searchMessage($term, true)->orderBy('seq')->pluck('seq')->all();
            $viaLike = LogEntry::query()->searchMessage($term, false)->orderBy('seq')->pluck('seq')->all();

            expect($viaLike)->toBe($viaIndex, "term [{$term}]");
        }
    });
});

describe('choosing between the index and LIKE', function () {
    beforeEach(function () {
        foreach (['insert', 'delete', 'update'] as $event) {
            DB::connection(Databases::LOGS)->statement("drop trigger log_entries_fts_{$event}");
        }
    });

    /**
     * Insert `$count` plain entries with one statement.
     *
     * The full-text triggers are dropped by the `beforeEach` above, because indexing a hundred thousand rows would
     * make these tests slow, and they only look at which SQL a search runs.
     */
    function bulkLogEntries(Project $project, int $count, string $level = 'info', string $message = 'Filler line'): void
    {
        DB::connection(Databases::LOGS)->insert(
            'with recursive numbers(n) as (select 1 union all select n + 1 from numbers where n < ?) '
            ."insert into log_entries(id, project_id, level, message, occurred_at) select lower(hex(randomblob(16))), ?, ?, ? || ' ' || n, ? from numbers",
            [$count, $project->id, $level, $message, now()->subMinutes(5)->toDateTimeString()],
        );
    }

    /**
     * Whether a search for `$term` over the page's filters ran through the FTS index, judged from the SQL it executed.
     */
    function searchRanThroughTheIndex(Project $project, string $term, ?string $level = null): bool
    {
        $logs = DB::connection(Databases::LOGS);
        $logs->flushQueryLog();
        $logs->enableQueryLog();

        app(LogStats::class)->paginated($project, TimeRange::fromLabel('24h'), $term, $level, null, 1, 50);

        $usedIndex = collect($logs->getQueryLog())->contains(fn (array $entry): bool => str_contains($entry['query'], 'log_entries_fts'));
        $logs->disableQueryLog();

        return $usedIndex;
    }

    it('scans with LIKE up to the threshold and uses the index above it', function () {
        bulkLogEntries($this->project, LogEntry::SEARCH_INDEX_MINIMUM_CANDIDATES, message: 'Payment filler');

        expect(searchRanThroughTheIndex($this->project, 'payment'))->toBeFalse();

        bulkLogEntries($this->project, 1, message: 'Payment one more');

        expect(searchRanThroughTheIndex($this->project, 'payment'))->toBeTrue()
            ->and(searchRanThroughTheIndex($this->project, 'pa'))->toBeFalse();
    });

    it('counts only the rows the other filters leave', function () {
        bulkLogEntries($this->project, LogEntry::SEARCH_INDEX_MINIMUM_CANDIDATES + 1, message: 'Payment filler');
        bulkLogEntries($this->project, 3, level: 'error', message: 'Payment error');
        bulkLogEntries(Project::factory()->create(), 5, message: 'Payment elsewhere');

        expect(searchRanThroughTheIndex($this->project, 'payment'))->toBeTrue()
            ->and(searchRanThroughTheIndex($this->project, 'payment', level: 'error'))->toBeFalse();

        $page = app(LogStats::class)->paginated($this->project, TimeRange::fromLabel('24h'), 'payment error', 'error', null, 1, 50);

        expect($page->total())->toBe(3);
    });

    it('decides with a count that stops one row past the threshold', function () {
        bulkLogEntries($this->project, LogEntry::SEARCH_INDEX_MINIMUM_CANDIDATES + 5);

        $logs = DB::connection(Databases::LOGS);
        $logs->enableQueryLog();

        LogEntry::query()->where('project_id', $this->project->id)->searchMessage('filler');

        $decision = collect($logs->getQueryLog())->pluck('query')->sole();

        expect($decision)->toContain('from (select 1 from "log_entries"')
            ->and($decision)->toContain('limit '.(LogEntry::SEARCH_INDEX_MINIMUM_CANDIDATES + 1));
    });

    it('never runs a decision count for short terms or a forced path', function () {
        $logs = DB::connection(Databases::LOGS);
        $logs->enableQueryLog();

        LogEntry::query()->searchMessage('ab');
        LogEntry::query()->searchMessage('abc', true);
        LogEntry::query()->searchMessage('abc', false);

        expect($logs->getQueryLog())->toBe([]);
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

    it('answers a search containing a NUL byte over HTTP instead of failing', function () {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]));
        logEntry($this->project, 'Payment failed');

        $this->get(route('projects.logs.index', ['project' => $this->project->slug, 'range' => '24h', 'search' => "pay\0ment"]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('logs.total', 0));
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
        return LogEntry::on('mysql_search')->searchMessage($term, true);
    }

    it('matches against the FULLTEXT index in boolean mode with a quoted phrase, then confirms with LIKE', function () {
        $query = mysqlSearch('payment');

        expect($query->toSql())->toBe("select * from `log_entries` where match (`log_entries`.`message`) against (? in boolean mode) and `log_entries`.`message` like ? escape '!'")
            ->and($query->getBindings())->toBe(['"payment"', '%payment%']);
    });

    it('escapes LIKE wildcards', function () {
        expect(mysqlSearch('500%_done!')->getBindings())->toBe(['"500%_done!"', '%500!%!_done!!%']);
    });

    it('uses LIKE for a term with a double quote, which cannot be escaped inside a boolean phrase', function () {
        $query = mysqlSearch('say "done"');

        expect($query->toSql())->not->toContain('match')
            ->and($query->getBindings())->toBe(['%say "done"%']);
    });

    it('uses LIKE for a term with a fragment the ngram parser cannot tokenize, because the phrase would match nothing', function () {
        foreach (['a-b-c', '1.2.3', '[] {}', 'ab !!', '_ _ _', 't failed f', '/api/users?page=2', 'a-bcd', 'x 123 y', 'done_ok'] as $term) {
            expect(mysqlSearch($term)->toSql())->not->toContain('match');
        }

        foreach (['failed for', '/api/users', 'user@example', '-bar', 'été', 'NEAR('] as $term) {
            expect(mysqlSearch($term)->toSql())->toContain('match');
        }
    });

    it('uses LIKE for a term with a symbol, which the ngram parser does not index', function () {
        foreach (['abc😀def', 'a=bcd', 'total+tax', 'price$100', 'x|yz abc', 'a<b>cd'] as $term) {
            expect(mysqlSearch($term)->toSql())->not->toContain('match');
        }

        expect(mysqlSearch('user@example')->toSql())->toContain('match');
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

        expect(LogEntry::on('mariadb_search')->searchMessage('payment', true)->toSql())->not->toContain('match');
    });
});
