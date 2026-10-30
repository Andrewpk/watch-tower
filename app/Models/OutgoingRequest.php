<?php

namespace App\Models;

use App\Watch\Databases;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'trace_id',
    'method',
    'host',
    'url',
    'status_code',
    'duration_ms',
    'request_size_bytes',
    'response_size_bytes',
    'source_type',
    'source_id',
    'source_label',
    'environment',
    'occurred_at',
])]
class OutgoingRequest extends Model
{
    /**
     * @var string
     */
    protected $connection = Databases::TELEMETRY;

    use HasUuids;

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'request_size_bytes' => 'integer',
            'response_size_bytes' => 'integer',
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
