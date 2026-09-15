<?php

namespace App\Services\Agents;

use App\Models\Setting;
use App\Services\AgentContextService;

/**
 * Research Agent — researches topics, saves sources, extracts first angles.
 * Ports content-agent/agents/research_agent.py.
 */
class ResearchAgent
{
    public function __construct(
        private AgentOrchestrator $orchestrator,
        private AgentTools $tools,
        private AgentContextService $contextService,
    ) {}

    private function systemPrompt(string $strategy): string
    {
        $custom = Setting::where('key', 'agent_prompts')->first()?->value['research'] ?? null;
        $base = $custom ?? <<<PROMPT
Du bist ein Research Agent für Content-Marketing. Deine Aufgabe ist es,
zu einem gegebenen Thema Quellen zu recherchieren und erste Angles zu extrahieren.

## Schritt 0 — IMMER ZUERST:
Rufe get_strategy_context auf, BEVOR du recherchierst. Der Kontext enthält
ICPs, Pain-Cluster, Tonalität und die Ziel-Persona(en). Nennt der Nutzer eine
Persona, übergib ihren Namen als `persona`-Parameter — alle Angles zielen dann
nur auf diese Persona. Ohne Persona-Angabe wählst du pro Angle die passendste
Persona aus dem Kontext (NIEMALS für alle Personas gleichzeitig generieren).

## Vorgehen:
1. **Recherchieren**: Nutze web_search, um das Thema zu recherchieren.
   Suche nach:
   - Aktuellen Artikeln und Studien
   - Branchen-Trends und Statistiken
   - Pain Points und Problemen der Zielgruppe
   - Wettbewerbs-Inhalten

2. **Quellen speichern**: Für jede gefundene Quelle rufe create_source auf:
   - title: Aussagekräftiger Titel
   - type: "url" für Webseiten
   - batch_key: Ein thematischer Batch-Key (z. B. "crm-trends-2026")
   - visibility: "intern"
   - url: Die URL der Quelle

3. **Angles extrahieren**: Aus jeder Quelle 1-3 Angles ableiten und per create_angle speichern:
   - Der angle-Text soll eine prägnante Kernaussage sein
   - batch_key muss mit dem der Quelle übereinstimmen
   - source_id: Die ID der zugehörigen Quelle
   - ICP und Pain-Cluster werden automatisch erkannt

4. **Ranking abrufen**: Am Ende get_batch_ranking aufrufen,
   um das Ranking aller extrahierten Angles zu sehen.

## Wichtig:
- Fokussiere auf B2B-SaaS-Themen rund um CRM, Vertrieb und Prozesse
- Angles sollen provokativ und meinungsstark sein (nicht neutral)
- Maximal 3 Quellen pro Recherche
- Für vielversprechende Treffer rufe scrape_page mit der URL auf,
  um den vollständigen Artikel zu lesen und bessere Angles zu extrahieren
- Gib am Ende eine Zusammenfassung der gefundenen Angles und ihres Rankings
PROMPT;

        $context = $this->contextService->build($strategy);
        return $base . "\n\n" . $context;
    }

    public function run(string $userMessage, string $strategy = 'viscale', ?string $persona = null): array
    {
        $registry = new ToolRegistry();
        $toolNames = [
            'web_search', 'scrape_page', 'get_strategy_context',
            'create_source', 'list_sources',
            'create_angle', 'get_batch_ranking', 'create_content_idea',
        ];
        $this->tools->register($registry, $toolNames, $strategy);

        $messages = [['role' => 'user', 'content' => $userMessage]];
        if ($persona) {
            $messages[0]['content'] .= " (Ziel-Persona: {$persona})";
        }

        return $this->orchestrator->run(
            agent: 'research',
            systemPrompt: $this->systemPrompt($strategy),
            messages: $messages,
            tools: $this->tools->definitionsFor($toolNames),
            registry: $registry,
        );
    }
}
