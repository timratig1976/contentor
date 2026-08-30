<?php

namespace App\Http\Controllers;

use App\Models\Strategy;
use Inertia\Inertia;
use Inertia\Response;

class QuickInputPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('QuickInput/Index', [
            'strategies' => Strategy::all(),
        ]);
    }
}