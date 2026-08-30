<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = Setting::all()->pluck('value', 'key');
        return response()->json($settings);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string',
            'value' => 'required|array',
        ]);

        $setting = Setting::updateOrCreate(
            ['key' => $validated['key']],
            ['value' => $validated['value']]
        );

        // Wenn Inertia-Request: redirect back statt JSON
        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json($setting, 201);
    }
}