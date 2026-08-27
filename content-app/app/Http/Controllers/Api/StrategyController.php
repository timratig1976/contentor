<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentStrategy;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StrategyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit' => 'required|string|exists:units,key',
            'key' => 'required|string|in:' . implode(',', ContentStrategy::KEYS),
            'content' => 'required',
        ]);

        $unit = Unit::where('key', $validated['unit'])->firstOrFail();

        $strategy = ContentStrategy::updateOrCreate(
            ['unit_id' => $unit->id, 'key' => $validated['key']],
            [
                'content' => $validated['content'],
                'version' => ContentStrategy::where('unit_id', $unit->id)->where('key', $validated['key'])->value('version') + 1 ?? 1,
            ]
        );

        return response()->json([
            'message' => ContentStrategy::LABELS[$validated['key']] . " für {$unit->name} gespeichert.",
            'strategy' => $strategy,
        ], 201);
    }

    public function show(string $unitKey, string $key): JsonResponse
    {
        $unit = Unit::where('key', $unitKey)->firstOrFail();

        $strategy = ContentStrategy::where('unit_id', $unit->id)->where('key', $key)->first();

        if (!$strategy) {
            return response()->json([
                'found' => false,
                'message' => "Keine " . (ContentStrategy::LABELS[$key] ?? $key) . " für {$unit->name} hinterlegt.",
            ], 404);
        }

        return response()->json([
            'found' => true,
            'unit' => $unitKey,
            'key' => $key,
            'label' => ContentStrategy::LABELS[$key] ?? $key,
            'content' => $strategy->content,
            'version' => $strategy->version,
            'updated_at' => $strategy->updated_at,
        ]);
    }

    public function index(string $unitKey): JsonResponse
    {
        $unit = Unit::where('key', $unitKey)->firstOrFail();

        $strategies = ContentStrategy::where('unit_id', $unit->id)->get()->map(fn ($s) => [
            'key' => $s->key,
            'label' => ContentStrategy::LABELS[$s->key] ?? $s->key,
            'version' => $s->version,
            'updated_at' => $s->updated_at,
        ]);

        return response()->json([
            'unit' => $unitKey,
            'strategies' => $strategies,
            'total' => $strategies->count(),
        ]);
    }

    public function destroy(string $unitKey, string $key): JsonResponse
    {
        $unit = Unit::where('key', $unitKey)->firstOrFail();

        $deleted = ContentStrategy::where('unit_id', $unit->id)->where('key', $key)->delete();

        return response()->json([
            'deleted' => $deleted > 0,
            'message' => $deleted
                ? (ContentStrategy::LABELS[$key] ?? $key) . " für {$unit->name} gelöscht."
                : "Nicht gefunden.",
        ]);
    }
}
