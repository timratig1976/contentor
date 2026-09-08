<?php

namespace App\Console\Commands;

use App\Models\Angle;
use App\Services\QuickInputAgentService;
use Illuminate\Console\Command;

class ScoreAngles extends Command
{
    protected $signature = 'angles:score
        {--strategy= : Nur Angles dieser Strategie bewerten (key)}
        {--only-neu : Nur noch ungewertete (neu) Angles bewerten}
        {--limit= : Maximale Anzahl Angles pro Lauf}';

    protected $description = 'Bewertet Angles automatisch per LLM (4 Kriterien) und setzt Ranking + Auto-Approve';

    public function handle(QuickInputAgentService $agentService): int
    {
        $strategyKey = $this->option('strategy');
        $onlyNeu = (bool) $this->option('only-neu');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $query = Angle::with('strategy');

        if ($strategyKey) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $strategyKey));
        }
        if ($onlyNeu) {
            $query->whereIn('status', ['neu', null])->whereNull('ranking_score');
        }

        $angles = $query->get();

        if ($limit) {
            $angles = $angles->take($limit);
        }

        if ($angles->isEmpty()) {
            $this->info('Keine Angles zu bewerten.');
            return self::SUCCESS;
        }

        $scored = 0;
        $failed = 0;

        foreach ($angles as $angle) {
            $strategy = $angle->strategy;
            if (! $strategy) {
                $failed++;
                continue;
            }

            try {
                $score = $agentService->scoreAngle(
                    $angle->angle,
                    $strategy,
                    $angle->icp,
                    $angle->pain_cluster,
                );

                if (! $score) {
                    $this->warn("  {$angle->id}: kein Ergebnis (Key fehlt oder API-Fehler)");
                    $failed++;
                    continue;
                }

                $angle->updateQuietly([
                    'r_zielgruppe'    => $score['r_zielgruppe'],
                    'r_viscale_fit'   => $score['r_viscale_fit'],
                    'r_schaerfe'      => $score['r_schaerfe'],
                    'r_timing'        => $score['r_timing'],
                    'score_reasoning' => $score['score_reasoning'] ?? null,
                ]);

                $angle->updateRanking();
                $scored++;
                $this->line("  ✅ {$angle->id}: Score {$angle->ranking_score}/12 → {$angle->status}");
            } catch (\Throwable $e) {
                $this->error("  ❌ {$angle->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Bewertet: {$scored} · Fehlgeschlagen: {$failed}");

        return $failed > 0 && $scored === 0 ? self::FAILURE : self::SUCCESS;
    }
}