<?php

namespace App\Services\Agents;

use App\Models\Setting;
use App\Services\AgentContextService;

/**
 * Production Agent — produces content from angles using brand voice and templates.
 * Ports content-agent/agents/production_agent.py.
 */
class ProductionAgent
{
    public function __construct(
        private AgentOrchestrator $orchestrator,
        private AgentTools $tools,
        private AgentContextService $contextService,
    ) {}

    private function systemPrompt(string $strategy): string
    {
        $custom = Setting::where('key', 'agent_prompts')->first()?->value['production'] ?? null;
        $base = $custom ?? <<<PROMPT
Du bist ein Content-Production-Agent. Deine Aufgabe ist es,
aus Top-Angles fertigen Content für verschiedene Kanäle zu produzieren.

## Vorgehen:
1. **Strategie laden**: Rufe get_strategy auf,
   um Brand Voice, Channel Rules, Post-Templates und ICP-Channel-Mapping zu laden.

2. **Top-Angles identifizieren**: Rufe get_batch_ranking() oder list_angles(sort="ranking_score")
   auf, um die besten Angles zu finden. Nimm die Top 3 mit Score ≥ 8.

3. **Pattern wählen**: Wähle pro Angle ein passendes inhaltliches Pattern:
   - contrarian_take, data_drop, mistake_post, framework
   - Nutze das ICP-Channel-Mapping aus der Strategie (best_for-Feld der Patterns)

4. **Content produzieren**: Für jeden Top-Angle rufe produce_content() auf mit:
   - angle_id: Die Angle-ID
   - format: Passe das Format an den ICP und Channel an
     - "linkedin_post" für Thought Leadership
     - "ad_copy" für Paid Social
     - "newsletter" für Newsletter
     - "landing_page_headlines" für Landing Pages
   - pattern: Das gewählte inhaltliche Template
   - persona_id: Ziel-Persona (wenn im Kontext gesetzt)
   - metric: Eine konkrete Zahl/Metrik die den Angle stützt
   - mechanism: Der Mechanismus hinter der Aussage
   - proofs: Beweise oder Belege
   - cta: Call-to-Action

5. **Qualität prüfen**: Nach der Produktion den Content via list_content() prüfen.
   Bei Bedarf mit update_content() nachbessern.

## Format-Regeln:
- **LinkedIn Post**:
  - Hook in Zeile 1 (provokative These)
  - 3-5 Absätze mit Mechanismus, Beweis, Implikation
  - 3-5 Hashtags
  - Kein "Jetzt Termin buchen"-CTA (soft CTA)
- **Ad Copy**:
  - Primary Text: Pain → Mechanism → Proof → CTA (max 125 Zeichen Primary Text)
  - Headline: Max 40 Zeichen
- **Newsletter**:
  - Subject Line: Neugierde wecken
  - Preview Text: Ergänzung zur Subject Line
  - Body: 3-4 Absätze, ein Call-to-Action

## Brand Voice:
- Kommt dynamisch aus get_strategy() (brand_voice.rules + Persona-Stil:
  Perspektive, Emoji-Nutzung, Max. Satzlänge, verbotene Wörter).
- Halte dich strikt an diese Regeln — keine eigenen Stil-Annahmen.

## Wichtig:
- Jeder Post braucht einen konkreten Mechanismus, nicht nur eine Behauptung
- Metriken und Beweise müssen spezifisch sein, nicht generisch
- Der CTA muss zum Format und zur Funnel-Stufe passen
PROMPT;

        $context = $this->contextService->build($strategy);
        return $base . "\n\n" . $context;
    }

    public function run(string $userMessage, string $strategy = 'viscale'): array
    {
        $registry = new ToolRegistry();
        $toolNames = [
            'get_strategy', 'list_angles', 'get_batch_ranking',
            'produce_content', 'list_content', 'update_content',
        ];
        $this->tools->register($registry, $toolNames, $strategy);

        return $this->orchestrator->run(
            agent: 'production',
            systemPrompt: $this->systemPrompt($strategy),
            messages: [['role' => 'user', 'content' => $userMessage]],
            tools: $this->tools->definitionsFor($toolNames),
            registry: $registry,
        );
    }
}
