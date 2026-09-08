<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AssistantController extends Controller
{
    /**
     * AI-Assistant: Chat-Endpoint mit Tool-Calling für Strategie-Erstellung.
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'history' => 'nullable|array',
        ]);

        $edenaiKey = Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
        if (!$edenaiKey) {
            return response()->json(['error' => 'EdenAI Key nicht konfiguriert'], 422);
        }

        $systemPrompt = $this->buildSystemPrompt();

        $messages = $validated['history'] ?? [];
        $messages[] = ['role' => 'user', 'message' => $validated['message']];

        // Build EdenAI chat history
        $history = [];
        foreach (array_slice($messages, -10) as $msg) {
            $history[] = ['role' => $msg['role'] === 'user' ? 'user' : 'assistant', 'message' => $msg['message']];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $edenaiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.edenai.run/v2/text/chat', [
            'providers' => 'openai',
            'model' => 'gpt-4o',
            'text' => $validated['message'],
            'chatbot_global_action' => $systemPrompt,
            'previous_history' => array_slice($history, 0, -1),
            'temperature' => 0.7,
            'max_tokens' => 2000,
        ]);

        $data = $response->json();
        $reply = $data['openai']['generated_text'] ?? 'Keine Antwort erhalten.';

        return response()->json([
            'reply' => $reply,
            'history' => $messages,
        ]);
    }

    /**
     * Assistant kann direkt in die DB schreiben (Strategie, Personas, Settings).
     */
    public function write(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:strategy,persona,setting,angle,content,icp_definitions,strategy_content',
            'data' => 'required|array',
        ]);

        $result = match ($validated['type']) {
            'strategy' => $this->writeStrategy($validated['data']),
            'persona' => $this->writePersona($validated['data']),
            'setting' => $this->writeSetting($validated['data']),
            'angle' => $this->writeAngle($validated['data']),
            'content' => $this->writeContent($validated['data']),
            'icp_definitions' => $this->writeIcpDefinitions($validated['data']),
            'strategy_content' => $this->writeStrategyContent($validated['data']),
        };

        return response()->json(['saved' => true, 'result' => $result]);
    }

    private function writeStrategy(array $data): array
    {
        $strategy = \App\Models\Strategy::updateOrCreate(
            ['key' => $data['key']],
            ['name' => $data['name'], 'config' => $data['config'] ?? []]
        );

        // Save content strategies if provided
        if (!empty($data['content_strategies'])) {
            foreach ($data['content_strategies'] as $cs) {
                \App\Models\ContentStrategy::updateOrCreate(
                    ['strategy_id' => $strategy->id, 'key' => $cs['key']],
                    ['content' => $cs['content'], 'version' => 1]
                );
            }
        }

        return $strategy->toArray();
    }

    private function writePersona(array $data): array
    {
        $strategy = \App\Models\Strategy::where('key', $data['strategy'])->firstOrFail();
        $persona = \App\Models\Persona::create([
            'strategy_id' => $strategy->id,
            'name' => $data['name'],
            'role' => $data['role'] ?? null,
            'voice' => $data['voice'] ?? null,
            'positioning' => $data['positioning'] ?? null,
            'core_statements' => $data['core_statements'] ?? [],
            'tonality' => $data['tonality'] ?? ['style' => 'direkt'],
            'topics' => $data['topics'] ?? [],
            'angles' => $data['angles'] ?? [],
            'content_attributes' => $data['content_attributes'] ?? [],
            'cadence' => $data['cadence'] ?? 'weekly',
            'channel_strategies' => $data['channel_strategies'] ?? [],
            'active' => $data['active'] ?? true,
        ]);

        return $persona->toArray();
    }

    private function writeSetting(array $data): array
    {
        $setting = Setting::updateOrCreate(
            ['key' => $data['key']],
            ['value' => $data['value']]
        );
        return $setting->toArray();
    }

    private function writeAngle(array $data): array
    {
        $strategy = \App\Models\Strategy::where('key', $data['strategy'])->firstOrFail();
        $angle = \App\Models\Angle::create([
            'strategy_id' => $strategy->id,
            'angle' => $data['angle'],
            'icp' => $data['icp'] ?? null,
            'pain_cluster' => $data['pain_cluster'] ?? null,
            'statement_type' => $data['statement_type'] ?? null,
            'batch_key' => $data['batch_key'] ?? null,
        ]);
        return $angle->toArray();
    }

    private function writeContent(array $data): array
    {
        $strategy = \App\Models\Strategy::where('key', $data['strategy'])->firstOrFail();
        $content = \App\Models\ContentItem::create([
            'strategy_id' => $strategy->id,
            'type' => 'post',
            'format' => $data['format'] ?? 'linkedin_post',
            'title' => $data['title'] ?? null,
            'content' => $data['content'],
            'status' => $data['status'] ?? 'idee',
        ]);
        return $content->toArray();
    }

    private function writeIcpDefinitions(array $data): array
    {
        $strategy = \App\Models\Strategy::where('key', $data['strategy'])->firstOrFail();

        $newIcps = $data['icps'] ?? [];
        $defaultIcp = $data['default_icp'] ?? null;

        // Bestehende ICPs laden und mit neuen mergen (Key-basiert)
        $existing = \App\Models\ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'icp_definitions')
            ->first();

        $existingIcps = $existing && isset($existing->content['icps'])
            ? $existing->content['icps']
            : [];

        $byKey = collect($existingIcps)->keyBy('key')->toArray();
        foreach ($newIcps as $icp) {
            $byKey[$icp['key']] = $icp;
        }
        $mergedIcps = array_values($byKey);

        $mergedDefault = $defaultIcp ?: ($existing->content['default_icp'] ?? 'B2B-1');

        // In ContentStrategy speichern (für UI)
        \App\Models\ContentStrategy::updateOrCreate(
            ['strategy_id' => $strategy->id, 'key' => 'icp_definitions'],
            ['content' => ['icps' => $mergedIcps, 'default_icp' => $mergedDefault], 'version' => 1]
        );

        // In Strategy.config.rules.icpGuesser speichern (für Backend-Regex)
        $config = $strategy->config ?? [];
        $config['rules'] = $config['rules'] ?? [];
        $config['rules']['icpGuesser'] = array_map(fn ($icp) => [
            'icp' => $icp['key'],
            'match' => $icp['match_keywords'] ?? '',
        ], $mergedIcps);
        $config['rules']['defaultIcp'] = $mergedDefault;
        $strategy->config = $config;
        $strategy->save();

        return ['icps' => $mergedIcps, 'default_icp' => $mergedDefault];
    }

    /**
     * Schreibt einen beliebigen ContentStrategy-Block (brand_voice, channel_rules, etc.)
     * in die Datenbank. Merged mit bestehenden Daten, überschreibt nicht.
     */
    private function writeStrategyContent(array $data): array
    {
        $strategy = \App\Models\Strategy::where('key', $data['strategy'])->firstOrFail();
        $key = $data['key'] ?? '';
        $content = $data['content'] ?? [];

        if (!in_array($key, \App\Models\ContentStrategy::KEYS)) {
            throw new \InvalidArgumentException("Ungültiger ContentStrategy-Key: {$key}");
        }

        // Bestehenden Content laden und mergen
        $existing = \App\Models\ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', $key)
            ->first();

        $merged = $existing ? array_merge($existing->content ?? [], $content) : $content;

        $cs = \App\Models\ContentStrategy::updateOrCreate(
            ['strategy_id' => $strategy->id, 'key' => $key],
            ['content' => $merged, 'version' => ($existing?->version ?? 0) + 1]
        );

        return $cs->toArray();
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Du bist ein Content-Strategie-Assistant für das Contentor-System. Du hilfst Nutzern dabei, Content-Strategien zu erstellen, zu verfeinern und zu optimieren.

## Deine Fähigkeiten:
1. **Strategie erstellen**: Du kannst komplette Content-Strategien mit Brand Voice, Channel Rules, ICP-Definitionen, Content-Pillars und Post-Templates generieren.
2. **Personas erstellen**: Du kannst Content-Personas (Name, Rolle, Voice, Tonalität, Themen, Angles, Channel-Strategien) erstellen. Personas sind GLOBAL — sie gehören zu keiner einzelnen Strategie, sondern werden Strategien separat zugeordnet (type "persona" mit "strategy").
3. **Settings schreiben**: Du kannst direkt in die Datenbank schreiben — Strategien, Personas, Angles und Settings.
4. **Fragen beantworten**: Du beantwortest Fragen zu Content-Marketing, B2B-SaaS, LinkedIn-Strategie, etc.

## Workflow für Strategie-Erstellung:
Wenn der Nutzer eine neue Strategie will:
1. Stelle 3-5 Fragen: Worum geht es? Wer ist die Zielgruppe? Welcher Ton? Welche Kanäle?
2. Generiere dann die komplette Strategie als JSON
3. Frage ob sie gespeichert werden soll
4. Schreibe via API in die DB

## Wichtige Regeln:
- Sei konkret und praxisnah
- Generiere echte, verwendbare Strategien (keine Platzhalter)
- Frage immer nach Bestätigung, bevor du in die DB schreibst
- Wenn du etwas nicht weißt, frage nach
- Antworte auf Deutsch

## Auto-Execution:
Wenn der Nutzer etwas speichern/erstellen will, antworte mit einem JSON-Codeblock.
Der Frontend-Assistant erkennt diese Blöcke automatisch und führt sie aus.
Das System unterstützt folgende Typen: "strategy", "persona", "angle", "content", "setting", "icp_definitions", "strategy_content"

- **strategy_content**: Schreibt einen ContentStrategy-Block (brand_voice, channel_rules, post_templates, media_logic, editorial_rhythm, content_strategy, content_personas). Merged mit bestehenden Daten.
  ```json
  { "type": "strategy_content", "data": { "strategy": "viscale", "key": "brand_voice", "content": { "personality": "...", "tone": "direkt", "never": [], "must": [] } } }
  ```

Beispiel für ICP-Erstellung:
```json
{
  "type": "icp_definitions",
  "data": {
    "strategy": "viscale",
    "default_icp": "B2B-1",
    "icps": [
      { "key": "B2B-4", "name": "Startup CTO", "role": "CTO", "description": "...", "pain_points": [], "gains": [], "match_keywords": "cto|startup|scale", "default_funnel": "ToFu", "priority": "medium" }
    ]
  }
}
```

## JSON-Format für Strategie:
{
  "key": "slug",
  "name": "Name",
  "brand_voice": { "personality": "...", "tone": "direkt", "never": [], "must": [] },
  "channel_rules": { "channels": [{ "channel": "linkedin", "frequency": "weekly", "rules": [] }] },
  "content_strategy": { "goals": "...", "pillars": [{ "name": "...", "description": "..." }] },
  "post_templates": { "templates": [{ "format": "linkedin_post", "structure": "Hook\nMechanismus\nProof\nCTA" }] }
}

## JSON-Format für ICP-Definitionen:
{
  "type": "icp_definitions",
  "data": {
    "strategy": "viscale",
    "default_icp": "B2B-1",
    "icps": [
      {
        "key": "B2B-1",
        "name": "CRM-Entscheider Mittelstand",
        "role": "CEO / Head of Sales",
        "description": "2-3 Sätze Beschreibung",
        "pain_points": ["Blindflug im Forecast", "Vertrieb hängt an Einzelpersonen"],
        "gains": ["Planbares Wachstum", "Echte Forecast-Sicherheit"],
        "match_keywords": "forecast|pipeline|sales",
        "default_funnel": "ToFu",
        "priority": "high"
      }
    ]
  }
}

## JSON-Format für Persona (global, separat von der Strategie):
{
  "type": "persona",
  "data": {
    "strategy": "slug",
    "name": "...",
    "role": "...",
    "voice": "...",
    "tonality": { "style": "direkt", "do": [], "dont": [] },
    "topics": [],
    "angles": [],
    "content_attributes": {}
  }
}
PROMPT;
    }
}