<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Setting;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_save_llm_keys(): void
    {
        $response = $this->postJson('/api/settings', [
            'key' => 'llm_keys',
            'value' => [
                'openai_key' => 'sk-test123',
                'content_api_url' => 'http://localhost:8000/api',
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('settings', ['key' => 'llm_keys']);
    }
}