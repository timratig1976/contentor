<?php

namespace App\Console\Commands;

use App\Models\Angle;
use App\Services\ContentRulesService;
use Illuminate\Console\Command;

class BackfillAngleFunnel extends Command
{
    protected $signature = 'angles:backfill-funnel {--dry-run : Nur anzeigen, nicht speichern}';
    protected $description = 'Setzt die Funnel-Stufe (ToFu/MoFu/BoFu) für Angles ohne Funnel-Wert — per ICP-Default oder Text-Heuristik.';

    public function handle(ContentRulesService $rules): int
    {
        $angles = Angle::with('strategy')->whereNull('funnel')->get();

        if ($angles->isEmpty()) {
            $this->info('Keine Angles ohne Funnel-Wert gefunden.');
            return self::SUCCESS;
        }

        $this->info($angles->count() . ' Angles ohne Funnel gefunden.');
        $rows = [];
        $counts = ['icp_default' => 0, 'heuristic' => 0];

        foreach ($angles as $angle) {
            $source = 'heuristic';
            $funnel = null;

            // 1) ICP-Default aus den icp_definitions der Strategie
            if ($angle->icp && $angle->strategy) {
                $funnel = $rules->resolveIcpFunnel($angle->strategy, $angle->icp);
                if ($funnel) {
                    $source = 'icp_default';
                }
            }

            // 2) Text-Heuristik
            if (! $funnel) {
                $guess = $rules->guessFunnel($angle->angle);
                $funnel = $guess['funnel'];
            }

            $counts[$source]++;

            if (! $this->option('dry-run')) {
                $angle->update(['funnel' => $funnel]);
            }

            $rows[] = [
                $angle->id,
                mb_substr($angle->angle, 0, 50) . '…',
                $funnel,
                $source === 'icp_default' ? 'ICP-Default' : 'Text-Analyse',
            ];
        }

        $this->table(['Angle', 'Text', 'Funnel', 'Quelle'], $rows);
        $this->line("ICP-Default: {$counts['icp_default']} · Text-Analyse: {$counts['heuristic']}");

        if ($this->option('dry-run')) {
            $this->warn('Dry-Run: nichts gespeichert. Ohne --dry-run erneut ausführen zum Übernehmen.');
        } else {
            $this->info('✅ ' . $angles->count() . ' Angles aktualisiert.');
        }

        return self::SUCCESS;
    }
}
