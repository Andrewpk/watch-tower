<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MaintenanceRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * When a scheduled maintenance task last ran.
 *
 * One row per task, keyed by the task name. It lives in the database rather
 * than the cache because a cleared cache would look like "never ran".
 *
 * The migration seeds a row with `rows_deleted` null as a baseline, so a null
 * `rows_deleted` means that no real run has been recorded yet.
 */
#[Fillable(['task', 'started_at', 'finished_at', 'rows_deleted'])]
class MaintenanceRun extends Model
{
    /** @use HasFactory<MaintenanceRunFactory> */
    use HasFactory;

    /**
     * The retention prune, `php artisan watch:prune`.
     */
    public const TASK_PRUNE = 'watch:prune';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'task';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'rows_deleted' => 'integer',
        ];
    }

    /**
     * Record that a task, started at the given time, finished just now.
     */
    public static function recordFinished(string $task, CarbonInterface $startedAt, int $rowsDeleted): void
    {
        static::query()->upsert(
            [['task' => $task, 'started_at' => $startedAt, 'finished_at' => now(), 'rows_deleted' => $rowsDeleted]],
            ['task'],
            ['started_at', 'finished_at', 'rows_deleted'],
        );
    }

    /**
     * Whether a real run is on record, rather than just the migration's baseline.
     */
    public function hasRecordedRun(): bool
    {
        return $this->rows_deleted !== null;
    }
}
