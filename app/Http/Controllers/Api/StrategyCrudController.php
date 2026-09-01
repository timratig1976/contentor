<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class StrategyCrudController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Strategy::withCount('personas')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|unique:strategies,key|max:50',
            'name' => 'required|string|max:255',
            'config' => 'nullable|array',
        ]);

        $strategy = Strategy::create($validated);

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json($strategy, 201);
    }

    public function update(Request $request, Strategy $strategy): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'sometimes|string|unique:strategies,key,' . $strategy->id . '|max:50',
            'name' => 'sometimes|string|max:255',
            'config' => 'nullable|array',
        ]);

        $strategy->update($validated);

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json($strategy);
    }

    public function destroy(Request $request, Strategy $strategy): JsonResponse|RedirectResponse
    {
        // Nächstverbleibende Strategie für den Frontend-Umzug, wenn die
        // gerade aktivierte gelöscht wird.
        $nextStrategyKey = Strategy::where('id', '!=', $strategy->id)
            ->orderBy('id')
            ->value('key');

        try {
            $counts = $strategy->deleteCascade();
        } catch (Throwable $e) {
            // Fail-safe: nie einen Teilzustand stehen lassen (Transaktion
            // rollt automatisch zurück) – sauberen Fehler an den Client.
            Log::error("Strategie {$strategy->id} konnte nicht gelöscht werden: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'deleted' => false,
                'message' => 'Die Strategie konnte nicht gelöscht werden. Bitte versuche es erneut.',
            ], 500);
        }

        $totalDeleted = array_sum($counts);

        $payload = [
            'deleted' => true,
            'message' => "Strategie {$strategy->name} mit {$totalDeleted} zugehörigen Datensätzen gelöscht.",
            'counts' => $counts,
            'next_strategy_key' => $nextStrategyKey,
        ];

        if ($request->header('X-Inertia')) {
            return $nextStrategyKey
                ? redirect()->route('strategie', ['strategy' => $nextStrategyKey])
                : redirect()->route('strategie');
        }

        return response()->json($payload);
    }
}