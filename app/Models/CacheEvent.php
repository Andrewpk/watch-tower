<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'trace_id',
    'key',
    'store',
    'operation',
    'succeeded',
    'duration_ms',
    'source_type',
    'source_label',
    'environment',
    'occurred_at',
])]
class CacheEvent extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'duration_ms' => 'integer',
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
}
