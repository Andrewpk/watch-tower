<?php

namespace App\Models;

use Database\Factories\EndUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person using the monitored application, as reported by Nightwatch `user` records.
 *
 * `identifier` is the same value the other records carry as their `user` field.
 */
#[Fillable([
    'project_id',
    'identifier',
    'name',
    'email',
    'last_seen_at',
])]
class EndUser extends Model
{
    /** @use HasFactory<EndUserFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
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
