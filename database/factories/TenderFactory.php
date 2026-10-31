<?php

namespace Database\Factories;

use App\Models\Tender;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tender>
 */
class TenderFactory extends Factory
{
    /**
     * A draft tender open for a week from tomorrow; tests set the organizer and created_by.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'type' => 'goods',
            'description' => fake()->sentence(),
            'starts_at' => now()->addDay(),
            'closes_at' => now()->addDays(8),
            'status' => 'draft',
            'rule' => [],
        ];
    }
}
