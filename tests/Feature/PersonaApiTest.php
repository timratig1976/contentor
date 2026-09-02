<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Strategy;
use App\Models\Persona;

class PersonaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persona(): void
    {
        $strategy = Strategy::factory()->create(['key' => 'testunit', 'name' => 'Test Unit']);

        $response = $this->postJson('/api/personas', [
            'strategy' => 'testunit',
            'name' => 'Max Mustermann',
            'role' => 'CEO',
            'positioning' => 'Thought Leader',
            'core_statements' => ['CRM ist kein Tool-Problem'],
            'tonality' => ['style' => 'direkt', 'do' => ['Mechanismus'], 'dont' => ['Buzzwords']],
            'content_attributes' => ['maxLength' => 2000, 'tone' => 'direkt', 'formats' => ['linkedin_post'], 'keywords' => ['CRM']],
            'cadence' => 'weekly',
            'active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('name', 'Max Mustermann');
        $response->assertJsonPath('tonality.style', 'direkt');
        // Persona ist global + per strategy-Feld direkt gemappt
        $response->assertJsonCount(1, 'strategies');
    }

    public function test_list_personas(): void
    {
        $strategy = Strategy::factory()->create(['key' => 'testunit2', 'name' => 'TU2']);
        Persona::factory()->create(['strategy_id' => $strategy->id, 'name' => 'Alice', 'active' => true]);
        Persona::factory()->create(['strategy_id' => $strategy->id, 'name' => 'Bob', 'active' => false]);

        $response = $this->getJson('/api/personas?strategy=testunit2');
        $response->assertStatus(200);
        $response->assertJsonCount(2);
    }

    public function test_update_persona(): void
    {
        $strategy = Strategy::factory()->create(['key' => 'testunit3', 'name' => 'TU3']);
        $persona = Persona::factory()->create(['strategy_id' => $strategy->id, 'name' => 'Old']);

        $response = $this->patchJson("/api/personas/{$persona->id}", [
            'name' => 'Updated',
            'voice' => 'Direkt und analytisch',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('name', 'Updated');
        $response->assertJsonPath('voice', 'Direkt und analytisch');
    }

    public function test_delete_persona(): void
    {
        $strategy = Strategy::factory()->create(['key' => 'testunit4', 'name' => 'TU4']);
        $persona = Persona::factory()->create(['strategy_id' => $strategy->id]);

        $response = $this->deleteJson("/api/personas/{$persona->id}");
        $response->assertStatus(200);
        $this->assertDatabaseMissing('personas', ['id' => $persona->id]);
    }
}