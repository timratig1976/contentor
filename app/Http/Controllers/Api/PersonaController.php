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
        $query = Persona::with('strategy');

        if ($request->filled('strategy')) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'strategy' => 'required|string|exists:strategies,key',
            'name' => 'required|string|max:255',
            'role' => 'nullable|string|max:255',
            'voice' => 'nullable|string',
            'core_statements' => 'nullable|array',
            'tonality' => 'nullable|array',
            'positioning' => 'nullable|string|max:255',
            'topics' => 'nullable|array',
            'angles' => 'nullable|array',
            'content_attributes' => 'nullable|array',
            'cadence' => 'nullable|string|max:255',
            'channel_strategies' => 'nullable|array',
            'active' => 'boolean',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();
        $validated['strategy_id'] = $strategy->id;
        unset($validated['strategy']);

        $persona = Persona::create($validated);

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json($persona->load('strategy'), 201);
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
            'topics' => 'nullable|array',
            'angles' => 'nullable|array',
            'content_attributes' => 'nullable|array',
            'cadence' => 'nullable|string|max:255',
            'channel_strategies' => 'nullable|array',
            'active' => 'boolean',
        ]);

        if (isset($validated['strategy'])) {
            $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();
            $validated['strategy_id'] = $strategy->id;
            unset($validated['strategy']);
        }

        $persona->update($validated);

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json($persona->load('strategy'));
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