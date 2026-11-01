<?php

namespace App\Models;

use App\Watch\Databases;
use Database\Factories\EventOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'trace_id',
    'event_class',
    'fired_by',
    'duration_ms',
    'listeners_count',
    'payload',
    'occurred_at',
])]
class EventOccurrence extends Model
{
    /**
     * @var string
     */
    protected $connection = Databases::TELEMETRY;

    /** @use HasFactory<EventOccurrenceFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'duration_ms' => 'integer',
            'listeners_count' => 'integer',
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
