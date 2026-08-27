<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Models\Unit;
use Inertia\Inertia;
use Inertia\Response;

class EinstellungenPageController extends Controller
{
    public function index(): Response
    {
        $units = Unit::all();
        $personas = Persona::with('unit')->get();

        return Inertia::render('Einstellungen/Index', [
            'units' => $units,
            'personas' => $personas,
        ]);
    }
}
