<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewsletterPageController extends Controller
{
    public function index(Request $request): Response
    {
        $unitKey = $request->input('unit', 'viscale');
        $unit = Unit::where('key', $unitKey)->firstOrFail();
        $units = Unit::all();

        $drafts = ContentItem::with(['unit', 'angle'])
            ->where('unit_id', $unit->id)
            ->where('type', 'newsletter')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Newsletter/Index', [
            'drafts' => $drafts,
            'units' => $units,
            'currentUnit' => $unit,
        ]);
    }
}
