<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Trace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Trace>
 */
class TraceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'correlation_id' => (string) Str::uuid(),
            'method' => fake()->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
            'uri' => '/'.fake()->word().'/'.fake()->word(),
            'status_code' => 200,
            'duration_ms' => fake()->numberBetween(10, 500),
            'db_queries_count' => fake()->numberBetween(0, 20),
            'db_time_ms' => fake()->numberBetween(0, 200),
            'memory_used_kb' => fake()->numberBetween(1024, 32768),
            'memory_peak_kb' => fake()->numberBetween(2048, 65536),
            'environment' => 'production',
            'hostname' => fake()->domainWord(),
            'has_errors' => false,
            'has_slow_queries' => false,
            'is_stub' => false,
            'occurred_at' => now(),
        ];
    }
}
