<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\RedaktionsplanEntry;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RedaktionsplanPageController extends Controller
{
    public function index(Request $request): Response
    {
        $unitKey = $request->input('unit', 'viscale');
        $unit = Unit::where('key', $unitKey)->firstOrFail();
        $units = Unit::all();

        $items = ContentItem::with(['angle', 'media'])
            ->where('unit_id', $unit->id)
            ->whereNotIn('status', ['verworfen'])
            ->get();

        $kanban = [];
        foreach (RedaktionsplanEntry::KANBAN_COLUMNS as $col) {
            $kanban[$col] = $items->where('status', $col)->values();
        }

        return Inertia::render('Redaktionsplan/Index', [
            'kanban' => $kanban,
            'units' => $units,
            'currentUnit' => $unit,
            'columns' => RedaktionsplanEntry::KANBAN_COLUMNS,
        ]);
    }
}
