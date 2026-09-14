<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceInputQueue;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuellenPageController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Source::with(['strategy', 'angles']);

        if ($request->filled('strategy')) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $sources = $query->latest()->paginate(25)->withQueryString();
        $strategies = Strategy::all();

        // Eingangs-Queue (Draft-Angles aus automatischen Quellen) für Approval-UI
        $queueItems = SourceInputQueue::with(['source:id,title,type', 'strategy:id,key,name'])
            ->whereIn('status', ['done', 'processing'])
            ->whereNotNull('extracted_angles')
            ->latest()
            ->limit(50)
            ->get();
        $queueCounts = [
            'pending' => SourceInputQueue::where('status', 'pending')->count(),
            'done' => SourceInputQueue::where('status', 'done')->whereNotNull('extracted_angles')->count(),
        ];

        return Inertia::render('Quellen/Index', [
            'sources' => $sources,
            'strategies' => $strategies,
            'filters' => $request->only(['strategy', 'type']),
            'queueItems' => $queueItems,
            'queueCounts' => $queueCounts,
        ]);
    }
}
