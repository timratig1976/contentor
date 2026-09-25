<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    /**
     * Startet einen PHP-nativen Multi-Agent-Workflow via Neuron AI.
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'input'    => 'required|string|max:2000',
            'strategy' => 'nullable|string|exists:strategies,key',
            'phases'   => 'nullable|array',
            'phases.*' => 'in:research,angle,production,review',
            'persona_id' => 'nullable|integer|exists:personas,id',
        ]);

        $strategyKey = $validated['strategy'] ?? 'viscale';
        $phases = $validated['phases'] ?? ['research', 'angle', 'production', 'review'];
        $testMode = $request->boolean('test_mode', true); // Test-Modus: Angles als Vorschlag merken, keine automatische DB-Befüllung
        $personaId = !empty($validated['persona_id']) ? (int) $validated['persona_id'] : null;

        $run = WorkflowRun::create([
            'input'  => $validated['input'],
            'status' => 'running',
            'trace'  => json_encode([
                'started_at' => now()->toIso8601String(),
                'strategy'   => $strategyKey,
                'phases'     => $phases,
                'persona_id' => $personaId,
                'test_mode'  => $testMode,
                'timeline'   => [
                    [
                        'id' => 'evt_init',
                        'type' => 'llm_input',
                        'agent' => 'research',
                        'title' => 'Starte Pipeline: Initialisierung…',
                        'details' => ['input' => $validated['input'], 'phases' => $phases, 'persona_id' => $personaId],
                        'timestamp' => now()->toIso8601String(),
                    ]
                ],
                'loops'      => [],
                'proposed_angles' => [],
                'steps'      => [
                    [
                        'agent' => 'research',
                        'status' => 'running',
                        'message' => 'Initialisiere Neuron AI Multi-Agent Pipeline…',
                        'timestamp' => now()->toIso8601String(),
                    ]
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Workflow asynchron im Hintergrund-Job ausführen
        \App\Jobs\ExecuteContentWorkflowJob::dispatch(
            $run->id,
            $strategyKey,
            $validated['input'],
            $phases,
            $testMode,
            $personaId
        );

        return response()->json([
            'run'     => $run->fresh(),
            'message' => 'Workflow gestartet.',
        ], 200);
    }

    /**
     * Status & Ergebnis aller Workflow-Runs (automatische Historie).
     * Re-injiziert laufende Runs mit dem Ergebnis aus trace_output.json.
     */
    public function index(Request $request): JsonResponse
    {
        $runs = WorkflowRun::latest()->limit(50)->get();

        // Laufende Runs: prüfen, ob trace_output.json inzwischen geschrieben wurde
        foreach ($runs as $run) {
            if ($run->status === 'running') {
                $this->syncTraceFor($run);
            }
        }

        return response()->json($runs);
    }

    public function show(WorkflowRun $run): JsonResponse
    {
        if ($run->status === 'running') {
            $this->syncTraceFor($run);
        }

        return response()->json($run->fresh());
    }

    /**
     * Gibt einen im Test-Lauf vorgeschlagenen Angle frei und legt ihn in der Datenbank an.
     */
    public function approveAngle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'run_id'      => 'required|integer|exists:workflow_runs,id',
            'proposed_id' => 'required|string',
        ]);

        $run = WorkflowRun::findOrFail($validated['run_id']);
        $trace = json_decode($run->trace ?? '{}', true) ?: [];
        $proposedAngles = $trace['proposed_angles'] ?? [];

        $targetIdx = null;
        foreach ($proposedAngles as $idx => $p) {
            if (($p['id'] ?? '') === $validated['proposed_id']) {
                $targetIdx = $idx;
                break;
            }
        }

        if ($targetIdx === null) {
            return response()->json(['error' => 'Vorgeschlagener Angle nicht im Run gefunden.'], 404);
        }

        $prop = $proposedAngles[$targetIdx];

        if (!empty($prop['saved_angle_id'])) {
            return response()->json([
                'message'  => 'Angle wurde bereits freigegeben.',
                'angle_id' => $prop['saved_angle_id'],
            ]);
        }

        $strategyKey = $prop['strategy'] ?? 'viscale';
        $strategyModel = \App\Models\Strategy::where('key', $strategyKey)->first();

        $angle = \App\Models\Angle::create([
            'angle'          => $prop['angle'],
            'strategy_id'    => $strategyModel?->id,
            'icp'            => $prop['icp'] ?? null,
            'pain_cluster'   => $prop['pain_cluster'] ?? null,
            'statement_type' => $prop['statement_type'] ?? null,
            'funnel'         => $prop['funnel'] ?? null,
            'batch_key'      => $prop['batch_key'] ?? ('run_' . $run->id),
            'r_zielgruppe'   => $prop['r_zielgruppe'] ?? null,
            'r_viscale_fit'  => $prop['r_viscale_fit'] ?? null,
            'r_schaerfe'     => $prop['r_schaerfe'] ?? null,
            'r_timing'       => $prop['r_timing'] ?? null,
            'ranking_score'  => $prop['ranking_score'] ?? null,
            'score_reasoning'=> $prop['score_reasoning'] ?? null,
            'status'         => 'approved',
        ]);

        $proposedAngles[$targetIdx]['approved'] = true;
        $proposedAngles[$targetIdx]['saved_angle_id'] = $angle->id;
        $proposedAngles[$targetIdx]['status'] = 'approved';

        $trace['proposed_angles'] = $proposedAngles;
        $run->update(['trace' => json_encode($trace, JSON_UNESCAPED_UNICODE)]);

        return response()->json([
            'message'  => 'Angle erfolgreich freigegeben und in Pipeline übernommen.',
            'angle_id' => $angle->id,
            'angle'    => $angle,
            'run'      => $run->fresh(),
        ]);
    }

    /**
     * Gibt alle noch offenen vorgeschlagenen Angles eines Runs auf einmal frei.
     */
    public function approveAllAngles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'run_id' => 'required|integer|exists:workflow_runs,id',
        ]);

        $run = WorkflowRun::findOrFail($validated['run_id']);
        $trace = json_decode($run->trace ?? '{}', true) ?: [];
        $proposedAngles = $trace['proposed_angles'] ?? [];

        $created = [];
        foreach ($proposedAngles as $idx => $prop) {
            if (empty($prop['saved_angle_id'])) {
                $strategyKey = $prop['strategy'] ?? 'viscale';
                $strategyModel = \App\Models\Strategy::where('key', $strategyKey)->first();

                $angle = \App\Models\Angle::create([
                    'angle'          => $prop['angle'],
                    'strategy_id'    => $strategyModel?->id,
                    'icp'            => $prop['icp'] ?? null,
                    'pain_cluster'   => $prop['pain_cluster'] ?? null,
                    'statement_type' => $prop['statement_type'] ?? null,
                    'funnel'         => $prop['funnel'] ?? null,
                    'batch_key'      => $prop['batch_key'] ?? ('run_' . $run->id),
                    'r_zielgruppe'   => $prop['r_zielgruppe'] ?? null,
                    'r_viscale_fit'  => $prop['r_viscale_fit'] ?? null,
                    'r_schaerfe'     => $prop['r_schaerfe'] ?? null,
                    'r_timing'       => $prop['r_timing'] ?? null,
                    'ranking_score'  => $prop['ranking_score'] ?? null,
                    'score_reasoning'=> $prop['score_reasoning'] ?? null,
                    'status'         => 'approved',
                ]);

                $proposedAngles[$idx]['approved'] = true;
                $proposedAngles[$idx]['saved_angle_id'] = $angle->id;
                $proposedAngles[$idx]['status'] = 'approved';
                $created[] = $angle->id;
            }
        }

        $trace['proposed_angles'] = $proposedAngles;
        $run->update(['trace' => json_encode($trace, JSON_UNESCAPED_UNICODE)]);

        return response()->json([
            'message'     => count($created) . ' Angles erfolgreich in Pipeline übernommen.',
            'created_ids' => $created,
            'run'         => $run->fresh(),
        ]);
    }

    /**
     * Bricht einen laufenden Workflow-Run ab.
     */
    public function cancel(int $id): JsonResponse
    {
        $run = WorkflowRun::find($id);
        if (!$run) {
            return response()->json(['error' => 'Run not found'], 404);
        }

        $trace = json_decode($run->trace ?? '{}', true) ?: [];
        $timeline = $trace['timeline'] ?? [];
        $timeline[] = [
            'id' => 'evt_cancelled_' . uniqid(),
            'type' => 'info',
            'agent' => 'system',
            'title' => 'Workflow durch Benutzer abgebrochen',
            'details' => ['cancelled_at' => now()->toIso8601String()],
            'timestamp' => now()->toIso8601String(),
        ];
        $trace['timeline'] = $timeline;

        $run->update([
            'status' => 'cancelled',
            'trace'  => json_encode($trace, JSON_UNESCAPED_UNICODE),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Workflow erfolgreich abgebrochen.',
            'run'     => $run->fresh(),
        ]);
    }

    /**
     * Liest trace_output.json (vom Python debug_run.py geschrieben) und
     * markiert den Lauf als success — sonst (wenn der Prozess tot ist) error.
     */
    private function syncTraceFor(WorkflowRun $run): void
    {
        $traceFile = base_path('content-agent/trace_output.json');

        if (file_exists($traceFile)) {
            $raw = file_get_contents($traceFile);
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $output = $this->renderTrace($data);
                $run->update([
                    'status' => 'success',
                    'output' => $output,
                    'trace' => $raw,
                    'exit_code' => 0,
                ]);
                return;
            }
        }

        // Kein Trace: Prozess evtl. noch am Laufen — nicht als Error markieren,
        // solange kein PID-Timestamp-Signal für Abbruch vorliegt. Wir lassen es auf "running".
        // Als Heuristik: laufende Runs > 20 Minuten markieren wir als error.
        if ($run->created_at->diffInMinutes(now()) > 20) {
            $run->update(['status' => 'error', 'output' => 'Timeout: kein Ergebnis nach 20 Minuten.']);
        }
    }

    /**
     * Render die JSON-Trace in einen lesbaren Konsolen-Text.
     */
    private function renderTrace(array $data): string
    {
        $lines = ["=== CONTENTOR WORKFLOW TRACE ==="];
        if (! empty($data['input'])) {
            $lines[] = "\n👤 INPUT: " . $data['input'];
        }

        $steps = $data['steps'] ?? [];
        foreach ($steps as $s) {
            match ($s['role'] ?? '') {
                'user' => $lines[] = "\n👤 USER:\n" . ($s['text'] ?? ''),
                'assistant' => $lines[] = "\n🤖 ASSISTANT:\n" . ($s['text'] ?? ''),
                'tool_call' => $lines[] = "\n🔧 TOOL-CALL → " . ($s['tool'] ?? '?') . "\n   Args: " . json_encode($s['arguments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'tool_result' => $lines[] = "   ↳ ERGEBNIS: " . $this->truncate(json_encode($s['result'] ?? (($s['error'] ?? null) ? ['error' => $s['error']] : null), JSON_UNESCAPED_UNICODE)),
                default => null,
            };
        }

        if (! empty($data['last_message'])) {
            $lines[] = "\n=== LETZTE ANTWORT ===\n" . $data['last_message'];
        }

        return implode("\n", $lines);
    }

    private function truncate(string $s, int $max = 2000): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . "\n… [gekürzt]" : $s;
    }
}