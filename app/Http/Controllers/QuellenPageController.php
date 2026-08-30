<?php

namespace App\Http\Controllers;

use App\Models\Source;
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

        return Inertia::render('Quellen/Index', [
            'sources' => $sources,
            'strategies' => $strategies,
            'filters' => $request->only(['strategy', 'type']),
        ]);
    }
}
