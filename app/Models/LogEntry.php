<?php

namespace App\Models;

use App\Watch\Databases;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * Keep the entries whose message contains `$term`, ignoring case, like `LIKE '%term%'` but using the full-text
     * index added by the `add_full_text_index_to_log_entries` migration.
     *
     * Terms of three or more characters go through the index: the SQLite FTS5 trigram table, or the MySQL ngram
     * FULLTEXT index, which only narrows the candidates, so a final LIKE keeps the result an exact substring match.
     * The term is always searched as literal text. Shorter terms, and any other database, use LIKE with `%`, `_` and
     * the escape character escaped.
     *
     * @param  Builder<LogEntry>  $query
     */
    #[Scope]
    protected function searchMessage(Builder $query, string $term): void
    {
        $driver = $query->getConnection()->getDriverName();

        if (mb_strlen($term) >= self::MINIMUM_INDEXED_SEARCH_LENGTH) {
            if ($driver === 'sqlite') {
                $query->whereIn($query->qualifyColumn('seq'), function ($subquery) use ($term): void {
                    $subquery->select('rowid')
                        ->from('log_entries_fts')
                        ->whereRaw('log_entries_fts MATCH ?', ['"'.str_replace('"', '""', $term).'"']);
                });

                return;
            }

            if ($driver === 'mysql') {
                $query->whereFullText($query->qualifyColumn('message'), '"'.str_replace('"', '', $term).'"', ['mode' => 'boolean']);
            }
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $query->whereRaw($query->getGrammar()->wrap($query->qualifyColumn('message'))." like ? escape '!'", ['%'.$escaped.'%']);
    }
}
