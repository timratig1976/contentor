<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentStrategy;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StrategyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'required|string|exists:strategies,key',
            'key' => 'required|string|in:' . implode(',', ContentStrategy::KEYS),
            'content' => 'required',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();

        $strategy = ContentStrategy::updateOrCreate(
            ['strategy_id' => $strategy->id, 'key' => $validated['key']],
            [
                'content' => $validated['content'],
                'version' => ContentStrategy::where('strategy_id', $strategy->id)->where('key', $validated['key'])->value('version') + 1 ?? 1,
            ]
        );

        return response()->json([
            'message' => ContentStrategy::LABELS[$validated['key']] . " für {$strategy->name} gespeichert.",
            'strategy' => $strategy,
        ], 201);
    }

    public function show(string $strategyKey, string $key): JsonResponse
    {
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();

        $strategy = ContentStrategy::where('strategy_id', $strategy->id)->where('key', $key)->first();

        if (!$strategy) {
            return response()->json([
                'found' => false,
                'message' => "Keine " . (ContentStrategy::LABELS[$key] ?? $key) . " für {$strategy->name} hinterlegt.",
            ], 404);
        }

        return response()->json([
            'found' => true,
            'strategy' => $strategyKey,
            'key' => $key,
            'label' => ContentStrategy::LABELS[$key] ?? $key,
            'content' => $strategy->content,
            'version' => $strategy->version,
            'updated_at' => $strategy->updated_at,
        ]);
    }

    public function index(string $strategyKey): JsonResponse
    {
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();

        $strategies = ContentStrategy::where('strategy_id', $strategy->id)->get()->map(fn ($s) => [
            'key' => $s->key,
            'label' => ContentStrategy::LABELS[$s->key] ?? $s->key,
            'version' => $s->version,
            'updated_at' => $s->updated_at,
        ]);

        return response()->json([
            'strategy' => $strategyKey,
            'strategies' => $strategies,
            'total' => $strategies->count(),
        ]);
    }

    public function destroy(string $strategyKey, string $key): JsonResponse
    {
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();

        $deleted = ContentStrategy::where('strategy_id', $strategy->id)->where('key', $key)->delete();

        return response()->json([
            'deleted' => $deleted > 0,
            'message' => $deleted
                ? (ContentStrategy::LABELS[$key] ?? $key) . " für {$strategy->name} gelöscht."
                : "Nicht gefunden.",
        ]);
    }
}
