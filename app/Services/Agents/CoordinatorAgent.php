<?php

namespace App\Services\Agents;

use App\Models\Setting;
use App\Services\AgentContextService;

/**
 * Coordinator Agent — orchestrates the full content production workflow.
 * Ports content-agent/agents/coordinator.py.
 */
class CoordinatorAgent
{
    public function __construct(
        private AgentOrchestrator $orchestrator,
        private AgentTools $tools,
        private AgentContextService $contextService,
        private ResearchAgent $researchAgent,
        private AngleAgent $angleAgent,
        private ProductionAgent $productionAgent,
        private ReviewAgent $reviewAgent,
    ) {}

    private function workflowLoopsConfig(): string
    {
        $loops = Setting::where('key', 'workflow_loops')->first()?->value ?? [
            [
                'name' => 'Qualitäts-Loop',
                'from_agent' => 'review',
                'to_agent' => 'production',
                'condition' => 'verdict = "fail"',
                'max_rounds' => 2,
            ],
        ];

        if (empty($loops)) {
            return "Keine Feedback-Loops konfiguriert. Führe den Workflow strikt einmalig aus (Phase 1-4) und brich bei Fehlern ab.";
        }

        $lines = [];
        foreach ($loops as $i => $loop) {
            $num = $i + 1;
            $from = $loop['from_agent'] ?? 'review';
            $to = $loop['to_agent'] ?? 'production';
            $cond = $loop['condition'] ?? 'die Bedingung aus der UI-Konfiguration zutrifft';
            $maxRounds = $loop['max_rounds'] ?? 1;
            $name = $loop['name'] ?? "Loop {$num}";

            $lines[] = "{$num}. **{$name}**";
            $lines[] = "   - Prüfe nach `{$from}`, ob {$cond}.";
            $lines[] = "   - Falls ja: rufe `{$to}` ERNEUT auf und reiche das Feedback aus `{$from}` als zusätzlichen Kontext weiter. Danach wiederhole `{$from}`.";
            $lines[] = "   - MAXIMAL {$maxRounds} Überarbeitungsrunden. Falls danach die Bedingung immer noch zutrifft: brich ab, melde die offenen Issues dem Nutzer und frage, wie weiter.";
        }

        return implode("\n", $lines);
    }

    private function systemPrompt(string $strategy): string
    {
        $custom = Setting::where('key', 'agent_prompts')->first()?->value['coordinator'] ?? null;
        $loopsConfig = $this->workflowLoopsConfig();

        $base = $custom ?? <<<PROMPT
Du bist ein Content-Strategie-Koordinator für die Unit "{$strategy}".
Du orchestrierst den gesamten Content-Produktions-Workflow von der Recherche
bis zum fertigen, reviewten Content.

## Deine Sub-Agenten:
- **research**: Recherchiert Themen, findet Quellen, extrahiert Angles
- **develop_angles**: Bewertet und ranked Angles nach 4 Kriterien
- **produce_content**: Produziert Content aus Top-Angles
- **review_content**: Prüft und verbessert produzierten Content

## Workflow:
1. **Phase 1 — Recherche**: Rufe research auf für neue Themen.
2. **Phase 2 — Angle-Bewertung**: Rufe develop_angles auf nach der Recherche.
3. **Phase 3 — Produktion**: Rufe produce_content auf wenn Angles bereit sind.
4. **Phase 4 — Review**: Rufe review_content auf nach der Produktion.

## Feedback-Loops:
{$loopsConfig}

## Wichtig:
- Führe den Workflow strikt in der Reihenfolge aus
- Gib nach jeder Phase eine kurze Zwischenzusammenfassung
- Bei Fehlern: klare Fehlermeldung, nicht weitermachen
- Am Ende: Gesamtzusammenfassung mit allen erstellten Items und IDs
PROMPT;

        $context = $this->contextService->build($strategy);
        return $base . "\n\n" . $context;
    }

    /**
     * Register sub-agents as callable tools in the coordinator's registry.
     */
    private function registerSubAgents(ToolRegistry $registry, string $strategy): void
    {
        $registry->register('research', function (string $task) use ($strategy) {
            $result = $this->researchAgent->run($task, $strategy);
            return $result['text'] ?? 'Kein Ergebnis vom Research-Agent.';
        });

        $registry->register('develop_angles', function (string $task) use ($strategy) {
            $result = $this->angleAgent->run($task, $strategy);
            return $result['text'] ?? 'Kein Ergebnis vom Angle-Agent.';
        });

        $registry->register('produce_content', function (string $task) use ($strategy) {
            $result = $this->productionAgent->run($task, $strategy);
            return $result['text'] ?? 'Kein Ergebnis vom Production-Agent.';
        });

        $registry->register('review_content', function (string $task) use ($strategy) {
            $result = $this->reviewAgent->run($task, $strategy);
            return $result['text'] ?? 'Kein Ergebnis vom Review-Agent.';
        });
    }

    /** @return array<int,array> */
    private function subAgentDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'research',
                    'description' => 'Recherchiere ein Thema: durchsuche das Web, speichere Quellen und extrahiere erste Angles. Nutze dies für neue Themen-Recherchen.',
                    'parameters' => ['type' => 'object', 'properties' => ['task' => ['type' => 'string', 'description' => 'Die Recherche-Aufgabe']], 'required' => ['task']],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'develop_angles',
                    'description' => 'Analysiere, bewerte und ranke Angles. Lade Strategie-Daten, vergebe Scores (1-3) für Zielgruppe, Viscale-Fit, Schärfe und Timing. Nutze dies nach der Recherche oder wenn Angles bewertet werden müssen.',
                    'parameters' => ['type' => 'object', 'properties' => ['task' => ['type' => 'string', 'description' => 'Die Angle-Aufgabe']], 'required' => ['task']],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'produce_content',
                    'description' => 'Produziere Content aus Top-Angles. Generiere LinkedIn-Posts, Ad Copies, Newsletter oder Landing-Page-Headlines basierend auf Brand Voice und Templates. Nutze dies, wenn Angles bereit zur Produktion sind.',
                    'parameters' => ['type' => 'object', 'properties' => ['task' => ['type' => 'string', 'description' => 'Die Produktions-Aufgabe']], 'required' => ['task']],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'review_content',
                    'description' => 'Prüfe produzierten Content gegen Brand Voice, Channel Rules und Qualitätskriterien. Gib konkretes Feedback und wende Verbesserungen an. Nutze dies nach der Content-Produktion.',
                    'parameters' => ['type' => 'object', 'properties' => ['task' => ['type' => 'string', 'description' => 'Die Review-Aufgabe']], 'required' => ['task']],
                ],
            ],
        ];
    }

    public function run(string $userMessage, string $strategy = 'viscale'): array
    {
        $registry = new ToolRegistry();
        $this->registerSubAgents($registry, $strategy);

        // Also register general tools the coordinator might need directly
        $generalTools = ['get_overview', 'get_batch_ranking', 'revise_content'];
        $this->tools->register($registry, $generalTools, $strategy);

        $tools = array_merge(
            $this->subAgentDefinitions(),
            $this->tools->definitionsFor($generalTools),
        );

        return $this->orchestrator->run(
            agent: 'coordinator',
            systemPrompt: $this->systemPrompt($strategy),
            messages: [['role' => 'user', 'content' => $userMessage]],
            tools: $tools,
            registry: $registry,
        );
    }
}
