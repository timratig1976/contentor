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
     * Nutzt die konfigurierten Agent-Einstellungen (Modell, Temperature,
     * Max Tokens, Reasoning-Effort) über den zentralen LlmService.
     */
    public function test(Request $request, \App\Services\LlmService $llm): JsonResponse
    {
        $validated = $request->validate([
            'agent' => 'required|string|in:research,angle,production,review',
            'message' => 'required|string',
            'strategy' => 'nullable|string',
            'persona_id' => 'nullable|integer',
        ]);

        $agent = $validated['agent'];
        $cfg = $llm->configFor($agent);

        if (!Setting::where('key', 'llm_keys')->first()?->value['edenai_key']) {
            return response()->json(['error' => 'EdenAI Key nicht konfiguriert. Bitte in Einstellungen eintragen.'], 422);
        }

        $prompts = Setting::where('key', 'agent_prompts')->first()?->value ?? [];
        $basePrompt = $prompts[$agent] ?? "Du bist ein hilfreicher Agent.";

        // Strategie-Kontext (ICPs, Cluster, Tonalität, Personas) in den
        // System-Prompt einbetten — Platzhalter oder automatisch angehängt.
        $strategyKey = $validated['strategy'] ?? (\App\Models\Strategy::value('key') ?: 'viscale');
        $personaId = $validated['persona_id'] ?? null;
        $contextBlock = app(AgentContextService::class)->build($strategyKey, $personaId);
        $systemPrompt = str_contains($basePrompt, '{{strategy_context}}')
            ? str_replace('{{strategy_context}}', $contextBlock, $basePrompt)
            : $basePrompt . "\n\n" . $contextBlock;

        // EdenAI v3 Chat API (OpenAI-kompatibel) über den zentralen Service —
        // identische Parameter wie der Production-Pfad, inkl. Reasoning-Effort.
        $result = $llm->chat($agent, $systemPrompt, [
            ['role' => 'user', 'content' => $validated['message']],
        ], ['timeout' => 120]);

        $model = $llm->withProviderPrefix($cfg['model'], $cfg['provider']);

        if ($result['status'] !== 'success') {
            return response()->json([
                'agent' => $agent,
                'provider' => $cfg['provider'],
                'model' => $model,
                'input' => $validated['message'],
                'error' => 'EdenAI Fehler: ' . ($result['error'] ?? 'unbekannt'),
            ], 502);
        }

        return response()->json([
            'agent' => $agent,
            'provider' => $cfg['provider'],
            'model' => $model,
            'input' => $validated['message'],
            'output' => $result['text'],
            'usage' => $result['usage'],
            'cost' => $result['cost'],
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