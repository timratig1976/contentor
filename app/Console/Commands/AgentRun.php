<?php

namespace App\Console\Commands;

use App\Services\Agents\CoordinatorAgent;
use Illuminate\Console\Command;

class AgentRun extends Command
{
    protected $signature = 'agent:run
                            {--strategy=viscale : The strategy key to use}
                            {--message= : Run a single message non-interactively}';

    protected $description = 'Start the Contentor multi-agent workflow (replaces content-agent/main.py)';

    public function handle(CoordinatorAgent $coordinator): int
    {
        $strategy = $this->option('strategy');
        $singleMessage = $this->option('message');

        if ($singleMessage) {
            return $this->runSingle($coordinator, $singleMessage, $strategy);
        }

        $this->info('============================================================');
        $this->info('🤖  Contentor Agent — Multi-Agent Content Workflow');
        $this->info('============================================================');
        $this->newLine();
        $this->line('Gib dein Thema oder deine Anfrage ein (z. B.):');
        $this->line('  "Recherchiere zum Thema CRM-Datenqualität und produziere LinkedIn-Posts"');
        $this->line('  "Recherchiere Forecast-Pains für die Persona <Name>"');
        $this->line('  "Analysiere und ranke alle Angles im Batch crm-trends-2026"');
        $this->line('  "Produziere Content aus den Top-3-Angles als Newsletter"');
        $this->newLine();
        $this->line('Gib <comment>exit</comment> ein zum Beenden.');
        $this->line('------------------------------------------------------------');

        while (true) {
            $input = $this->ask('👤 Du');

            if (in_array(strtolower(trim($input ?? '')), ['exit', 'quit', 'q'], true)) {
                $this->info('👋 Tschüss!');
                break;
            }

            if (empty(trim($input ?? ''))) {
                continue;
            }

            $this->line('');
            $this->info('🤖 Agent:');

            $result = $coordinator->run($input, $strategy);

            if ($result['status'] === 'error') {
                $this->error('Fehler: ' . ($result['error'] ?? 'Unbekannt'));
            } else {
                $this->line($result['text']);
            }

            $this->line('');
            $this->line('------------------------------------------------------------');
        }

        return self::SUCCESS;
    }

    private function runSingle(CoordinatorAgent $coordinator, string $message, string $strategy): int
    {
        $result = $coordinator->run($message, $strategy);

        if ($result['status'] === 'error') {
            $this->error('Fehler: ' . ($result['error'] ?? 'Unbekannt'));
            return self::FAILURE;
        }

        $this->line($result['text']);
        return self::SUCCESS;
    }
}
