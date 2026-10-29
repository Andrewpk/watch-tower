<?php

namespace App\Models;

use Database\Factories\TraceQueryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'trace_id',
    'query_type',
    'sql',
    'bindings',
    'connection_name',
    'duration_ms',
    'row_count',
    'is_n_plus_one',
    'n_plus_one_group',
    'is_slow',
    'source_type',
    'source_label',
    'occurred_at',
])]
class TraceQuery extends Model
{
    /** @use HasFactory<TraceQueryFactory> */
    use HasFactory, HasUuids;

    protected $table = 'trace_queries';

    protected function casts(): array
    {
        return [
            'bindings' => 'array',
            'duration_ms' => 'decimal:3',
            'row_count' => 'integer',
            'is_n_plus_one' => 'boolean',
            'is_slow' => 'boolean',
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
     * @return BelongsTo<Trace, $this>
     */
    public function trace(): BelongsTo
    {
        return $this->belongsTo(Trace::class);
    }
}
