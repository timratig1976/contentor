<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentKpi;
use App\Models\Strategy;
use Illuminate\Support\Collection;

/**
 * KPI-Lernschleife: aggregiert Performance-Daten veröffentlichter Posts
 * und destilliert daraus Erkenntnisse für die Content-Generierung.
 *
 * Lern-Dimensionen (jeweils Format × Dimension → Ø Performance):
 * - variant_pattern   (z. B. contrarian vs. data_drop vs. story)
 * - statement_type    (z. B. Drastisch vs. Mechanismus)
 * - icp               (z. B. B2B-1 vs. B2B-2)
 * - persona           (welche Autoren-Stimme performt)
 *
 * Die Erkenntnisse werden als kompakter Prompt-Block ("PERFORMANCE-LEARNINGS")
 * in die Content-Generierung injiziert, damit das LLM bevorzugt, was
 * nachweislich funktioniert.
 */
class KpiLearningService
{
    /**
     * Mindestanzahl gemessener Posts pro Gruppe, bevor eine Erkenntnis
     * als belastbar gilt (sonst Rauschen bei 1-2 Datenpunkten).
     */
    private const MIN_SAMPLES = 2;

    /**
     * Anzahl Top-Erkenntnisse pro Dimension im Prompt.
     */
    private const TOP_N = 2;

    /**
     * Primäre Erfolgsmetrik für das Ranking.
     * Reihenfolge führt zu einem einzigen Score (höher = besser).
     */
    private function successScore(ContentKpi $kpi): float
    {
        // Conversion-lastig gewichtet: Leads zählen am meisten,
        // dann Engagement/CTR als Reichweiten-Qualitätssignal.
        return ($kpi->leads * 10)
            + ($kpi->conversions * 15)
            + ($kpi->clicks * 1.0)
            + ($kpi->engagementRate() * 5)
            + ($kpi->ctr() * 3);
    }

    /**
     * Alle KPI-Einträge einer Strategie mit ihrem Content-Item,
     * aufsteigend nach Messdatum (neueste Messung pro Item gewinnt).
     *
     * @return Collection<int,object{kpi:ContentKpi,item:ContentItem}>
     */
    private function measuredItems(Strategy $strategy): Collection
    {
        $kpis = ContentKpi::with('contentItem')
            ->where('strategy_id', $strategy->id)
            ->orderBy('measured_at')
            ->get();

        // Nur neueste Messung pro Content-Item berücksichtigen
        return $kpis
            ->filter(fn (ContentKpi $kpi) => $kpi->contentItem !== null)
            ->groupBy('content_item_id')
            ->map(fn ($group) => [
                'kpi' => $group->last(),
                'item' => $group->last()->contentItem,
            ])
            ->values();
    }

    /**
     * Aggregiert eine Lern-Dimension: Gruppe → Ø Score, Ø Impressions, Anzahl.
     *
     * @param Collection<int,object{kpi:ContentKpi,item:ContentItem}> $rows
     * @param callable(ContentItem):?string $groupKey
     * @return Collection<string,object{avg_score:float,avg_impressions:float,samples:int,total_leads:int}>
     */
    private function aggregate(Collection $rows, callable $groupKey): Collection
    {
        return $rows
            ->groupBy(fn ($row) => $groupKey($row['item']))
            ->filter(fn ($group, $key) => $key !== null && $key !== '')
            ->map(function ($group) {
                $scores = $group->map(fn ($row) => $this->successScore($row['kpi']));
                $impressions = $group->map(fn ($row) => $row['kpi']->impressions ?: $row['kpi']->reach);

                return (object) [
                    'avg_score' => round($scores->avg() ?? 0, 1),
                    'avg_impressions' => (int) round($impressions->avg() ?? 0),
                    'samples' => $group->count(),
                    'total_leads' => (int) $group->sum(fn ($row) => $row['kpi']->leads),
                    'total_conversions' => (int) $group->sum(fn ($row) => $row['kpi']->conversions),
                ];
            })
            ->filter(fn ($agg) => $agg->samples >= self::MIN_SAMPLES)
            ->sortByDesc('avg_score');
    }

    /**
     * Volles Learning-Report für eine Strategie (für UI/Board).
     *
     * @return array{
     *   measured_items:int,
     *   total_leads:int,
     *   total_conversions:int,
     *   best_format:?array, worst_format:?array,
     *   by_pattern:array, by_statement:array, by_icp:array, by_persona:array,
     *   insights:array<int,string>
     * }
     */
    public function report(Strategy $strategy): array
    {
        $rows = $this->measuredItems($strategy);

        if ($rows->isEmpty()) {
            return [
                'measured_items' => 0,
                'total_leads' => 0,
                'total_conversions' => 0,
                'best_format' => null,
                'worst_format' => null,
                'by_pattern' => [], 'by_statement' => [], 'by_icp' => [], 'by_persona' => [],
                'insights' => [],
            ];
        }

        $byFormat = $this->aggregate($rows, fn (ContentItem $i) => $i->format);
        $byPattern = $this->aggregate($rows, fn (ContentItem $i) => $i->variant_pattern);
        $byStatement = $this->aggregate($rows, fn (ContentItem $i) => $i->statement_type);
        $byIcp = $this->aggregate($rows, fn (ContentItem $i) => $i->icp);
        $byPersona = $this->aggregate($rows, function (ContentItem $i) {
            return $i->persona?->name;
        });

        return [
            'measured_items' => $rows->count(),
            'total_leads' => (int) $rows->sum(fn ($r) => $r['kpi']->leads),
            'total_conversions' => (int) $rows->sum(fn ($r) => $r['kpi']->conversions),
            'best_format' => $this->topEntry($byFormat),
            'worst_format' => $this->bottomEntry($byFormat),
            'by_pattern' => $this->toArray($byPattern),
            'by_statement' => $this->toArray($byStatement),
            'by_icp' => $this->toArray($byIcp),
            'by_persona' => $this->toArray($byPersona),
            'insights' => $this->buildInsights($byFormat, $byPattern, $byStatement, $byIcp),
        ];
    }

