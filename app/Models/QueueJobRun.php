<?php

namespace App\Models;

use Database\Factories\QueueJobRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'trace_id',
    'job_id',
    'job_class',
    'queue',
    'connection',
    'memory_peak_kb',
    'dispatched_at',
    'started_at',
    'completed_at',
    'failed_at',
    'duration_ms',
    'attempts',
    'lazy_loads',
    'hydrated_models',
    'status',
    'payload',
    'user_identifier',
    'user_email',
    'user_name',
    'exception',
    'environment',
])]
class QueueJobRun extends Model
{
    /** @use HasFactory<QueueJobRunFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'memory_peak_kb' => 'integer',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'duration_ms' => 'integer',
            'attempts' => 'integer',
            'lazy_loads' => 'integer',
            'hydrated_models' => 'integer',
            'payload' => 'array',
            'exception' => 'array',
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
