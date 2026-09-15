<?php

namespace App\Services\Agents;

use App\Models\Setting;
use App\Services\AgentContextService;

/**
 * Angle Agent — evaluates, refines and ranks content angles.
 * Ports content-agent/agents/angle_agent.py.
 */
class AngleAgent
{
    public function __construct(
        private AgentOrchestrator $orchestrator,
        private AgentTools $tools,
        private AgentContextService $contextService,
    ) {}

    private function systemPrompt(string $strategy): string
    {
        $custom = Setting::where('key', 'agent_prompts')->first()?->value['angle'] ?? null;
        $base = $custom ?? <<<PROMPT
Du bist ein Angle-Entwicklungs-Agent. Deine Aufgabe ist es,
Content-Angles zu bewerten, zu verfeinern und zu ranken.

## Vorgehen:
1. **Strategie laden**: Rufe get_strategy auf,
   um Brand Voice, ICPs, Channel Rules und Content-Strategie zu laden.
   Diese Daten sind deine Referenz für alle Bewertungen.

2. **Angles analysieren**: Rufe list_angles() auf, um alle Angles zu sehen.
   Bewerte jeden Angle nach 4 Kriterien (je 1-3 Punkte):
   - **Zielgruppe (r_zielgruppe)**: Wie präzise trifft der Angle den ICP?
     1 = generisch, 2 = relevant, 3 = punktgenau
   - **Viscale-Fit (r_viscale_fit)**: Wie gut passt der Angle zur Viscale-Positionierung?
     1 = schwach, 2 = passend, 3 = perfekter Fit
   - **Schärfe (r_schaerfe)**: Wie provokativ/meinungsstark ist der Angle?
     1 = neutral, 2 = pointiert, 3 = scharf/provokativ
   - **Timing (r_timing)**: Wie aktuell/relevant ist das Thema jetzt?
     1 = Evergreen, 2 = aktuell, 3 = hochaktuell/Trend

3. **Ranking speichern**: Aktualisiere jeden Angle via update_angle() mit den Scores
   UND der Begründung (score_reasoning).

4. **Neue Angles vorschlagen**: Wenn du Lücken in der Strategie erkennst,
   schlage neue Angles via create_angle() vor.

5. **Batch-Ranking anzeigen**: Am Ende get_batch_ranking() für den Überblick.

## Wichtig:
- Ein Angle mit Score ≥10 ist 🟢 Top-Performer
- Ein Angle mit Score 7-9 ist 🟡 solide, kann verbessert werden
- Ein Angle mit Score <7 ist 🔴 schwach, sollte überarbeitet werden
- Begründe jede Bewertung kurz
- ICP-Matching: Prüfe, ob der Angle den Pain-Cluster des ICPs adressiert

## Ausgabeformat für update_angle():
Übergib bei jedem Update IMMER auch score_reasoning mit einer 1-2-Sätze-Begründung, z.B.:
  score_reasoning="Trifft B2B-1 präzise (CRM-Datenqualität als Kernproblem). "
                  "Schärfe hoch durch kontrarianen Take gegen Tool-Fokus."
PROMPT;

        $context = $this->contextService->build($strategy);
        return $base . "\n\n" . $context;
    }

    public function run(string $userMessage, string $strategy = 'viscale'): array
    {
        $registry = new ToolRegistry();
        $toolNames = [
            'get_strategy', 'list_angles', 'update_angle',
            'create_angle', 'get_batch_ranking',
        ];
        $this->tools->register($registry, $toolNames, $strategy);

        return $this->orchestrator->run(
            agent: 'angle',
            systemPrompt: $this->systemPrompt($strategy),
            messages: [['role' => 'user', 'content' => $userMessage]],
            tools: $this->tools->definitionsFor($toolNames),
            registry: $registry,
        );
    }
}
