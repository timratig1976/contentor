<?php

namespace App\Http\Controllers;

use App\Models\ContentStrategy;
use App\Models\PostTemplate;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplatesPageController extends Controller
{
    public function index(Request $request): Response
    {
        $strategyKey = $request->input('strategy', 'viscale');
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();
        $strategies = Strategy::all();

        $templates = PostTemplate::orderBy('format')->orderBy('name')->get();

        $cs = ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'post_templates')->first();
        $selected = $cs?->content['selected'] ?? $templates->pluck('id')->all();

        return Inertia::render('Templates/Index', [
            'strategies' => $strategies,
            'currentStrategy' => $strategy,
            'templates' => $templates,
            'selected' => $selected,
            'formats' => PostTemplate::FORMATS,
        ]);
    }
}