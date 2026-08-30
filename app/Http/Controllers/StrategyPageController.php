<?php

namespace App\Http\Controllers;

use App\Models\ContentStrategy;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StrategyPageController extends Controller
{
    public function index(Request $request): Response
    {
        $strategyKey = $request->input('strategy', 'viscale');
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();
        $strategies = Strategy::all();

        $contentStrategies = ContentStrategy::where('strategy_id', $strategy->id)->get()->keyBy('key');

        $keys = collect(ContentStrategy::KEYS)->map(fn ($key) => [
            'key' => $key,
            'label' => ContentStrategy::LABELS[$key] ?? $key,
            'content' => $contentStrategies->get($key)?->content,
            'version' => $contentStrategies->get($key)?->version ?? 0,
            'updated_at' => $contentStrategies->get($key)?->updated_at,
        ]);

        return Inertia::render('Strategie/Index', [
            'strategies' => $strategies,
            'currentStrategy' => $strategy,
            'contentKeys' => $keys,
        ]);
    }
}
