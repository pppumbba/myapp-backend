<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * An active company with a made-up registry number and director.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registry_number' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'name' => fake()->company(),
            'status' => 'active',
            'director_registry_number' => 'УБ'.fake()->numerify('########'),
            'director_name' => fake()->name(),
            'checked_at' => now(),
        ];
    }

    /** The default state, named for readability in tests. */
    public function active(): static
    {
        return $this->state(['status' => 'active', 'frozen_at' => null]);
    }
}
