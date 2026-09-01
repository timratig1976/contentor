<?php

namespace Database\Factories;

use App\Models\Persona;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

class PersonaFactory extends Factory
{
    protected $model = Persona::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role' => fake()->jobTitle(),
            'voice' => fake()->sentence(),
            'cadence' => 'weekly',
            'active' => true,
        ];
    }
}