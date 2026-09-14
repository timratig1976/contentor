<?php

namespace App\Http\Controllers;

use App\Models\PostTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplatesPageController extends Controller
{
    /**
     * Globaler Template-Katalog — bewusst KEINE Strategie-Bindung.
     * Gute Templates werden zentral gesammelt und stehen allen
     * Strategien zur Verfügung.
     */
    public function index(Request $request): Response
    {
        $templates = PostTemplate::where('active', true)
            ->orderBy('format')->orderBy('name')->get();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
            'formats' => PostTemplate::FORMATS,
        ]);
    }
}