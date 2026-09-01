<?php

namespace Tests\Feature;

use App\Models\Angle;
use App\Models\ContentItem;
use App\Models\ContentMedia;
use App\Models\ContentStrategy;
use App\Models\Persona;
use App\Models\RedaktionsplanEntry;
use App\Models\Source;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyDeleteCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function seedChildData(Strategy $strategy): void
    {
        $source = Source::create([
            'strategy_id' => $strategy->id,
            'title' => 'Test Quelle',
            'type' => 'url',
        ]);

        $angle = Angle::create([
            'strategy_id' => $strategy->id,
            'source_id' => $source->id,
            'angle' => 'Test Angle',
        ]);

        $item = ContentItem::create([
            'strategy_id' => $strategy->id,
            'angle_id' => $angle->id,
            'type' => 'post',
            'status' => 'idee',
        ]);

        ContentMedia::create([
            'content_item_id' => $item->id,
            'strategy_id' => $strategy->id,
            'type' => 'image',
            'status' => 'briefing',
        ]);

        Persona::factory()->for($strategy)->create();

        RedaktionsplanEntry::create([
            'strategy_id' => $strategy->id,
            'content_item_id' => $item->id,
            'planned_date' => '2026-09-15',
            'channel' => 'linkedin',
        ]);

        ContentStrategy::create([
            'strategy_id' => $strategy->id,
            'key' => 'brand_voice',
            'content' => ['personality' => 'Test'],
        ]);
    }

    public function test_delete_strategy_removes_all_child_data(): void
    {
        $strategy = Strategy::factory()->create(['key' => 'victim', 'name' => 'Victim']);
        $other = Strategy::factory()->create(['key' => 'survivor', 'name' => 'Survivor']);

        $this->seedChildData($strategy);
        // Schwester-Daten, die NICHT gelöscht werden dürfen
        Persona::factory()->for($other)->create();
        Source::create(['strategy_id' => $other->id, 'title' => 'Andere Quelle', 'type' => 'url']);

        $response = $this->deleteJson('/api/strategies/' . $strategy->id);

        $response->assertStatus(200);
        $response->assertJsonPath('deleted', true);
        $response->assertJsonPath('next_strategy_key', $other->key);
        $response->assertJson([
            'counts' => [
                'content_media' => 1,
                'redaktionsplan_entries' => 1,
                'content_items' => 1,
                'angles' => 1,
                'sources' => 1,
                'personas' => 1,
                'content_strategies' => 1,
            ],
        ]);

        // Alles weg
        $this->assertDatabaseMissing('strategies', ['id' => $strategy->id]);
        $this->assertSame(0, Source::where('strategy_id', $strategy->id)->count());
        $this->assertSame(0, Angle::where('strategy_id', $strategy->id)->count());
        $this->assertSame(0, ContentItem::where('strategy_id', $strategy->id)->count());
        $this->assertSame(0, ContentMedia::where('strategy_id', $strategy->id)->count());
        $this->assertSame(0, Persona::where('strategy_id', $strategy->id)->count());
        $this->assertSame(0, RedaktionsplanEntry::where('strategy_id', $strategy->id)->count());
        $this->assertSame(0, ContentStrategy::where('strategy_id', $strategy->id)->count());

        // Schwester-Daten intakt
        $this->assertDatabaseHas('strategies', ['id' => $other->id]);
        $this->assertSame(1, Persona::where('strategy_id', $other->id)->count());
        $this->assertSame(1, Source::where('strategy_id', $other->id)->count());
    }

    public function test_delete_nonexistent_strategy_returns_404(): void
    {
        $this->deleteJson('/api/strategies/999999')->assertStatus(404);
    }

    public function test_strategy_page_handles_missing_strategy_gracefully(): void
    {
        Strategy::factory()->create(['key' => 'viscale', 'name' => 'Viscale']);

        $response = $this->get('/strategie?strategy=gibts-nicht');

        $response->assertRedirect(route('strategie', ['strategy' => 'viscale']));
    }

    public function test_strategy_page_renders_empty_state_without_strategies(): void
    {
        $response = $this->get('/strategie');

        $response->assertOk();
    }
}
