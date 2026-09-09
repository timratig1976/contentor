<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentKpi;
use App\Models\Strategy;
use App\Services\KpiLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KPI-Lernschleife: Erfassung, Aggregation und Prompt-Injection.
 */
class KpiLearningTest extends TestCase
{
    use RefreshDatabase;

    private function makeStrategy(): Strategy
    {
        return Strategy::create([
            'key' => 'test-strategy',
            'name' => 'Test Strategy',
            'config' => ['rules' => ['icpKeys' => ['B2B-1'], 'defaultIcp' => 'B2B-1']],
        ]);
    }

    private function makeItem(Strategy $strategy, string $format, ?string $pattern, ?string $icp, ?string $statement = null): ContentItem
    {
        return ContentItem::create([
            'strategy_id' => $strategy->id,
            'type' => 'post',
            'format' => $format,
            'title' => 'Test',
            'content' => 'Test-Content mit ausreichender Länge für den Parser.',
            'status' => 'live',
            'icp' => $icp,
            'statement_type' => $statement,
            'variant_pattern' => $pattern,
        ]);
    }

    private function addKpi(ContentItem $item, array $overrides = []): ContentKpi
    {
        return ContentKpi::create(array_merge([
            'strategy_id' => $item->strategy_id,
            'content_item_id' => $item->id,
            'measured_at' => now()->toDateString(),
            'impressions' => 1000,
            'clicks' => 20,
            'likes' => 30,
            'comments' => 5,
            'shares' => 2,
            'leads' => 3,
        ], $overrides));
    }

    public function test_kpi_can_be_recorded_via_api_and_sets_item_live(): void
    {
        $strategy = $this->makeStrategy();
        $item = $this->makeItem($strategy, 'blog_post', 'framework', 'B2B-1');
        $item->update(['status' => 'geplant']);

        $response = $this->postJson('/api/content-kpis', [
            'content_item_id' => $item->id,
            'impressions' => 2500,
            'clicks' => 42,
            'leads' => 4,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('content_kpis', [
            'content_item_id' => $item->id,
            'impressions' => 2500,
            'leads' => 4,
        ]);
        $this->assertSame('live', $item->fresh()->status);
    }

    public function test_same_item_and_date_updates_instead_of_duplicating(): void
    {
        $strategy = $this->makeStrategy();
        $item = $this->makeItem($strategy, 'blog_post', 'framework', 'B2B-1');

        $this->postJson('/api/content-kpis', ['content_item_id' => $item->id, 'impressions' => 100])->assertCreated();
        $this->postJson('/api/content-kpis', ['content_item_id' => $item->id, 'impressions' => 200])->assertCreated();

        $this->assertSame(1, ContentKpi::where('content_item_id', $item->id)->count());
        $this->assertSame(200, (int) ContentKpi::where('content_item_id', $item->id)->value('impressions'));
    }

    public function test_learning_report_aggregates_by_pattern_and_needs_min_samples(): void
    {
        $strategy = $this->makeStrategy();
        $service = app(KpiLearningService::class);

        // Ohne Daten: leerer Report
        $empty = $service->report($strategy);
        $this->assertSame(0, $empty['measured_items']);
        $this->assertSame('', $service->promptBlock($strategy));

        // 2 starke data_drop-Posts, 2 schwache contrarian-Posts
        foreach ([1, 2] as $i) {
            $this->addKpi($this->makeItem($strategy, 'linkedin_post', 'data_drop', 'B2B-2', 'Drastisch'), [
                'impressions' => 5000, 'leads' => 10, 'clicks' => 100, 'measured_at' => now()->subDays($i)->toDateString(),
            ]);
        }
        foreach ([1, 2] as $i) {
            $this->addKpi($this->makeItem($strategy, 'linkedin_post', 'contrarian', 'B2B-1', 'Direkt'), [
                'impressions' => 500, 'leads' => 0, 'clicks' => 5, 'measured_at' => now()->subDays($i)->toDateString(),
            ]);
        }

        $report = $service->report($strategy);
        $this->assertSame(4, $report['measured_items']);
        $this->assertNotEmpty($report['insights']);

        // data_drop muss vor contrarian ranken
        $patterns = array_keys($report['by_pattern']);
        $this->assertSame('data_drop', $patterns[0]);

        // Prompt-Block enthält das Top-Pattern und die Dimension
        $block = $service->promptBlock($strategy);
        $this->assertStringContainsString('PERFORMANCE-LEARNINGS', $block);
        $this->assertStringContainsString('data_drop', $block);
        $this->assertStringContainsString('Pattern-Erfolg', $block);
    }

    public function test_latest_measurement_per_item_wins(): void
    {
        $strategy = $this->makeStrategy();
        $item = $this->makeItem($strategy, 'linkedin_post', 'data_drop', 'B2B-1');

        $this->addKpi($item, ['measured_at' => now()->subDays(7)->toDateString(), 'leads' => 1]);
        $this->addKpi($item, ['measured_at' => now()->toDateString(), 'leads' => 9]);

        $report = app(KpiLearningService::class)->report($strategy);
        // Ein Item gezählt; 9 Leads aus der neuesten Messung (1 alte verworfen)
        $this->assertSame(1, $report['measured_items']);
        $this->assertSame(9, $report['total_leads']);
    }
}
