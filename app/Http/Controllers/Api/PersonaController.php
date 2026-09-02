<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Persona::with(['strategies', 'strategy']);

        if ($request->filled('strategy')) {
            $query->whereHas('strategies', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'strategy' => 'nullable|string|exists:strategies,key',
            'name' => 'required|string|max:255',
            'role' => 'nullable|string|max:255',
            'voice' => 'nullable|string',
            'core_statements' => 'nullable|array',
            'tonality' => 'nullable|array',
            'positioning' => 'nullable|string|max:255',
            'content_attributes' => 'nullable|array',
            'cadence' => 'nullable|string|max:255',
            'channel_strategies' => 'nullable|array',
            'forbidden_words' => 'nullable|array',
            'forbidden_words.*' => 'string|max:100',
            'max_sentence_length' => 'nullable|integer|min:5|max:50',
            'emoji_usage' => 'nullable|in:none,light,heavy',
            'perspective' => 'nullable|in:ich,wir,neutral',
            'active' => 'boolean',
        ]);

        $persona = Persona::create($validated);

        // Optional: Persona direkt einer Strategie zuordnen (als Default-Mapping).
        // Themen + Angles werden separat über das Mapping gepflegt.
        if (!empty($validated['strategy'])) {
            $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();
            $persona->strategies()->syncWithoutDetaching([$strategy->id => [
                'is_default' => true,
            ]]);
        }

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json($persona->load(['strategies', 'strategy']), 201);
    }

    public function update(Request $request, Persona $persona): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'sometimes|string|exists:strategies,key',
            'name' => 'sometimes|string|max:255',
            'role' => 'nullable|string|max:255',
            'voice' => 'nullable|string',
            'core_statements' => 'nullable|array',
            'tonality' => 'nullable|array',
            'positioning' => 'nullable|string|max:255',
            'content_attributes' => 'nullable|array',
            'cadence' => 'nullable|string|max:255',
            'channel_strategies' => 'nullable|array',
            'forbidden_words' => 'nullable|array',
            'forbidden_words.*' => 'string|max:100',
            'max_sentence_length' => 'nullable|integer|min:5|max:50',
            'emoji_usage' => 'nullable|in:none,light,heavy',
            'perspective' => 'nullable|in:ich,wir,neutral',
            'active' => 'boolean',
        ]);

        $strategyKey = $validated['strategy'] ?? null;
        unset($validated['strategy']);

        $persona->update($validated);

        // Mapping aktualisieren, falls eine Strategie angegeben wurde
        if ($strategyKey) {
            $strategy = Strategy::where('key', $strategyKey)->firstOrFail();
            $persona->strategies()->syncWithoutDetaching([$strategy->id => [
                'is_default' => true,
            ]]);
        }

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json($persona->load(['strategies', 'strategy']));
    }

    public function destroy(Request $request, Persona $persona)
    {
        $persona->delete();

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json(['deleted' => true, 'message' => "Persona {$persona->name} gelöscht."]);
    }
}