<?php

namespace App\Http\Controllers;

use App\Models\ContentMedia;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MediaPageController extends Controller
{
    public function index(Request $request): Response
    {
        $query = ContentMedia::with(['contentItem', 'unit']);

        if ($request->filled('unit')) {
            $query->whereHas('unit', fn ($q) => $q->where('key', $request->input('unit')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $media = $query->latest()->paginate(24)->withQueryString();
        $units = Unit::all();

        return Inertia::render('Medien/Index', [
            'media' => $media,
            'units' => $units,
            'filters' => $request->only(['unit', 'status', 'type']),
        ]);
    }
}
