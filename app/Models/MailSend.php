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
    'mailable_class',
    'mailer',
    'subject',
    'from_address',
    'from_name',
    'recipients_to',
    'recipients_cc',
    'recipients_bcc',
    'recipients_count',
    'attachments_count',
    'queue',
    'status',
    'duration_ms',
    'source_type',
    'source_id',
    'source_label',
    'environment',
    'occurred_at',
])]
class MailSend extends Model
{
    /**
     * @var string
     */
    protected $connection = Databases::TELEMETRY;

    use HasUuids;

    protected function casts(): array
    {
        return [
            'recipients_to' => 'array',
            'recipients_cc' => 'array',
            'recipients_bcc' => 'array',
            'recipients_count' => 'integer',
            'attachments_count' => 'integer',
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
