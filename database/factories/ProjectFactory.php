<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            ...Project::apiKeyAttributes('pk_test_'.Str::random(40)),
            'sampling_rate' => 1.0,
            'retention_days' => 30,
        ];
    }

    /**
     * Give the project a known plain-text API key for testing. Only its hash
     * and display prefix are stored, as for a real project.
     */
    public function withApiKey(string $key): static
    {
        return $this->state(fn () => Project::apiKeyAttributes($key));
    }
}
