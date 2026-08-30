<?php

namespace Database\Factories;

use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

class StrategyFactory extends Factory
{
    protected $model = Strategy::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->word(),
            'name' => fake()->company(),
            'config' => [
                'rules' => [
                    'icpKeys' => ['B2B-1', 'B2B-2'],
                    'defaultIcp' => 'B2B-1',
                    'clusters' => [
                        ['key' => 'cluster_1', 'code' => 'C1', 'name' => 'Default Cluster'],
                    ],
                    'hashtags' => ['#b2b', '#saas'],
                    'defaultOwner' => 'Team',
                ],
            ],
        ];
    }
}