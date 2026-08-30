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
            'type' => 'required|string|in:strategy,persona,setting,angle,content',
            'data' => 'required|array',
        ]);

        $result = match ($validated['type']) {
            'strategy' => $this->writeStrategy($validated['data']),
            'persona' => $this->writePersona($validated['data']),
            'setting' => $this->writeSetting($validated['data']),
            'angle' => $this->writeAngle($validated['data']),
            'content' => $this->writeContent($validated['data']),
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

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Du bist ein Content-Strategie-Assistant für das Contentor-System. Du hilfst Nutzern dabei, Content-Strategien zu erstellen, zu verfeinern und zu optimieren.

## Deine Fähigkeiten:
1. **Strategie erstellen**: Du kannst komplette Content-Strategien mit Brand Voice, Channel Rules, ICP-Mapping, Content-Pillars, Post-Templates und Personas generieren.
2. **Personas erstellen**: Du kannst Content-Personas mit Kernaussagen, Tonalität, Themen, Angles und Channel-Strategien erstellen.
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

## JSON-Format für Strategie:
{
  "key": "slug",
  "name": "Name",
  "brand_voice": { "personality": "...", "tone": "direkt", "never": [], "must": [] },
  "channel_rules": { "channels": [{ "channel": "linkedin", "frequency": "weekly", "rules": [] }] },
  "icp_channel_mapping": { "mappings": [{ "icp": "B2B-1", "channels": ["linkedin"], "priority": "high" }] },
  "content_strategy": { "goals": "...", "pillars": [{ "name": "...", "description": "..." }] },
  "post_templates": { "templates": [{ "format": "linkedin_post", "structure": "Hook\nMechanismus\nProof\nCTA" }] },
  "content_personas": { "personas": [{ "name": "...", "role": "...", "tonality": { "style": "direkt" }, "topics": [] }] }
}
PROMPT;
    }
}