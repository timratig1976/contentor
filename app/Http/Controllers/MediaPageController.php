<?php

namespace App\Http\Controllers;

use App\Models\ContentMedia;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MediaPageController extends Controller
{
    public function index(Request $request): Response
    {
        $query = ContentMedia::with(['contentItem', 'strategy']);

        if ($request->filled('strategy')) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $media = $query->latest()->paginate(24)->withQueryString();
        $strategies = Strategy::all();

        return Inertia::render('Medien/Index', [
            'media' => $media,
            'strategies' => $strategies,
            'filters' => $request->only(['strategy', 'status', 'type']),
        ]);
    }
}
