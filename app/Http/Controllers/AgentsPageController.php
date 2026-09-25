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

    /**
     * Agent Flow Visualizer & Interactive Workflow Tester page.
     */
    public function flow(): Response
    {
        $strategies = Strategy::all();
        $personas = \App\Models\Persona::where('active', true)->get(['id', 'name', 'role']);
        $recentRuns = \App\Models\WorkflowRun::latest()->limit(20)->get();
        $agentModels = Setting::where('key', 'agent_models')->first()?->value ?? [];
        $agentPrompts = Setting::where('key', 'agent_prompts')->first()?->value ?? [];
        $workflowLoops = Setting::where('key', 'workflow_loops')->first()?->value
            ?? self::DEFAULT_WORKFLOW_LOOPS;

        $defaultPrompts = [
            'research' => "Du bist Stratege, kein Texter. Formuliere sachlich wie in einer internen Analyse.\nKeine Hooks, keine Werbeslogans, keine direkte Kundenansprache (Sie/Ihr), keine Stilmittel.\n\nDeine Aufgabe: Erzeuge GENAU 5 strategische Angles zu dem Thema:\n- 5 verschiedene pain_cluster / Mechanismus-Kombinationen\n- Funnel-Mix: min. 2 ToFu, 2 MoFu, 1 BoFu\n- Min. 1 Angle besetzt zwingend eine hinterlegte Markt-Lücke\n- Jeder Angle belegt durch konkreten Trigger, Einwand oder Kundenzitat aus dem Kontext\n- Keine Zahlen außer sie stehen explizit im Kontext\n\nSCHEMA PRO ANGLE:\n- these: Kernbehauptung, 1 Satz, neutral und sachlich (max. 180 Zeichen)\n- mechanismus: Warum das so ist (Ursache → Wirkung)\n- implikation: Was der ICP daraus ändern muss\n- beleg: Konkretes Zitat, Trigger oder Einwand aus dem Kontext\n- icp: ICP-1 | ICP-2 | ICP-3\n- pain_cluster: E3-01 | E3-02 | E3-03 | E3-05 | E3-06 | E1-02\n- funnel: ToFu | MoFu | BoFu\n\nBEISPIEL:\nthese: CRM-Adoption scheitert am fehlenden Eigennutzen für Vertriebler.\nmechanismus: Pflege dient nur Reporting fürs Management; Vertriebler hat Aufwand ohne Rückfluss → Excel bleibt parallel.\nimplikation: Prozesse so bauen, dass Eintragen Arbeit spart (Automatisierung, Aufgaben, Vorbereitung).\nbeleg: „Ich zahle für HubSpot und Excel gleichzeitig.\"\n\nSpeichere jeden via create_angle(these, mechanismus, implikation, beleg, icp, pain_cluster, funnel). Keine weitere Textausgabe nach dem Speichern.",
            'angle' => "Du bist der Angle-Ranking-Agent. Du bewertest und schärfst vorhandene Angles.\n\nSCHRITT 1: get_strategy_context laden.\nSCHRITT 2: list_angles laden (alle Angles des aktuellen Batches).\nSCHRITT 3: Jeden Angle bewerten: r_zielgruppe, r_viscale_fit, r_schaerfe, r_timing (je 1-3).\nSCHRITT 4: update_angle pro Angle mit Scores + score_reasoning + funnel-Zuordnung (ToFu/MoFu/BoFu).\nSCHRITT 5: get_batch_ranking und Top 3 Angles für die Produktion melden.",
            'production' => "Du bist der Content-Produktionsagent. Du produzierst fertige Posts aus bewerteten Top-Angles.\n\nSCHRITT 1: get_strategy_context laden.\nSCHRITT 2: Für jeden übergebenen Angle: produce_content aufrufen (format: linkedin_post).\nSCHRITT 3: Brand Voice, Funnel-Dramaturgie und ICP-Tonalität einhalten.\nSCHRITT 4: Content-IDs und Textvorschau zurückmelden.",
            'review' => "Du bist der Quality-Review-Agent. Du prüfst produzierten Content gegen Brand Voice und Qualitätsregeln.\n\nFür jeden Content-Item:\n1. Brand Voice Check: Hält der Text Personality, Tone, Must-Haves und No-Gos ein?\n2. Funnel Check: Passt die Dramaturgie zur Funnel-Stufe (ToFu/MoFu/BoFu)?\n3. ICP Check: Trifft der Text den richtigen Schmerz des ICPs?\n4. Fakten Check: Zahlen belegt und korrekt?\n5. Liefere ein klares verdict: \"pass\" oder \"fail\" mit konkretem Feedback.",
        ];

        return Inertia::render('Agents/Flow', [
            'strategies'     => $strategies,
            'personas'       => $personas,
            'recentRuns'     => $recentRuns,
            'agentModels'    => $agentModels,
            'agentPrompts'   => $agentPrompts,
            'defaultPrompts' => $defaultPrompts,
            'workflowLoops'  => $workflowLoops,
        ]);
    }
}