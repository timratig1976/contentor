<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\RedaktionsplanEntry;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedaktionsplanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $strategyKey = $request->input('strategy', 'viscale');
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();

        $query = RedaktionsplanEntry::with('contentItem.angle')
            ->where('strategy_id', $strategy->id);

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
                ->where('strategy_id', $strategy->id)
                ->whereNotIn('status', ['verworfen'])
                ->get();

            $kanban = [];
            foreach (RedaktionsplanEntry::KANBAN_COLUMNS as $col) {
                $kanban[$col] = $items->where('status', $col)->values();
            }

            return response()->json([
                'strategy' => $strategy->only(['key', 'name']),
                'kanban' => $kanban,
            ]);
        }

        return response()->json([
            'strategy' => $strategy->only(['key', 'name']),
            'entries' => $entries,
            'total' => $entries->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content_item_id' => 'required|string|exists:content_items,id',
            'strategy' => 'required|string|exists:strategies,key',
            'planned_date' => 'required|date',
            'channel' => 'nullable|string',
            'status' => 'string|in:' . implode(',', RedaktionsplanEntry::STATUSES),
            'notes' => 'nullable|string',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();

        $entry = RedaktionsplanEntry::create([
            'strategy_id' => $strategy->id,
            'content_item_id' => $validated['content_item_id'],
            'planned_date' => $validated['planned_date'],
            'channel' => $validated['channel'] ?? null,
            'status' => $validated['status'] ?? 'geplant',
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json($entry->load('contentItem'), 201);
    }
}
