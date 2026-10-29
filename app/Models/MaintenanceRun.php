<?php

namespace App\Models;

use Database\Factories\MaintenanceRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * When a scheduled maintenance task last finished.
 *
 * One row per task, keyed by the task name. It lives in the database rather
 * than the cache because a cleared cache would look like "never ran".
 */
#[Fillable(['task', 'finished_at', 'rows_deleted'])]
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
            'finished_at' => 'datetime',
            'rows_deleted' => 'integer',
        ];
    }

    /**
     * Record that a task finished just now.
     */
    public static function recordFinished(string $task, int $rowsDeleted): void
    {
        static::query()->updateOrCreate(
            ['task' => $task],
            ['finished_at' => now(), 'rows_deleted' => $rowsDeleted],
        );
    }
}
