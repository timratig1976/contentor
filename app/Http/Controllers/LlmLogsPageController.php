<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use Inertia\Inertia;
use Inertia\Response;

class LlmLogsPageController extends Controller
{
    /**
     * Eigene Ansicht für alle LLM-Calls (Agents, Quality-Gate, Bild-Generierung,
     * Assistant). Wurde aus der Agents-Seite herausgelöst, damit Logs dort den
     * Fokus bekommen und nicht die Konfiguration überladen.
     */
    public function index(): Response
    {
        $agentLogs = AgentLog::latest()->limit(200)->get();

        return Inertia::render('Logs/Index', [
            'agentLogs' => $agentLogs,
            'stats' => [
                'total' => AgentLog::count(),
                'success' => AgentLog::where('status', 'success')->count(),
                'error' => AgentLog::where('status', 'error')->count(),
            ],
        ]);
    }
}