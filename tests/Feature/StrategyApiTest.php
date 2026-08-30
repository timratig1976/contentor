<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Strategy;

class StrategyApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_save_brand_voice(): void
    {
        $unit = Strategy::factory()->create(['key' => 'viscale', 'name' => 'viscale']);

        $response = $this->postJson('/api/strategy', [
            'strategy' => 'viscale',
            'key' => 'brand_voice',
            'content' => ['personality' => 'Direkt', 'tone' => 'direkt'],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('content_strategies', ['key' => 'brand_voice']);
    }

    public function test_save_content_personas(): void
    {
        $unit = Strategy::factory()->create(['key' => 'viscale', 'name' => 'viscale']);

        $response = $this->postJson('/api/strategy', [
            'strategy' => 'viscale',
            'key' => 'content_personas',
            'content' => [
                'personas' => [[
                    'name' => 'CEO Persona',
                    'role' => 'CEO',
                    'core_statements' => ['These 1'],
                    'tonality' => ['style' => 'provokativ'],
                    'topic_clusters' => ['CRM'],
                    'channels' => ['linkedin'],
                ]],
            ],
        ]);

        $response->assertStatus(201);
    }
}