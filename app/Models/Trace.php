<?php

namespace App\Models;

use Database\Factories\TraceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'correlation_id',
    'method',
    'uri',
    'status_code',
    'user_identifier',
    'user_email',
    'user_name',
    'duration_ms',
    'db_queries_count',
    'db_time_ms',
    'memory_used_kb',
    'memory_peak_kb',
    'environment',
    'release_version',
    'hostname',
    'ip_address',
    'user_agent',
    'headers',
    'request_data',
    'response_data',
    'has_errors',
    'has_slow_queries',
    'is_stub',
    'occurred_at',
])]
class Trace extends Model
{
    /** @use HasFactory<TraceFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'db_queries_count' => 'integer',
            'db_time_ms' => 'integer',
            'memory_used_kb' => 'integer',
            'memory_peak_kb' => 'integer',
            'headers' => 'array',
            'request_data' => 'array',
            'response_data' => 'array',
            'has_errors' => 'boolean',
            'has_slow_queries' => 'boolean',
            'is_stub' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Restrict the query to traces backed by a real request record.
     *
     * A stub is created when a child record (query, exception, log, ...) arrives before its request record, so it
     * carries placeholder request fields and must not be counted, bucketed or listed as a request.
     *
     * @param  Builder<Trace>  $query
     */
    #[Scope]
    protected function excludingStubs(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_stub'), false);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<TraceQuery, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(TraceQuery::class);
    }

    /**
     * @return HasMany<ErrorOccurrence, $this>
     */
    public function errors(): HasMany
    {
        return $this->hasMany(ErrorOccurrence::class);
    }

    /**
     * @return HasMany<EventOccurrence, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    /**
     * @return HasMany<QueueJobRun, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(QueueJobRun::class);
    }
}
