<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return ['google_sub' => fake()->unique()->uuid(), 'email' => fake()->safeEmail(), 'name' => fake()->name()];
    }
}
