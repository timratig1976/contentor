<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\PersonaExample;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Few-Shot-Referenz-Posts für Personas (kuratierte Beispiele pro Format).
 */
class PersonaExampleController extends Controller
{
    /**
     * Alle Beispiele einer Persona (optional nach Format gefiltert).
     */
    public function index(Request $request, Persona $persona): JsonResponse
    {
        $query = PersonaExample::where('persona_id', $persona->id);

        if ($request->filled('format')) {
            $query->where('format', $request->input('format'));
        }

        return response()->json($query->get());
    }

    /**
     * Neues Referenz-Beispiel anlegen.
     */
    public function store(Request $request, Persona $persona): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'required|string|exists:strategies,key',
            'format' => 'required|string|max:50',
            'content' => 'required|string',
            'why_good' => 'nullable|string',
            'active' => 'nullable|boolean',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();

        $example = $persona->examples()->create([
            'strategy_id' => $strategy->id,
            'format' => $validated['format'],
            'content' => $validated['content'],
            'why_good' => $validated['why_good'] ?? null,
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json($example, 201);
    }

    /**
     * Beispiel aktualisieren.
     */
    public function update(Request $request, Persona $persona, PersonaExample $example): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'sometimes|string|exists:strategies,key',
            'format' => 'sometimes|string|max:50',
            'content' => 'sometimes|string',
            'why_good' => 'nullable|string',
            'active' => 'sometimes|boolean',
        ]);

        if (isset($validated['strategy'])) {
            $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();
            $validated['strategy_id'] = $strategy->id;
            unset($validated['strategy']);
        }

        $example->update($validated);

        return response()->json($example);
    }

    /**
     * Beispiel löschen.
     */
    public function destroy(Persona $persona, PersonaExample $example): JsonResponse
    {
        $example->delete();

        return response()->json(['deleted' => true]);
    }
}