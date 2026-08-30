<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\RedaktionsplanEntry;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RedaktionsplanPageController extends Controller
{
    public function index(Request $request): Response
    {
        $strategyKey = $request->input('strategy', 'viscale');
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();
        $strategies = Strategy::all();

        $items = ContentItem::with(['angle', 'media'])
            ->where('strategy_id', $strategy->id)
            ->whereNotIn('status', ['verworfen'])
            ->get();

        $kanban = [];
        foreach (RedaktionsplanEntry::KANBAN_COLUMNS as $col) {
            $kanban[$col] = $items->where('status', $col)->values();
        }

        return Inertia::render('Redaktionsplan/Index', [
            'kanban' => $kanban,
            'strategies' => $strategies,
            'currentStrategy' => $strategy,
            'columns' => RedaktionsplanEntry::KANBAN_COLUMNS,
        ]);
    }
}
