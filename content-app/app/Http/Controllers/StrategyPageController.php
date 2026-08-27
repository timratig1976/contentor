<?php

namespace App\Http\Controllers;

use App\Models\ContentStrategy;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StrategyPageController extends Controller
{
    public function index(Request $request): Response
    {
        $unitKey = $request->input('unit', 'viscale');
        $unit = Unit::where('key', $unitKey)->firstOrFail();
        $units = Unit::all();

        $strategies = ContentStrategy::where('unit_id', $unit->id)->get()->keyBy('key');

        $keys = collect(ContentStrategy::KEYS)->map(fn ($key) => [
            'key' => $key,
            'label' => ContentStrategy::LABELS[$key] ?? $key,
            'content' => $strategies->get($key)?->content,
            'version' => $strategies->get($key)?->version ?? 0,
            'updated_at' => $strategies->get($key)?->updated_at,
        ]);

        return Inertia::render('Strategie/Index', [
            'units' => $units,
            'currentUnit' => $unit,
            'strategyKeys' => $keys,
        ]);
    }
}
