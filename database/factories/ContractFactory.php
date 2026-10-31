<?php

namespace Database\Factories;

use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    /**
     * A direct draft contract; tests set the parties.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'direct',
            'title' => fake()->sentence(3),
            'total' => 0,
            'middleman' => 'off',
            'status' => 'draft',
            'version' => 1,
        ];
    }
}
