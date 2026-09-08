<?php

namespace App\Http\Controllers;

use App\Models\Angle;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnglePageController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Angle::with(['strategy', 'source']);

        if ($request->filled('strategy')) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('batch')) {
            $query->where('batch_key', $request->input('batch'));
        }
        if ($request->filled('icp')) {
            $query->where('icp', $request->input('icp'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('funnel')) {
            $query->where('funnel', $request->input('funnel'));
        }

        $angles = $query->orderByDesc('ranking_score')->orderByDesc('created_at')->paginate(25)->withQueryString();

        $strategies = Strategy::all();
        $batches = Angle::distinct()->whereNotNull('batch_key')->pluck('batch_key');

        return Inertia::render('Angles/Index', [
            'angles' => $angles,
            'strategies' => $strategies,
            'batches' => $batches,
            'filters' => $request->only(['strategy', 'batch', 'icp', 'status', 'funnel']),
        ]);
    }

    public function show(Angle $angle): Response
    {
        $angle->load(['strategy', 'source', 'contentItems.media']);

        // Geladene Templates der Strategie für die Varianten-Generierung
        $templates = [];
        if ($angle->strategy) {
            $contentStrategy = \App\Models\ContentStrategy::where('strategy_id', $angle->strategy->id)
                ->where('key', 'post_templates')
                ->first();
            $templates = $contentStrategy?->content['templates'] ?? [];
        }

        return Inertia::render('Angles/Show', [
            'angle' => $angle,
            'templates' => $templates,
        ]);
    }
}
