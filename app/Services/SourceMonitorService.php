<?php

namespace App\Services;

use App\Models\Angle;
use App\Models\Source;

/**
 * Quellen-Monitor: crawlt überwachte Quellen (via EdenAI/Firecrawl),
 * vergleicht Content-Hashes und extrahiert bei Änderungen neue Angles.
 * Das Event-Logging übernimmt der EdenAIWebService automatisch.
 */
class SourceMonitorService
{
    private const FREQUENCY_DAYS = [
        'daily' => 1,
        'weekly' => 7,
        'biweekly' => 14,
    ];

    public function __construct(private EdenAIWebService $web)
    {
    }

    /**
     * Alle fälligen Monitor-Quellen prüfen.
     *
     * @return array{checked: int, changed: int, errors: int, details: array}
     */
    public function run(?string $strategyKey = null, bool $force = false): array
    {
        $query = Source::where('monitor', true)
            ->where('type', 'url')
            ->whereNotNull('url')
            ->with('strategy');

        if ($strategyKey) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $strategyKey));
        }

        $due = $query->get()->filter(fn (Source $s) => $force || $this->isDue($s));

        $details = [];
        $changed = 0;
        $errors = 0;

        foreach ($due as $source) {
            $result = $this->checkSource($source);
            $details[] = $result;
            $changed += ($result['status'] === 'changed') ? 1 : 0;
            $errors += ($result['status'] === 'error') ? 1 : 0;
        }

        return [
            'checked' => $due->count(),
            'changed' => $changed,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    /**
     * Einzelne Quelle crawlen + Change-Detection + ggf. Angles extrahieren.
     * Erst-Crawl (kein Hash) gilt ebenfalls als "changed", damit sofort Angles entstehen.
     */
    public function checkSource(Source $source): array
    {
        if (empty($source->url)) {
            return ['source' => $source->id, 'url' => null, 'status' => 'error', 'error' => 'Keine URL hinterlegt.'];
        }

        $result = $this->web->scrape($source->url);

        if (! $result['success']) {
            return ['source' => $source->id, 'url' => $source->url, 'status' => 'error', 'error' => $result['error']];
        }

        $content = trim((string) $result['content']);
        if ($content === '') {
            return ['source' => $source->id, 'url' => $source->url, 'status' => 'error', 'error' => 'Leerer Inhalt erhalten'];
        }

        $firstCheck = $source->content_hash === null;
        $hash = md5($content);
        $changed = $firstCheck || $source->content_hash !== $hash;

        $source->update([
            'last_checked_at' => now(),
            'content_hash' => $hash,
            'last_content_preview' => mb_substr($content, 0, 480),
            'raw_content' => $content,
        ]);

        if (! $changed) {
            return ['source' => $source->id, 'url' => $source->url, 'status' => 'unchanged', 'angles_created' => 0];
        }

        $angles = $this->extractAnglesFromContent($content, $source);

        return [
            'source' => $source->id,
            'url' => $source->url,
            'status' => 'changed',
            'angles_created' => count($angles),
        ];
    }

    /**
     * Ist die Quelle fällig laut Frequenz?
     */
    public function isDue(Source $source): bool
    {
        if (! $source->last_checked_at) {
            return true;
        }

        $days = self::FREQUENCY_DAYS[$source->frequency] ?? 7;

        return $source->last_checked_at->diffInDays(now()) >= $days;
    }

    /**
     * Aus gecrawltem Content Angle-würdige Sätze extrahieren (wie QuickInput).
     */
    private function extractAnglesFromContent(string $content, Source $source): array
    {
        $strategy = $source->strategy;
        if (! $strategy) {
            return [];
        }

        $rulesService = app(ContentRulesService::class);
        $sentences = preg_split('/(?<=[.!?])\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
        $scored = [];

        foreach ($sentences as $sentence) {
            $score = 0;
            $lower = mb_strtolower($sentence);

            if (preg_match('/ist|sind|sollte|müssen|kann nicht|ohne|nie|immer|falsch|richtig|problem|lösung|fehler/iu', $sentence)) {
                $score += 2;
            }
            if (preg_match('/die meisten|alle|niemand|jeder|immer|nie/iu', $lower)) {
                $score += 2;
            }
            if (mb_strlen($sentence) > 30 && mb_strlen($sentence) < 200) {
                $score += 1;
            }
            if (preg_match('/\d+%|\d+x|\d+\s*(€|\$|prozent|fach|mal)/i', $sentence)) {
                $score += 3;
            }

            if ($score >= 3) {
                $scored[] = ['text' => $sentence, 'score' => $score];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] - $a['score']);
        $top = array_slice($scored, 0, 3);

        $batchKey = $source->batch_key ?: 'monitor-' . now()->format('Ymd-His');
        $angles = [];

        foreach ($top as $item) {
            $angles[] = Angle::create([
                'strategy_id' => $strategy->id,
                'source_id' => $source->id,
                'angle' => mb_substr($item['text'], 0, 500),
                'batch_key' => $batchKey,
                'icp' => $rulesService->guessIcp($item['text'], null, $strategy),
                'pain_cluster' => ($c = $rulesService->pickPainCluster($item['text'], $strategy))
                    ? "{$c['code']} · {$c['name']}" : null,
                'statement_type' => $rulesService->pickStatementType(null, 'text', $item['text']),
            ]);
        }

        return $angles;
    }
}
