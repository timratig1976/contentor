<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use App\Models\Setting;
use App\Models\Strategy;
use Inertia\Inertia;
use Inertia\Response;

class AgentsPageController extends Controller
{
    public function index(): Response
    {
        $settings = Setting::all()->pluck('value', 'key');
        $strategies = Strategy::all();
        $personaCount = \App\Models\Persona::where('active', true)->count();
        $strategyCount = \App\Models\ContentStrategy::count();
        $agentPrompts = Setting::where('key', 'agent_prompts')->first()?->value ?? [];
        $agentModels = Setting::where('key', 'agent_models')->first()?->value ?? [];
        $agentLogs = AgentLog::latest()->limit(100)->get();
        $personas = \App\Models\Persona::where('active', true)
            ->get(['id', 'strategy_id', 'name', 'role']);

        return Inertia::render('Agents/Index', [
            'settings' => $settings,
            'strategies' => $strategies,
            'agentPrompts' => $agentPrompts,
            'agentModels' => $agentModels,
            'agentLogs' => $agentLogs,
            'personas' => $personas,
            'stats' => [
                'personas' => $personaCount,
                'strategies' => $strategyCount,
            ],
        ]);
    }
}