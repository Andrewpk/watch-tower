<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'task',
    'task_hash',
    'lazy_loads',
    'hydrated_models',
    'schedule',
    'schedule_summary',
    'next_run_at',
    'status',
    'exit_code',
    'duration_ms',
    'threshold_ms',
    'output',
    'environment',
    'occurred_at',
])]
class ScheduledTaskRun extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'lazy_loads' => 'integer',
            'hydrated_models' => 'integer',
            'duration_ms' => 'integer',
            'threshold_ms' => 'integer',
            'next_run_at' => 'datetime',
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
