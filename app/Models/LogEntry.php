<?php

namespace App\Models;

use App\Watch\Databases;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Expression;

#[Fillable([
    'project_id',
    'trace_id',
    'level',
    'message',
    'source_type',
    'source_label',
    'user_name',
    'context',
    'environment',
    'occurred_at',
])]
class LogEntry extends Model
{
    /**
     * Shortest search term the full-text index can match; trigrams and ngrams are at least this long.
     */
    public const MINIMUM_INDEXED_SEARCH_LENGTH = 3;

    /**
     * A search goes through the full-text index only when more rows than this pass the other filters.
     */
    public const SEARCH_INDEX_MINIMUM_CANDIDATES = 100000;

    /**
     * @var string
     */
    protected $connection = Databases::LOGS;

    use HasUuids;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Keep the entries whose message contains `$term`, ignoring case, like `LIKE '%term%'`, using the full-text index
     * added by the `add_full_text_index_to_log_entries` migration when that is faster.
     *
     * Apply the other filters (project, time range, level, user) first: the scope counts the rows they leave, up to
     * {@see SEARCH_INDEX_MINIMUM_CANDIDATES} + 1, using their indexes. When few rows are left, scanning their messages
     * with LIKE is faster than asking the index for every match in the table, so only a larger set goes through the
     * index: the SQLite FTS5 trigram table, or the MySQL ngram FULLTEXT index, which only narrows the candidates, so a
     * final LIKE keeps the result an exact substring match. Terms shorter than
     * {@see MINIMUM_INDEXED_SEARCH_LENGTH} characters, terms the index cannot express (see {@see canUseIndex()}) and other
     * databases always use LIKE. Both paths return the same
     * rows and search the term as literal text (`%`, `_` and the escape character are escaped), with one exception:
     * SQLite's LIKE only folds the case of ASCII letters, while the trigram index folds all of Unicode.
     *
     * @param  Builder<LogEntry>  $query
     * @param  bool|null  $useIndex  Force the index (true) or LIKE (false) instead of choosing by the number of candidates.
     */
    #[Scope]
    protected function searchMessage(Builder $query, string $term, ?bool $useIndex = null): void
    {
        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'mysql'], true)
            && $this->canUseIndex($driver, $term)
            && ($useIndex ?? $this->hasManyCandidates($query))) {
            if ($driver === 'sqlite') {
                $query->whereIn($query->qualifyColumn('seq'), function ($subquery) use ($term): void {
                    $subquery->select('rowid')
                        ->from('log_entries_fts')
                        ->whereRaw('log_entries_fts MATCH ?', ['"'.str_replace('"', '""', $term).'"']);
                });

                return;
            }

            $query->whereFullText($query->qualifyColumn('message'), '"'.str_replace('"', '', $term).'"', ['mode' => 'boolean']);
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $query->whereRaw($query->getGrammar()->wrap($query->qualifyColumn('message'))." like ? escape '!'", ['%'.$escaped.'%']);
    }

    /**
     * Whether the full-text index can answer a search for the term.
     *
     * SQLite's FTS5 fails on a NUL byte ("unterminated string"). MySQL's boolean mode cannot quote a double quote. The
     * ngram parser splits a term at spaces and punctuation and cannot tokenize a fragment shorter than
     * `ngram_token_size`, and a phrase with such a fragment matches nothing: `t failed f` and `?page=2` miss rows that
     * LIKE finds. So every fragment must have {@see MINIMUM_INDEXED_SEARCH_LENGTH} letters or digits, which also holds
     * for a server that sets `ngram_token_size` to 3.
     */
    private function canUseIndex(string $driver, string $term): bool
    {
        if (mb_strlen($term) < self::MINIMUM_INDEXED_SEARCH_LENGTH || str_contains($term, "\0")) {
            return false;
        }

        if ($driver !== 'mysql') {
            return true;
        }

        if (str_contains($term, '"')) {
            return false;
        }

        $fragments = preg_split('/[^\p{L}\p{N}]+/u', $term, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return $fragments !== []
            && collect($fragments)->every(fn (string $fragment): bool => mb_strlen($fragment) >= self::MINIMUM_INDEXED_SEARCH_LENGTH);
    }

    /**
     * Whether more than {@see SEARCH_INDEX_MINIMUM_CANDIDATES} rows match the query so far. The count stops early.
     *
     * @param  Builder<LogEntry>  $query
     */
    private function hasManyCandidates(Builder $query): bool
    {
        $candidates = $query->clone()->toBase()->reorder()->select(new Expression('1'))->limit(self::SEARCH_INDEX_MINIMUM_CANDIDATES + 1);

        return $query->getConnection()->query()->fromSub($candidates, 'candidates')->count() > self::SEARCH_INDEX_MINIMUM_CANDIDATES;
    }
}
