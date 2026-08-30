<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class EdenAIModelsController extends Controller
{
    private const CURATED_MODELS = [
        'openai' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo'],
        'anthropic' => ['claude-3-5-sonnet-20240620', 'claude-3-haiku-20240307'],
        'google' => ['gemini-2.0-flash', 'gemini-1.5-pro'],
        'mistral' => ['mistral-large', 'mistral-medium'],
        'meta' => ['llama-3.1-70b'],
        'cohere' => ['command-r-plus'],
        'deepseek' => ['deepseek-chat', 'deepseek-reasoner'],
    ];

    public function index(): JsonResponse
    {
        $custom = Setting::where('key', 'agent_models_curated')->first()?->value;
        if ($custom) {
            return response()->json(['providers' => array_keys($custom), 'models' => $custom, 'source' => 'custom']);
        }
        return response()->json(['providers' => array_keys(self::CURATED_MODELS), 'models' => self::CURATED_MODELS, 'source' => 'curated']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['models' => 'required|array']);
        Setting::updateOrCreate(['key' => 'agent_models_curated'], ['value' => $validated['models']]);
        return response()->json(['saved' => true]);
    }

    public function all(): JsonResponse
    {
        $edenaiKey = Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
        if (!$edenaiKey) {
            return response()->json(['error' => 'EdenAI Key nicht konfiguriert'], 422);
        }

        try {
            // EdenAI v3 Models endpoint (public, no auth required for listing)
            $response = Http::timeout(60)->get('https://api.edenai.run/v3/models');

            if ($response->failed()) {
                return response()->json(['error' => 'EdenAI nicht erreichbar'], $response->status());
            }

            $data = $response->json();
            $providers = [];

            foreach ($data['data'] ?? [] as $model) {
                $provider = $model['owned_by'] ?? 'unknown';
                if (!isset($providers[$provider])) {
                    $providers[$provider] = [];
                }
                $providers[$provider][] = [
                    'id' => $model['id'] ?? '',
                    'name' => $model['model_name'] ?? $model['id'] ?? '',
                    'context_length' => $model['context_length'] ?? null,
                    'description' => $model['description'] ?? null,
                ];
            }

            // Sort providers by model count
            uasort($providers, fn ($a, $b) => count($b) - count($a));

            return response()->json([
                'providers' => array_keys($providers),
                'models' => $providers,
                'source' => 'edenai_v3',
                'total_models' => array_sum(array_map('count', $providers)),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function extractModelsForProvider(string $provider, array $data): array
    {
        $fallbacks = [
            'openai' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo'],
            'google' => ['gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-1.5-flash'],
            'anthropic' => ['claude-3-5-sonnet-20240620', 'claude-3-opus-20240229', 'claude-3-haiku-20240307'],
            'mistral' => ['mistral-large', 'mistral-medium', 'mistral-small'],
            'meta' => ['llama-3.1-405b', 'llama-3.1-70b'],
            'cohere' => ['command-r-plus', 'command-r'],
            'deepseek' => ['deepseek-chat', 'deepseek-reasoner'],
            'amazon' => ['titan-text-premier', 'titan-text-lite'],
        ];

        if (isset($data['providers'])) {
            foreach ($data['providers'] as $p) {
                if (($p['name'] ?? '') === $provider && !empty($p['models'])) {
                    return array_map(fn ($m) => $m['name'] ?? $m, $p['models']);
                }
            }
        }

        return $fallbacks[$provider] ?? [];
    }
}