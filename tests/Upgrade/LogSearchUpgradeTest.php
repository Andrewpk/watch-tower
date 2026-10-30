<?php

use App\Models\LogEntry;
use App\Watch\Databases;
use Illuminate\Support\Facades\DB;
use Tests\Upgrade\Support\SplitUpgrade;

/**
 * The full-text index is built on an install that already holds log entries.
 */
beforeEach(function () {
    $this->upgrade = new SplitUpgrade;
});

afterEach(function () {
    $this->upgrade->cleanUp();
});

function checkUpgradedFullTextIntegrity(): void
{
    DB::connection(Databases::LOGS)->statement("insert into log_entries_fts(log_entries_fts, rank) values('integrity-check', 1)");
}

it('makes pre-existing logs searchable after the rebuild', function () {
    $this->upgrade->legacy()->seed(25);

    expect($this->upgrade->migrate())->toBe(0);

    checkUpgradedFullTextIntegrity();

    expect(LogEntry::query()->searchMessage('ayment 7 acc')->pluck('message')->all())->toBe(['Payment 7 accepted'])
        ->and(LogEntry::query()->searchMessage('PAYMENT')->count())->toBe(25)
        ->and(LogEntry::query()->searchMessage('no such text')->count())->toBe(0)
        ->and(DB::connection(Databases::LOGS)->table('log_entries_fts')->whereRaw('log_entries_fts MATCH ?', ['"accepted"'])->count())->toBe(25);
});

it('indexes entries written after the upgrade and forgets the ones that are deleted', function () {
    $this->upgrade->legacy()->seed(10);
    $this->upgrade->migrate();

    $project = DB::table('projects')->value('id');
    LogEntry::create(['project_id' => $project, 'level' => 'error', 'message' => 'Brand new failure', 'occurred_at' => '2026-10-02 08:00:00']);
    LogEntry::query()->where('message', 'Payment 3 accepted')->delete();

    checkUpgradedFullTextIntegrity();

    expect(LogEntry::query()->searchMessage('new failure')->count())->toBe(1)
        ->and(LogEntry::query()->searchMessage('Payment 3')->count())->toBe(0)
        ->and(LogEntry::query()->searchMessage('Payment')->count())->toBe(9);
});

it('keeps the index valid and aligned after the logs file is vacuumed', function () {
    $this->upgrade->legacy()->seed(30);
    $this->upgrade->migrate();

    $logs = DB::connection(Databases::LOGS);
    $logs->table('log_entries')->where('seq', '<=', 20)->whereRaw('seq % 2 = 0')->delete();
    $seqByMessageBefore = $logs->table('log_entries')->orderBy('seq')->pluck('seq', 'message')->all();

    $logs->statement('VACUUM');

    checkUpgradedFullTextIntegrity();

    expect($logs->table('log_entries')->orderBy('seq')->pluck('seq', 'message')->all())->toBe($seqByMessageBefore)
        ->and(LogEntry::query()->searchMessage('Payment 10 accepted')->pluck('message')->all())->toBe(['Payment 10 accepted'])
        ->and(LogEntry::query()->searchMessage('Payment 11 accepted')->count())->toBe(0)
        ->and(LogEntry::query()->searchMessage('ayment')->count())->toBe(20);
});

it('can be rolled back and applied again without losing searchability', function () {
    $this->upgrade->legacy()->seed(12);
    $this->upgrade->migrate();

    expect($this->upgrade->rollback())->toBe(0)
        ->and(DB::connection('sqlite')->table('log_entries')->count())->toBe(12);

    expect($this->upgrade->migrate())->toBe(0);

    checkUpgradedFullTextIntegrity();

    expect(LogEntry::query()->searchMessage('ayment')->count())->toBe(12);
});
