<?php

namespace Database\Factories;

use App\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Model Factories
|--------------------------------------------------------------------------
|
| This directory should contain each of the model factory definitions for
| your application. Factories provide a convenient way to generate new
| model instances for testing / seeding your application's database.
|
*/

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $faker = $this->faker;

        return [
            'name' => $faker->name,
            'username' => $faker->userName,
            'email' => $faker->unique()->safeEmail,
            'avatar' => 'https://example.test/avatar.png',
            'password' => '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm', // secret
            'remember_token' => Str::random(10),
        ];
    }

    public function activated(): static
    {
        return $this->state(fn () => ['activated_at' => now()]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['is_admin' => true]);
    }
}
