<?php

namespace App\Models;

use App\Watch\Databases;
use Database\Factories\MetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'period_start',
    'period_end',
    'aggregation_level',
    'environment',
    'requests_count',
    'errors_count',
    'slow_requests_count',
    'avg_response_time_ms',
    'p50_response_time_ms',
    'p95_response_time_ms',
    'p99_response_time_ms',
    'total_queries',
    'avg_query_time_ms',
])]
class Metric extends Model
{
    /**
     * @var string
     */
    protected $connection = Databases::TELEMETRY;

    /** @use HasFactory<MetricFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'requests_count' => 'integer',
            'errors_count' => 'integer',
            'slow_requests_count' => 'integer',
            'avg_response_time_ms' => 'decimal:2',
            'p50_response_time_ms' => 'integer',
            'p95_response_time_ms' => 'integer',
            'p99_response_time_ms' => 'integer',
            'total_queries' => 'integer',
            'avg_query_time_ms' => 'decimal:3',
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
