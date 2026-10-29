<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'command',
    'memory_peak_kb',
    'arguments',
    'options',
    'status',
    'exit_code',
    'duration_ms',
    'output',
    'environment',
    'occurred_at',
])]
class CommandRun extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'memory_peak_kb' => 'integer',
            'arguments' => 'array',
            'options' => 'array',
            'exit_code' => 'integer',
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
