<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuellenPageController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Source::with(['unit', 'angles']);

        if ($request->filled('unit')) {
            $query->whereHas('unit', fn ($q) => $q->where('key', $request->input('unit')));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $sources = $query->latest()->paginate(25)->withQueryString();
        $units = Unit::all();

        return Inertia::render('Quellen/Index', [
            'sources' => $sources,
            'units' => $units,
            'filters' => $request->only(['unit', 'type']),
        ]);
    }
}
