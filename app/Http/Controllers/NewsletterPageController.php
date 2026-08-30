<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewsletterPageController extends Controller
{
    public function index(Request $request): Response
    {
        $strategyKey = $request->input('strategy', 'viscale');
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();
        $strategies = Strategy::all();

        $drafts = ContentItem::with(['strategy', 'angle'])
            ->where('strategy_id', $strategy->id)
            ->where('type', 'newsletter')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Newsletter/Index', [
            'drafts' => $drafts,
            'strategies' => $strategies,
            'currentStrategy' => $strategy,
        ]);
    }
}
