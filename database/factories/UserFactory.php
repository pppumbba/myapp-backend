<?php

namespace Database\Factories;

use App\Models\CustomerUser;
use App\Models\Role;
use App\Models\User;
use App\Support\CustomerSchema;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    /** In tests a new person joins the customer the test works in, as people do by invite. */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($customer = CustomerSchema::current()) {
                CustomerUser::firstOrCreate(['customer_id' => $customer->id, 'user_id' => $user->id]);
            }
        });
    }

    /** Someone who is not in the current customer. */
    public function outside(): static
    {
        return $this->afterCreating(fn (User $user) => CustomerUser::where('user_id', $user->id)->delete());
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Gives the user the admin role, which sees and changes everything. */
    public function admin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->roles()->attach(
            Role::firstOrCreate(['code' => Role::ADMIN], ['name' => 'Administrator'])->id
        ));
    }
}
