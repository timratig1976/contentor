<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Source::with('unit');

        if ($request->filled('unit')) {
            $query->whereHas('unit', fn ($q) => $q->where('key', $request->input('unit')));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('batch_key')) {
            $query->where('batch_key', $request->input('batch_key'));
        }

        $sources = $query->latest()->paginate($request->input('per_page', 50));

        return response()->json($sources);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:pdf,url,interview,intern,research',
            'unit' => 'required|string|exists:units,key',
            'visibility' => 'string|in:intern,extern,partner',
            'file_ref' => 'nullable|string',
            'batch_key' => 'nullable|string',
        ]);

        $unit = Unit::where('key', $validated['unit'])->firstOrFail();

        $source = Source::create([
            'unit_id' => $unit->id,
            'title' => $validated['title'],
            'type' => $validated['type'],
            'visibility' => $validated['visibility'] ?? 'intern',
            'file_ref' => $validated['file_ref'] ?? null,
            'batch_key' => $validated['batch_key'] ?? null,
        ]);

        return response()->json($source, 201);
    }

    public function angles(Source $source): JsonResponse
    {
        $angles = $source->angles()->with('unit')->latest()->get();

        return response()->json([
            'source' => $source,
            'angles' => $angles,
            'total' => $angles->count(),
        ]);
    }
}
