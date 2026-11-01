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
    'notification_class',
    'channel',
    'notifiable_type',
    'notifiable_id',
    'queue',
    'status',
    'duration_ms',
    'source_type',
    'source_id',
    'source_label',
    'environment',
    'occurred_at',
])]
class NotificationSend extends Model
{
    /**
     * @var string
     */
    protected $connection = Databases::TELEMETRY;

    use HasUuids;

    protected function casts(): array
    {
        return [
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
