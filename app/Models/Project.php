<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable([
    'organization_id',
    'name',
    'slug',
    'description',
    'sampling_rate',
    'retention_days',
])]
#[Hidden(['api_key_hash', 'previous_api_key_hash'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids;

    /**
     * How many leading characters of a key are kept as its display prefix.
     */
    public const API_KEY_PREFIX_LENGTH = 10;

    /**
     * Minimum minutes between two writes of `api_key_last_used_at`.
     */
    public const API_KEY_LAST_USED_THROTTLE_MINUTES = 5;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'api_key_version' => 1,
    ];

    /**
     * Hide projects that are being deleted.
     *
     * `deleting_at` is set by the delete action and the queued
     * `DeleteProjectData` job removes the row once its telemetry is gone. The
     * `notDeleting` scope keeps the project out of every query in between:
     * lists, route binding, ingest authentication and queued events. The job
     * opts out with `Project::withoutGlobalScope('notDeleting')`.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('notDeleting', function (Builder $query): void {
            $query->whereNull($query->getModel()->qualifyColumn('deleting_at'));
        });
    }

    protected function casts(): array
    {
        return [
            'sampling_rate' => 'float',
            'retention_days' => 'integer',
            'deleting_at' => 'datetime',
            'api_key_version' => 'integer',
            'previous_api_key_expires_at' => 'datetime',
            'api_key_last_used_at' => 'datetime',
        ];
    }

    /**
     * Generate a new plain-text API key.
     */
    public static function generateApiKey(): string
    {
        return 'pk_'.Str::random(40);
    }

    /**
     * Hash an API key the way it is stored and looked up.
     *
     * Keys are long random strings, so a fast unsalted hash is enough and lets
     * the lookup use the unique index.
     */
    public static function hashApiKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * The columns that represent a key at rest: its hash and display prefix.
     *
     * @return array{api_key_hash: string, api_key_prefix: string}
     */
    public static function apiKeyAttributes(string $key): array
    {
        return [
            'api_key_hash' => self::hashApiKey($key),
            'api_key_prefix' => substr($key, 0, self::API_KEY_PREFIX_LENGTH),
        ];
    }

    /**
     * Find the project a presented key belongs to.
     *
     * The current key always matches. The previous key matches only while the
     * grace period set by `rotateApiKey()` is still running.
     */
    public static function findByApiKey(string $key): ?self
    {
        $hash = self::hashApiKey($key);

        return static::query()
            ->where(function (Builder $query) use ($hash): void {
                $query->where('api_key_hash', $hash)
                    ->orWhere(function (Builder $previous) use ($hash): void {
                        $previous->where('previous_api_key_hash', $hash)
                            ->where('previous_api_key_expires_at', '>', now());
                    });
            })
            ->first();
    }

    /**
     * Give an unsaved project its first key and return the plain text.
     *
     * The plain text exists only in the return value: the caller must show it
     * to the user now, because only its hash is stored.
     */
    public function issueApiKey(): string
    {
        $key = self::generateApiKey();

        $this->forceFill(self::apiKeyAttributes($key));

        return $key;
    }

    /**
     * Replace the key and return the new plain text.
     *
     * With a grace period the old key keeps working until it ends; without
     * one it stops working immediately. Either way the key version goes up,
     * which revokes agent tokens issued with keys older than the previous one.
     */
    public function rotateApiKey(int $gracePeriodSeconds = 0): string
    {
        $key = self::generateApiKey();
        $keepsPreviousKey = $gracePeriodSeconds > 0;

        $this->forceFill([
            ...self::apiKeyAttributes($key),
            'previous_api_key_hash' => $keepsPreviousKey ? $this->api_key_hash : null,
            'previous_api_key_expires_at' => $keepsPreviousKey ? now()->addSeconds($gracePeriodSeconds) : null,
            'api_key_version' => $this->api_key_version + 1,
        ])->save();

        return $key;
    }

    /**
     * Whether the key replaced by the last rotation still authenticates.
     */
    public function hasActivePreviousApiKey(): bool
    {
        return $this->previous_api_key_hash !== null
            && $this->previous_api_key_expires_at?->isFuture() === true;
    }

    /**
     * The key version a presented key stands for: the current version, or the
     * one before it when the key is the previous key still in its grace period.
     */
    public function apiKeyVersionFor(string $key): int
    {
        return hash_equals((string) $this->api_key_hash, self::hashApiKey($key))
            ? $this->api_key_version
            : $this->api_key_version - 1;
    }

    /**
     * Whether an agent token issued with the given key version may still be used.
     *
     * Tokens issued before key versions existed carry none (`null`); they stay
     * valid until the first rotation.
     */
    public function acceptsApiKeyVersion(?int $version): bool
    {
        if ($version === null) {
            return $this->api_key_version === 1;
        }

        return $version === $this->api_key_version
            || ($version === $this->api_key_version - 1 && $this->hasActivePreviousApiKey());
    }

    /**
     * Stamp `api_key_last_used_at`, at most once per throttle window.
     *
     * Written with a plain query so that `updated_at` is left alone.
     */
    public function recordApiKeyUse(): void
    {
        $threshold = now()->subMinutes(self::API_KEY_LAST_USED_THROTTLE_MINUTES);

        if ($this->api_key_last_used_at !== null && $this->api_key_last_used_at->gt($threshold)) {
            return;
        }

        DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->where(fn ($query) => $query->whereNull('api_key_last_used_at')->orWhere('api_key_last_used_at', '<=', $threshold))
            ->update(['api_key_last_used_at' => now()]);

        $this->setAttribute('api_key_last_used_at', now());
        $this->syncOriginalAttribute('api_key_last_used_at');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<Trace, $this>
     */
    public function traces(): HasMany
    {
        return $this->hasMany(Trace::class);
    }

    /**
     * @return HasMany<ErrorGroup, $this>
     */
    public function errorGroups(): HasMany
    {
        return $this->hasMany(ErrorGroup::class);
    }

    /**
     * @return HasMany<ErrorOccurrence, $this>
     */
    public function errorOccurrences(): HasMany
    {
        return $this->hasMany(ErrorOccurrence::class);
    }

    /**
     * @return HasMany<TraceQuery, $this>
     */
    public function traceQueries(): HasMany
    {
        return $this->hasMany(TraceQuery::class);
    }

    /**
     * @return HasMany<EventOccurrence, $this>
     */
    public function eventOccurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    /**
     * @return HasMany<QueueJobRun, $this>
     */
    public function queueJobRuns(): HasMany
    {
        return $this->hasMany(QueueJobRun::class);
    }

    /**
     * @return HasMany<CommandRun, $this>
     */
    public function commandRuns(): HasMany
    {
        return $this->hasMany(CommandRun::class);
    }

    /**
     * @return HasMany<ScheduledTaskRun, $this>
     */
    public function scheduledTaskRuns(): HasMany
    {
        return $this->hasMany(ScheduledTaskRun::class);
    }
}
