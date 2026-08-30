<?php

namespace App\Http\Controllers;

use App\Models\ContentStrategy;
use App\Models\Persona;
use App\Models\Setting;
use App\Models\Strategy;
use Inertia\Inertia;
use Inertia\Response;

class EinstellungenPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Einstellungen/Index', [
            'settings' => Setting::all()->pluck('value', 'key'),
        ]);
    }
}
