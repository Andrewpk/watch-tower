<?php

namespace Database\Factories;

use App\Models\MaintenanceRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRun>
 */
class MaintenanceRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task' => MaintenanceRun::TASK_PRUNE,
            'finished_at' => now(),
            'rows_deleted' => 0,
        ];
    }
}
