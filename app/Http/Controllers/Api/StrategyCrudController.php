<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function destroy(Request $request, Strategy $strategy)
    {
        $strategy->delete();

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json(['deleted' => true, 'message' => "Strategie {$strategy->name} gelöscht."]);
    }
}