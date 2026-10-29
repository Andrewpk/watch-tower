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
    'memory_peak_kb',
    'timezone',
    'repeat_seconds',
    'without_overlapping',
    'on_one_server',
    'run_in_background',
    'even_in_maintenance_mode',
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
            'memory_peak_kb' => 'integer',
            'repeat_seconds' => 'integer',
            'without_overlapping' => 'boolean',
            'on_one_server' => 'boolean',
            'run_in_background' => 'boolean',
            'even_in_maintenance_mode' => 'boolean',
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