    /**
     * Kompakter Prompt-Block mit den wichtigsten Learnings.
     * Leerstring, wenn (noch) keine belastbaren Daten vorliegen.
     */
    public function promptBlock(Strategy $strategy): string
    {
        $rows = $this->measuredItems($strategy);
        if ($rows->count() < self::MIN_SAMPLES) {
            return '';
        }

        $byFormat = $this->aggregate($rows, fn (ContentItem $i) => $i->format);
        $byPattern = $this->aggregate($rows, fn (ContentItem $i) => $i->variant_pattern);
        $byStatement = $this->aggregate($rows, fn (ContentItem $i) => $i->statement_type);
        $byIcp = $this->aggregate($rows, fn (ContentItem $i) => $i->icp);

        $lines = [];
        $lines[] = '## PERFORMANCE-LEARNINGS (aus echten KPI-Daten dieser Strategie)';

        foreach ([
            'Format' => $byFormat,
            'Pattern' => $byPattern,
            'Statement-Typ' => $byStatement,
            'ICP' => $byIcp,
        ] as $dimLabel => $agg) {
            $top = $agg->take(self::TOP_N);
            if ($top->isEmpty()) {
                continue;
            }
            $entries = $top->map(function ($aggItem, $key) {
                return sprintf('%s (Ø Score %.0f aus %d Posts, %d Leads)', $key, $aggItem->avg_score, $aggItem->samples, $aggItem->total_leads);
            })->implode('; ');
            $lines[] = "- {$dimLabel}-Erfolg: {$entries}";
        }

        $bottom = $byFormat->last();
        if ($bottom && $byFormat->count() > 1) {
            $lines[] = '- Schwächstes Format bisher: ' . array_key_last($byFormat->all()) . ' (diese Aufbereitung vermeiden)';
        }

        $lines[] = 'Bevorzuge Stilmittel, Patterns und Argumentationswege der erfolgreichen Gruppen — ohne die Format-Vorgaben des aktuellen Auftrags zu verletzen.';

        return implode("\n", $lines);
    }

    /**
     * Menschlich lesbare Erkenntnisse für das Performance-Board.
     *
     * @return array<int,string>
     */
    private function buildInsights(Collection $byFormat, Collection $byPattern, Collection $byStatement, Collection $byIcp): array
    {
        $insights = [];

        if ($byFormat->isNotEmpty()) {
            $best = $byFormat->first();
            $bestKey = $byFormat->keys()->first();
            $insights[] = "Stärkstes Format: {$bestKey} — Ø Score {$best->avg_score} bei {$best->samples} gemessenen Posts ({$best->total_leads} Leads, {$best->total_conversions} Conversions).";
        }

        if ($byPattern->isNotEmpty()) {
            $bestKey = $byPattern->keys()->first();
            $insights[] = "Bestes Pattern: {$bestKey} — setzt dieses Muster bevorzugt ein.";
        }

        if ($byStatement->isNotEmpty()) {
            $bestKey = $byStatement->keys()->first();
            $insights[] = "Wirksamster Statement-Typ: {$bestKey}.";
        }

        if ($byIcp->isNotEmpty() ) {
            $bestKey = $byIcp->keys()->first();
            $insights[] = "Responsivste Zielgruppe: {$bestKey} — hier lohnt mehr Frequenz.";
        }

        return $insights;
    }

    /**
     * @param Collection<string,object> $agg
     * @return array<string,array{avg_score:float,avg_impressions:int,samples:int,total_leads:int,total_conversions:int}>
     */
    private function toArray(Collection $agg): array
    {
        return $agg->map(fn ($a) => [
            'avg_score' => $a->avg_score,
            'avg_impressions' => $a->avg_impressions,
            'samples' => $a->samples,
            'total_leads' => $a->total_leads,
            'total_conversions' => $a->total_conversions,
        ])->all();
    }

    /**
     * @param Collection<string,object> $agg
     * @return array{key:string,avg_score:float,samples:int}|null
     */
    private function topEntry(Collection $agg): ?array
    {
        if ($agg->isEmpty()) {
            return null;
        }
        $key = $agg->keys()->first();
        return ['key' => $key, 'avg_score' => $agg[$key]->avg_score, 'samples' => $agg[$key]->samples];
    }

    /**
     * @param Collection<string,object> $agg
     * @return array{key:string,avg_score:float,samples:int}|null
     */
    private function bottomEntry(Collection $agg): ?array
    {
        if ($agg->count() < 2) {
            return null;
        }
        $key = $agg->keys()->last();
        return ['key' => $key, 'avg_score' => $agg[$key]->avg_score, 'samples' => $agg[$key]->samples];
    }
}
