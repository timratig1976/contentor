<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentLog;
use App\Models\Setting;
use App\Services\AgentContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentConfigController extends Controller
{
    /**
     * Agent-System-Prompts & Konfiguration aus der Datenbank lesen/speichern.
     */
    public function index(): JsonResponse
    {
        $config = Setting::where('key', 'agent_prompts')->first()?->value ?? [];
        $models = Setting::where('key', 'agent_models')->first()?->value ?? [];
        return response()->json(['prompts' => $config, 'models' => $models]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'agent' => 'required|string|in:research,angle,production,review,coordinator',
            'system_prompt' => 'required|string',
        ]);

        $prompts = Setting::where('key', 'agent_prompts')->first()?->value ?? [];
        $prompts[$validated['agent']] = $validated['system_prompt'];

        Setting::updateOrCreate(['key' => 'agent_prompts'], ['value' => $prompts]);

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json(['saved' => true, 'agent' => $validated['agent']]);
    }

    /**
     * Einzelnen Agent direkt testen (ohne den ganzen Coordinator-Workflow).
     */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent' => 'required|string|in:research,angle,production,review',
            'message' => 'required|string',
            'strategy' => 'nullable|string',
            'persona_id' => 'nullable|integer',
        ]);

        // Lies EdenAI Key aus Settings
        $edenaiKey = Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
        if (!$edenaiKey) {
            return response()->json(['error' => 'EdenAI Key nicht konfiguriert. Bitte in Einstellungen eintragen.'], 422);
        }

        // Agent-spezifische Config
        $models = Setting::where('key', 'agent_models')->first()?->value ?? [];
        $agentModel = $models[$validated['agent']] ?? ['provider' => 'openai', 'model' => 'gpt-4o'];

        $provider = $agentModel['provider'];
        $model = $this->withProviderPrefix($agentModel['model'], $provider);

        $prompts = Setting::where('key', 'agent_prompts')->first()?->value ?? [];
        $basePrompt = $prompts[$validated['agent']] ?? "Du bist ein hilfreicher Agent.";

        // Strategie-Kontext (ICPs, Cluster, Tonalität, Personas) in den
        // System-Prompt einbetten — Platzhalter oder automatisch angehängt.
        $strategyKey = $validated['strategy'] ?? (\App\Models\Strategy::value('key') ?: 'viscale');
        $personaId = $validated['persona_id'] ?? null;
        $contextBlock = app(AgentContextService::class)->build($strategyKey, $personaId);
        $systemPrompt = str_contains($basePrompt, '{{strategy_context}}')
            ? str_replace('{{strategy_context}}', $contextBlock, $basePrompt)
            : $basePrompt . "\n\n" . $contextBlock;

        // EdenAI v3 Chat API (OpenAI-kompatibel) — identisch zum Python-Agent-Pfad.
        // Der alte v2/text/chat-Endpoint übersetzt Modell-IDs intern (z. B.
        // claude-sonnet-4-6 → claude-3-5-sonnet-latest) und schlägt dadurch fehl.
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . $edenaiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.edenai.run/v3/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $validated['message']],
            ],
            'temperature' => 0.7,
            'max_tokens' => 2000,
        ]);

        $data = $response->json() ?? [];

        // HTTP-Fehler von EdenAI (z. B. 400/401/404) direkt melden
        if ($response->failed()) {
            $message = $data['error']['message'] ?? $data['error'] ?? $data['message'] ?? $response->body();
            $error = "EdenAI Fehler (HTTP {$response->status()}): " . (is_string($message) ? $message : json_encode($message));

            AgentLog::create([
                'agent' => $validated['agent'],
                'provider' => $provider,
                'model' => $model,
                'input' => $validated['message'],
                'output' => $error,
                'status' => 'error',
            ]);

            return response()->json([
                'agent' => $validated['agent'],
                'provider' => $provider,
                'model' => $model,
                'input' => $validated['message'],
                'error' => $error,
            ], $response->status());
        }

        $generatedText = $data['choices'][0]['message']['content'] ?? null;

        // EdenAI liefert bei Provider-Fehlern keinen Content
        if (!$generatedText) {
            $reason = $data['error']['message'] ?? $data['error'] ?? $data['message'] ?? null;
            $error = 'EdenAI lieferte keine Antwort: '
                . ($reason ? (is_string($reason) ? $reason : json_encode($reason)) : json_encode($data));

            AgentLog::create([
                'agent' => $validated['agent'],
                'provider' => $provider,
                'model' => $model,
                'input' => $validated['message'],
                'output' => $error,
                'status' => 'error',
            ]);

            return response()->json([
                'agent' => $validated['agent'],
                'provider' => $provider,
                'model' => $model,
                'input' => $validated['message'],
                'error' => $error,
            ], 502);
        }

        // Log the test call
        AgentLog::create([
            'agent' => $validated['agent'],
            'provider' => $provider,
            'model' => $model,
            'input' => $validated['message'],
            'output' => $generatedText,
            'status' => 'success',
        ]);

        return response()->json([
            'agent' => $validated['agent'],
            'provider' => $provider,
            'model' => $model,
            'input' => $validated['message'],
            'output' => $generatedText,
        ]);
    }

    /**
     * Strategie-Kontext für einen Agent liefern (wird vom Python-Workflow
     * beim Start abgerufen, damit System-Prompt und Kontext getrennt bleiben).
     */
    public function context(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'required|string',
            'agent' => 'nullable|string|in:research,angle,production,review,coordinator',
            'persona_id' => 'nullable|integer',
        ]);

        $context = app(AgentContextService::class)->build(
            $validated['strategy'],
            $validated['persona_id'] ?? null,
        );

        $prompts = Setting::where('key', 'agent_prompts')->first()?->value ?? [];
        $agent = $validated['agent'] ?? null;

        return response()->json([
            'strategy' => $validated['strategy'],
            'agent' => $agent,
            'persona_id' => $validated['persona_id'] ?? null,
            'context' => $context,
            'system_prompt' => $agent ? ($prompts[$agent] ?? null) : null,
        ]);
    }

    /**
     * Modell-ID für EdenAI v3 aufbereiten: Der v3-Chat-Endpoint erwartet die
     * vollständige ID im Format "provider/modell" (z. B. "anthropic/claude-sonnet-4-6").
     * Fehlt der Präfix, wird er ergänzt; ist er schon da, bleibt er unverändert.
     */
    private function withProviderPrefix(string $model, string $provider): string
    {
        if (str_starts_with($model, $provider . '/')) {
            return $model;
        }
        return $provider . '/' . $model;
    }
}