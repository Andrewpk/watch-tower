<?php

namespace App\Models;

use Database\Factories\ErrorOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'trace_id',
    'error_group_id',
    'exception_class',
    'message',
    'stacktrace',
    'fingerprint',
    'user_identifier',
    'user_email',
    'user_name',
    'file',
    'line',
    'is_handled',
    'environment',
    'release_version',
    'language_version',
    'framework_version',
    'hostname',
    'context',
    'source_type',
    'source_label',
    'occurred_at',
])]
class ErrorOccurrence extends Model
{
    /** @use HasFactory<ErrorOccurrenceFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'stacktrace' => 'array',
            'context' => 'array',
            'line' => 'integer',
            'is_handled' => 'boolean',
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

    /**
     * @return BelongsTo<ErrorGroup, $this>
     */
    public function errorGroup(): BelongsTo
    {
        return $this->belongsTo(ErrorGroup::class);
    }

    /**
     * What ran when the exception was raised, as a type (`request`, `command`, `job`, `schedule` or an HTTP method)
     * and a label (a URI, command name or job class).
     *
     * The stored execution source wins unless it is `request`: a queued job inherits the trace id of the request that
     * dispatched it, so the trace exists but names the wrong cause. Without a source the trace decides, and records
     * from before the source was stored fall back to `COMMAND` and the basename of the file.
     *
     * @return array{type: string, label: string|null}
     */
    public function triggeredBy(): array
    {
        $trace = $this->trace;

        if ($this->source_type !== null && $this->source_type !== 'request') {
            return ['type' => $this->source_type, 'label' => $this->source_label];
        }

        if ($trace !== null && ! $trace->is_stub && $trace->method !== '') {
            return ['type' => $trace->method, 'label' => $trace->uri !== '' ? $trace->uri : null];
        }

        if ($this->source_type === 'request') {
            return ['type' => 'request', 'label' => $this->source_label];
        }

        return ['type' => $trace?->method ?: 'COMMAND', 'label' => $this->fallbackLabel()];
    }

    private function fallbackLabel(): ?string
    {
        if ($this->trace?->uri) {
            return $this->trace->uri;
        }

        if ($this->file) {
            $parts = explode('/', $this->file);

            return end($parts) ?: $this->file;
        }

        return null;
    }
}
