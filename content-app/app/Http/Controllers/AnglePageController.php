<?php

namespace App\Http\Controllers;

use App\Models\Angle;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnglePageController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Angle::with(['unit', 'source']);

        if ($request->filled('unit')) {
            $query->whereHas('unit', fn ($q) => $q->where('key', $request->input('unit')));
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

        $units = Unit::all();
        $batches = Angle::distinct()->whereNotNull('batch_key')->pluck('batch_key');

        return Inertia::render('Angles/Index', [
            'angles' => $angles,
            'units' => $units,
            'batches' => $batches,
            'filters' => $request->only(['unit', 'batch', 'icp', 'status', 'funnel']),
        ]);
    }

    public function show(Angle $angle): Response
    {
        $angle->load(['unit', 'source', 'contentItems.media']);

        return Inertia::render('Angles/Show', [
            'angle' => $angle,
        ]);
    }
}
