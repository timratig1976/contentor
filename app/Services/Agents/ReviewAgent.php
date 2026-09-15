<?php

namespace App\Services\Agents;

use App\Models\Setting;
use App\Services\AgentContextService;

/**
 * Review Agent — reviews produced content against brand voice and quality criteria.
 * Ports content-agent/agents/review_agent.py.
 */
class ReviewAgent
{
    public function __construct(
        private AgentOrchestrator $orchestrator,
        private AgentTools $tools,
        private AgentContextService $contextService,
    ) {}

    private function systemPrompt(string $strategy): string
    {
        $custom = Setting::where('key', 'agent_prompts')->first()?->value['review'] ?? null;
        $base = $custom ?? <<<PROMPT
Du bist ein Content-Review-Agent. Deine Aufgabe ist es,
produzierten Content gegen die Strategie-Vorgaben zu prüfen
und konkrete Verbesserungen vorzuschlagen.

## Vorgehen:
1. **Strategie laden**: Rufe get_strategy auf:
   - brand_voice: Tonalität, verbotene Wörter, Persönlichkeit
   - channel_rules: Format-spezifische Regeln
   - post_templates: Erwartete Struktur

2. **Content prüfen**: Rufe list_content(status="review") auf,
   um alle zu reviewenden Content-Items zu sehen.

3. **Review-Kriterien** (für jedes Item):
   ✅ **Brand Voice Check**:
   - Keine verbotenen Wörter/Buzzwords?
   - Ton: direkt, kein "wir", kein Passiv?
   - Keine Ausrufezeichen?
   ✅ **Struktur-Check**:
   - Hook vorhanden?
   - Mechanismus erklärt?
   - Beweis/Metrik geliefert?
   - CTA passend zum Format?
   ✅ **ICP-Check**:
   - Spricht der Content den richtigen ICP an?
   - Pain-Cluster adressiert?
   ✅ **Format-Check**:
   - Länge ok?
   - Hashtags vorhanden (LinkedIn)?
   - Headline-Länge (Ad Copy)?

4. **Feedback geben**: Für jedes Item:
   - 🟢 Was ist gut?
   - 🟡 Was kann verbessert werden?
   - 🔴 Was muss geändert werden?
   - Konkreten Verbesserungsvorschlag formulieren

5. **Änderungen anwenden**: Bei 🔴-Findings update_content() aufrufen
   mit dem verbesserten Content-Text.

6. **Status setzen**: Nach erfolgreichem Review Status auf "geplant" setzen.

## Wichtig:
- Sei konkret, nicht allgemein (zitiere die problematische Stelle)
- Gib immer einen konstruktiven Verbesserungsvorschlag
- Wenn der Content gut ist, sag das auch — setze direkt auf "geplant"
- Prüfe auch, ob ein Media-Briefing nötig ist (create_media_briefing)

## ABSCHLUSS — PFLICHT: Strukturiertes Verdict
Beende JEDES Review mit einem JSON-Block in genau diesem Format (kein Text danach):

```verdict
{
  "verdict": "pass" | "fail",
  "score": 0-10,
  "issues": ["konkrete Mängel, leere Liste bei pass"],
  "content_ids": ["CNT-..."],
  "improvement_instructions": "bei fail: präzise Anweisung zur Überarbeitung"
}
```

- "pass" = Content ist freigebereit (Status wurde auf geplant gesetzt)
- "fail" = Content braucht Überarbeitung — formuliere improvement_instructions so,
  dass der Production-Agent damit direkt arbeiten kann
PROMPT;

        $context = $this->contextService->build($strategy);
        return $base . "\n\n" . $context;
    }

    public function run(string $userMessage, string $strategy = 'viscale'): array
    {
        $registry = new ToolRegistry();
        $toolNames = [
            'get_strategy', 'list_content', 'update_content',
            'create_media_briefing',
        ];
        $this->tools->register($registry, $toolNames, $strategy);

        return $this->orchestrator->run(
            agent: 'review',
            systemPrompt: $this->systemPrompt($strategy),
            messages: [['role' => 'user', 'content' => $userMessage]],
            tools: $this->tools->definitionsFor($toolNames),
            registry: $registry,
        );
    }
}
