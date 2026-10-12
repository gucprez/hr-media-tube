<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $first = fake()->firstName();

        return [
            'first_name' => $first,
            'last_name' => fake()->lastName(),
            'username' => strtolower($first).fake()->unique()->numberBetween(100, 99999),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'Password123',
            'remember_token' => \Illuminate\Support\Str::random(10),
            'is_active' => true,
        ];
    }
}
