<?php

namespace Database\Factories;

use App\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThreadFactory extends Factory
{
    protected $model = Thread::class;

    public function definition(): array
    {
        return ['title' => $this->faker->sentence(), 'node_id' => 1];
    }
}
