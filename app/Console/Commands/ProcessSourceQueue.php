<?php

namespace App\Console\Commands;

use App\Models\SourceInputQueue;
use App\Models\Strategy;
use App\Services\EdenAIWebService;
use App\Services\QuickInputAgentService;
use Illuminate\Console\Command;

/**
 * Verarbeitet die SourceInputQueue: pending Items → LLM-Angle-Extraktion
 * über den bestehenden QuickInputAgentService-Pfad (derselbe Trichter wie
 * Quick Input) → extracted_angles als Drafts ablegen (status: done).
 *
 * Drafts warten anschließend auf Nutzer-Approval in der UI
 * (/quellen?tab=queue) und werden dort via /api/angles/batch übernommen.
 */
class ProcessSourceQueue extends Command
{
    protected $signature = 'sources:process-queue
        {--limit=20 : Maximale Anzahl Items pro Lauf}
        {--dry-run : Nur anzeigen, nicht verarbeiten}';

    protected $description = 'Eingangs-Queue automatischer Quellen verarbeiten (Draft-Angles extrahieren)';

    public function handle(QuickInputAgentService $agent, EdenAIWebService $web): int
    {
        $items = SourceInputQueue::where('status', 'pending')
            ->with('strategy')
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($items->isEmpty()) {
            $this->info('Queue ist leer — nichts zu verarbeiten.');
            return self::SUCCESS;
        }

        $this->info($items->count() . ' Queue-Items werden verarbeitet…');
        $rows = [];

        foreach ($items as $item) {
            $strategy = $item->strategy ?? Strategy::find($item->strategy_id);
            if (! $strategy) {
                $rows[] = [$item->id, mb_substr((string) $item->item_title, 0, 40), '❌ keine Strategie'];
                $item->update(['status' => 'rejected']);
                continue;
            }

            if ($this->option('dry-run')) {
                $rows[] = [$item->id, mb_substr((string) $item->item_title, 0, 40), 'dry-run'];
                continue;
            }

            $item->update(['status' => 'processing']);

            $content = $item->raw_content;

            // Feed-Auszüge sind oft zu kurz für sinnvolle Angle-Extraktion:
            // Volltext per Firecrawl nachladen.
            if (mb_strlen($content) < 600 && $item->item_url && $web->configured()) {
                $scraped = $web->scrape($item->item_url);
                if ($scraped['success'] && mb_strlen(trim((string) $scraped['content'])) > mb_strlen($content)) {
                    $content = mb_substr(trim((string) $scraped['content']), 0, 15000);
                    $item->update(['raw_content' => $content]);
                }
            }

            $result = $agent->extractAngles($content, $strategy, 3);

            if (! empty($result['error'])) {
                $rows[] = [$item->id, mb_substr((string) $item->item_title, 0, 40), '⚠️ ' . $result['error']];
                $item->update(['status' => 'pending']); // Retry beim nächsten Lauf
                continue;
            }

            $angles = $result['angles'] ?? [];
            if ($angles === []) {
                // Nichts Extrahierbares → Item still verwerfen (kein Ballast im Eingang-Tab)
                $item->update(['status' => 'rejected', 'extracted_angles' => []]);
                $rows[] = [$item->id, mb_substr((string) $item->item_title, 0, 40), '○ keine Angles (verworfen)'];
                continue;
            }

            $item->update([
                'status' => 'done',
                'extracted_angles' => $angles,
            ]);

            $rows[] = [$item->id, mb_substr((string) $item->item_title, 0, 40), '✅ ' . count($angles) . ' Draft-Angles'];
        }

        $this->table(['Item', 'Titel', 'Ergebnis'], $rows);

        return self::SUCCESS;
    }
}
