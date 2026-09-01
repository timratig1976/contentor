<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ordnet globale Personas Strategien zu (und entfernt sie wieder).
 * Pro Zuordnung können Angles + Themen-Cluster gepflegt werden.
 */
class StrategyPersonaController extends Controller
{
    /**
     * Alle Personas, die der Strategie zugeordnet sind (inkl. Pivot-Daten).
     */
    public function index(Strategy $strategy): JsonResponse
    {
        return response()->json($strategy->personas()->withPivot(['mapped_angles', 'mapped_topics', 'is_default'])->get());
    }

    /**
     * Persona zur Strategie mappen (oder bestehendes Mapping aktualisieren).
     */
    public function attach(Request $request, Strategy $strategy): JsonResponse
    {
        $validated = $request->validate([
            'persona_id' => 'required|exists:personas,id',
            'angles' => 'nullable|array',
            'topic_clusters' => 'nullable|array',
            'is_default' => 'nullable|boolean',
        ]);

        $persona = Persona::findOrFail($validated['persona_id']);

        $pivot = [
            'mapped_angles' => $validated['angles'] ?? null,
            'mapped_topics' => $validated['topic_clusters'] ?? null,
            'is_default' => $validated['is_default'] ?? false,
        ];

        $persona->strategies()->syncWithoutDetaching([$strategy->id => $pivot]);

        return response()->json($persona->strategies()->withPivot(['mapped_angles', 'mapped_topics', 'is_default'])->get(), 200);
    }

    /**
     * Persona-Mapping zur Strategie entfernen (Persona bleibt global erhalten).
     */
    public function detach(Strategy $strategy, Persona $persona): JsonResponse
    {
        $persona->strategies()->detach($strategy->id);

        return response()->json(['detached' => true, 'persona_id' => $persona->id, 'strategy_id' => $strategy->id]);
    }
}
