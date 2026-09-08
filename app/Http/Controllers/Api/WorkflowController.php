<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    /**
     * Startet den Python-Multi-Agent-Workflow im Debug-Modus (trace_output.json)
     * im Hintergrund und speichert Input + Ergebnis in workflow_runs.
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'input' => 'required|string|max:1000',
        ]);

        $run = WorkflowRun::create([
            'input' => $validated['input'],
            'status' => 'running',
        ]);

        $base = base_path('content-agent');
        $script = $base . '/debug_run.py';

        $python = trim((string) shell_exec('command -v python3'));
        if ($python === '') {
            $python = 'python3';
        }

        // Detached ausführen: python debug_run.py <input> >> <run>.log 2>&1 &
        // Ergebnis (trace_output.json) wird beim Poll zurückgelesen.
        $inputArg = escapeshellarg($validated['input']);
        $logFile = storage_path('logs/workflow_' . $run->id . '.log');

        $cmd = 'cd ' . escapeshellarg($base)
            . ' && ' . escapeshellarg($python) . ' ' . escapeshellarg($script)
            . ' ' . $inputArg . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';

        exec($cmd);

        return response()->json([
            'run' => $run,
            'message' => 'Workflow gestartet. Ergebnis wird im Verlauf aktualisiert.',
        ], 202);
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