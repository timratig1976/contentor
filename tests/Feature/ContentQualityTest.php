<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Strategy;
use App\Services\ContentQualityService;
use App\Services\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Quality-Gate: Regel-Check, Fix-Anweisungen und Score-Persistenz.
 * LLM-Calls werden gemockt (deterministisch + kein externer API-Zugriff).
 */
class ContentQualityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeStrategy(array $ruleOverrides = []): Strategy
    {
        return Strategy::create([
            'key' => 'test-unit-' . uniqid(),
            'name' => 'Test Unit',
            'config' => ['rules' => array_merge([
                'icpKeys' => ['B2B-1'],
                'defaultIcp' => 'B2B-1',
                'forbiddenPatterns' => ['beste[nrsm]?'],
            ], $ruleOverrides)],
        ]);
    }

    private function makeItem(Strategy $strategy, string $content, string $format = 'linkedin_post'): ContentItem
    {
        return ContentItem::create([
            'strategy_id' => $strategy->id,
            'type' => 'post',
            'format' => $format,
            'title' => 'Test',
            'content' => $content,
            'status' => 'in_produktion',
        ]);
    }

    public function test_rule_check_detects_tone_violation_and_missing_cta(): void
    {
        $strategy = $this->makeStrategy(['mandatoryCta' => 'Pipeline-Review buchen']);
        $service = app(ContentQualityService::class);

        // Text mit "besten" (verboten) und ohne Pflicht-CTA
        $check = $service->ruleCheck(
            str_repeat('Der beste Weg zu sauberem Forecast. ', 40),
            'linkedin_post',
            $strategy,
            []
        );

        $this->assertNotEmpty($check['violations']);
        $this->assertTrue($check['missing_cta']);
        $this->assertTrue($service->hasBlockingFindings($check));

        $instruction = $service->fixInstruction($check, $strategy);
        $this->assertStringContainsString('Pipeline-Review buchen', $instruction);
        $this->assertStringContainsString('verbotenen', $instruction);
    }

    public function test_rule_check_passes_clean_content(): void
    {
        $strategy = $this->makeStrategy(['mandatoryCta' => 'Pipeline-Review buchen']);
        $service = app(ContentQualityService::class);

        // ~150 Wörter (über linkedin_post-Minimum 120), keine Verbote, CTA enthalten
        $clean = str_repeat('Saubere Datenhygiene macht den Forecast planbar und die Pipeline belastbar für jede Entscheidung im Vertrieb. ', 10)
            . 'Mehr dazu im Beitrag. Pipeline-Review buchen und Struktur prüfen.';

        $check = $service->ruleCheck($clean, 'linkedin_post', $strategy, []);

        $this->assertEmpty($check['violations']);
        $this->assertFalse($check['missing_cta']);
        $this->assertFalse($service->hasBlockingFindings($check));
    }

    public function test_gate_persists_score_flags_and_fixed_content(): void
    {
        $strategy = $this->makeStrategy(['mandatoryCta' => 'CTA hier']);

        // LLM-Mock: Fix-Runde liefert sauberen Text (~150 Wörter, CTA enthalten),
        // Review liefert Score — danach ist Runde 2 befundfrei → Loop endet
        $fixedClean = str_repeat('Saubere Datenhygiene macht den Forecast planbar und die Pipeline belastbar für jede Entscheidung im Vertrieb. ', 10) . 'CTA hier.';
        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('chat')->once()
            ->withArgs(fn ($agent) => $agent === 'production')
            ->andReturn(['status' => 'success', 'text' => $fixedClean, 'usage' => [], 'cost' => null, 'error' => null, 'raw' => []]);
        $llm->shouldReceive('chat')->once()
            ->withArgs(fn ($agent) => $agent === 'review')
            ->andReturn(['status' => 'success', 'text' => json_encode(['score' => 8, 'comment' => 'Starker Hook, CTA passt.', 'issues' => []]), 'usage' => [], 'cost' => null, 'error' => null, 'raw' => []]);
        $this->app->instance(LlmService::class, $llm);

        $service = app(ContentQualityService::class);

        // Ausgangstext: Regelverstoß + fehlender CTA → muss Fix-Runde auslösen
        $item = $this->makeItem($strategy, str_repeat('Der beste Forecast-Trick. ', 40));
        $gated = $service->gate($item, $strategy, []);

        $this->assertSame(8, (int) $gated->quality_score);
        $this->assertSame('Starker Hook, CTA passt.', $gated->quality_comment);
        $this->assertSame(1, $gated->quality_flags['fix_rounds']);
        $this->assertStringContainsString('CTA hier', $gated->content);
        $this->assertDatabaseHas('content_items', [
            'id' => $item->id,
            'quality_score' => 8,
        ]);
    }

    public function test_gate_keeps_content_when_fix_llm_fails(): void
    {
        $strategy = $this->makeStrategy();

        $llm = Mockery::mock(LlmService::class);
        // Fix-Runden schlagen fehl (kein Textverlust!), Review ebenfalls
        $llm->shouldReceive('chat')->andReturn(['status' => 'error', 'text' => null, 'usage' => [], 'cost' => null, 'error' => 'timeout', 'raw' => []]);
        $this->app->instance(LlmService::class, $llm);

        $service = app(ContentQualityService::class);
        $original = str_repeat('Der beste Weg. ', 40);
        $item = $this->makeItem($strategy, $original);
        $gated = $service->gate($item, $strategy, []);

        // Regel-Enforcement (deterministisch) ersetzt "beste" → Text geändert, aber nie geleert
        $this->assertNotEmpty($gated->content);
        $this->assertNull($gated->quality_score);
        $this->assertSame(0, $gated->quality_flags['fix_rounds']);
    }
}
