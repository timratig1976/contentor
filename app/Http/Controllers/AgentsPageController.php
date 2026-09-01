<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use App\Models\Setting;
use App\Models\Strategy;
use Inertia\Inertia;
use Inertia\Response;

class AgentsPageController extends Controller
{
    /**
     * Standard-Workflow-Loops (spiegeln den bisherigen Coordinator-Prompt:
     * Review → Production, max. 2 Überarbeitungsrunden).
     */
    public const DEFAULT_WORKFLOW_LOOPS = [
        [
            'id' => 'review-rework',
            'name' => 'Qualitäts-Loop',
            'from_agent' => 'review',
            'to_agent' => 'production',
            'condition' => 'verdict = "fail"',
            'max_rounds' => 2,
        ],
    ];

    public function index(): Response
    {
        $settings = Setting::all()->pluck('value', 'key');
        $strategies = Strategy::all();
        $personaCount = \App\Models\Persona::where('active', true)->count();
        $strategyCount = \App\Models\ContentStrategy::count();
        $agentPrompts = Setting::where('key', 'agent_prompts')->first()?->value ?? [];
        $agentModels = Setting::where('key', 'agent_models')->first()?->value ?? [];
        $agentLogs = AgentLog::latest()->limit(100)->get();
        $workflowLoops = Setting::where('key', 'workflow_loops')->first()?->value
            ?? self::DEFAULT_WORKFLOW_LOOPS;
        $personas = \App\Models\Persona::where('active', true)
            ->get(['id', 'strategy_id', 'name', 'role']);

        return Inertia::render('Agents/Index', [
            'settings' => $settings,
            'strategies' => $strategies,
            'agentPrompts' => $agentPrompts,
            'agentModels' => $agentModels,
            'agentLogs' => $agentLogs,
            'workflowLoops' => $workflowLoops,
            'personas' => $personas,
            'stats' => [
                'personas' => $personaCount,
                'strategies' => $strategyCount,
            ],
        ]);
    }
}