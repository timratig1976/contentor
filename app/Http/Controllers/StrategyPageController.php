<?php

namespace App\Http\Controllers;

use App\Models\ContentStrategy;
use App\Models\Strategy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StrategyPageController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $strategyKey = $request->input('strategy', 'viscale');

        // Alle Strategien, inkl. Kinder-Zähler (für den Lösch-Dialog).
        $strategies = Strategy::withCount([
            'sources',
            'angles',
            'contentItems',
            'media',
            'personas',
            'redaktionsplanEntries',
            'contentStrategies',
        ])->get();

        // Fail-safe: angeforderte Strategie nicht (mehr) vorhanden?
        // - Wenn es andere gibt: sauber zu (erster) weiterleiten.
        // - Wenn keine existiert: Leere-State statt 500 rendern.
        if (! $strategies->contains('key', $strategyKey)) {
            $firstKey = $strategies->first()?->key;

            if ($firstKey && $firstKey !== $strategyKey) {
                return redirect()->route('strategie', ['strategy' => $firstKey]);
            }

            if ($strategies->isEmpty()) {
                return Inertia::render('Strategie/Index', [
                    'strategies' => [],
                    'currentStrategy' => null,
                    'contentKeys' => [],
                ]);
            }
        }

        $strategy = $strategies->firstWhere('key', $strategyKey);

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
