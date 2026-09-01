<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Models\Strategy;
use Inertia\Inertia;
use Inertia\Response;

class PersonaPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Personas/Index', [
            'strategies' => Strategy::all(),
            'personas' => Persona::with(['strategies', 'strategy'])->get(),
        ]);
    }
}