<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\RedaktionsplanEntry;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedaktionsplanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $unitKey = $request->input('unit', 'viscale');
        $unit = Unit::where('key', $unitKey)->firstOrFail();

        $query = RedaktionsplanEntry::with('contentItem.angle')
            ->where('unit_id', $unit->id);

        if ($request->filled('from')) {
            $query->where('planned_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('planned_date', '<=', $request->input('to'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $entries = $query->orderBy('planned_date')->get();

        // Group by kanban columns if requested
        if ($request->input('mode') === 'kanban') {
            $items = ContentItem::with(['angle', 'media'])
                ->where('unit_id', $unit->id)
                ->whereNotIn('status', ['verworfen'])
                ->get();

            $kanban = [];
            foreach (RedaktionsplanEntry::KANBAN_COLUMNS as $col) {
                $kanban[$col] = $items->where('status', $col)->values();
            }

            return response()->json([
                'unit' => $unit->only(['key', 'name']),
                'kanban' => $kanban,
            ]);
        }

        return response()->json([
            'unit' => $unit->only(['key', 'name']),
            'entries' => $entries,
            'total' => $entries->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content_item_id' => 'required|string|exists:content_items,id',
            'unit' => 'required|string|exists:units,key',
            'planned_date' => 'required|date',
            'channel' => 'nullable|string',
            'status' => 'string|in:' . implode(',', RedaktionsplanEntry::STATUSES),
            'notes' => 'nullable|string',
        ]);

        $unit = Unit::where('key', $validated['unit'])->firstOrFail();

        $entry = RedaktionsplanEntry::create([
            'unit_id' => $unit->id,
            'content_item_id' => $validated['content_item_id'],
            'planned_date' => $validated['planned_date'],
            'channel' => $validated['channel'] ?? null,
            'status' => $validated['status'] ?? 'geplant',
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json($entry->load('contentItem'), 201);
    }
}
