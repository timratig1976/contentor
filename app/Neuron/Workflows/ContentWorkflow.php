<?php

namespace App\Neuron\Workflows;

use App\Models\Setting;
use App\Models\WorkflowRun;
use App\Neuron\Agents\NeuronAngleAgent;
use App\Neuron\Agents\NeuronProductionAgent;
use App\Neuron\Agents\NeuronResearchAgent;
use App\Neuron\Agents\NeuronReviewAgent;
use App\Neuron\Tools\ContentorToolkit;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * ContentWorkflow — Full multi-agent workflow orchestration via Neuron AI agents.
 * Runs sequential phases with dynamic feedback loops and saves complete traces,
 * inter-LLM communication, tool calls, and staged angle proposals to WorkflowRun.
 */
class ContentWorkflow
{
    private string $strategyKey;
    private ?int $personaId;
    private ?WorkflowRun $run;
    private bool $testMode;
    private ContentorToolkit $toolkit;
    private string $currentPhase = 'init';
    private array $timeline = [];
    private array $steps = [];
    private array $loops = [];
    private int $eventCounter = 0;

    public function __construct(
        string $strategyKey = 'viscale',
        ?int $personaId = null,
        ?WorkflowRun $run = null,
        bool $testMode = true
    ) {
        $this->strategyKey = $strategyKey;
        $this->personaId = $personaId;
        $this->run = $run;
        $this->testMode = $testMode;

        $this->toolkit = ContentorToolkit::make(
            $this->strategyKey,
            $this->testMode,
            function (array $callInfo) {
                $this->recordToolCall($callInfo);
            },
            $this->personaId
        );
    }

    /**
     * Executes the workflow.
     *
     * @param string $topic
     * @param array $phases
     * @return array
     */
    public function run(string $topic, array $phases = ['research', 'angle', 'production', 'review']): array
    {
        $start = microtime(true);
        $logs = [];

        try {
            if ($this->isCancelled()) {
                return ['status' => 'cancelled', 'logs' => $logs, 'output' => 'Workflow vor Start abgebrochen'];
            }

            // Phase 1: Research
            if (in_array('research', $phases)) {
                $this->currentPhase = 'research';
                $this->updateRunStep('research', 'running', 'Research-Agent analysiert Thema & Quellen…');
                $res = $this->runResearch($topic);
                $logs['research'] = $res;
                $this->steps[] = ['agent' => 'research', 'status' => 'done', 'result' => $res];
                $this->updateRunStep('research', 'done', $res['output']);

                if ($this->isCancelled()) {
                    return ['status' => 'cancelled', 'logs' => $logs, 'output' => 'Workflow abgebrochen'];
                }

                // Inter-LLM Communication: Research -> Angle
                if (in_array('angle', $phases)) {
                    $this->recordCommunication(
                        from: 'research',
                        to: 'angle',
                        title: 'Übergabe: Research-Ergebnisse an Angle-Agent übergeben',
                        payload: $res['output'],
                        summary: 'Recherchierte Fakten, Quellen und erste Themenvorschläge zur Bewertung weitergeleitet.'
                    );
                }
            }

            if ($this->isCancelled()) {
                return ['status' => 'cancelled', 'logs' => $logs, 'output' => 'Workflow abgebrochen'];
            }

            // Phase 2: Angle Ranking
            if (in_array('angle', $phases)) {
                $this->currentPhase = 'angle';
                $this->updateRunStep('angle', 'running', 'Angle-Agent bewertet und rankt Thesen…');
                $res = $this->runAngle($topic, $logs['research']['output'] ?? '');
                $logs['angle'] = $res;
                $this->steps[] = ['agent' => 'angle', 'status' => 'done', 'result' => $res];
                $this->updateRunStep('angle', 'done', $res['output']);

                if ($this->isCancelled()) {
                    return ['status' => 'cancelled', 'logs' => $logs, 'output' => 'Workflow abgebrochen'];
                }

                // Inter-LLM Communication: Angle -> Production
                if (in_array('production', $phases)) {
                    $this->recordCommunication(
                        from: 'angle',
                        to: 'production',
                        title: 'Übergabe: Gerankte Angles an Production-Agent übergeben',
                        payload: $res['output'],
                        summary: 'Top bewertete Thesen und Hooks zur Content-Erstellung weitergeleitet.'
                    );
                }
            }

            if ($this->isCancelled()) {
                return ['status' => 'cancelled', 'logs' => $logs, 'output' => 'Workflow abgebrochen'];
            }

            // Phase 3 & 4: Production + Review (with configured Loops)
            if (in_array('production', $phases)) {
                $this->currentPhase = 'production';
                $this->updateRunStep('production', 'running', 'Production-Agent schreibt Content…');
                [$prodRes, $revRes] = $this->runProductionAndReview($topic, $logs['angle']['output'] ?? '', in_array('review', $phases));
                $logs['production'] = $prodRes;
                $logs['review'] = $revRes;
                $this->steps[] = ['agent' => 'production', 'status' => 'done', 'result' => $prodRes];
                if ($revRes) {
                    $this->steps[] = ['agent' => 'review', 'status' => 'done', 'result' => $revRes];
                }
            }

            $duration = (int) ((microtime(true) - $start) * 1000);
            $finalOutput = $this->formatFinalOutput($logs);

            if ($this->run) {
                $this->saveFullTrace('success', $finalOutput, $duration, 0, $phases);
            }

            return ['status' => 'success', 'logs' => $logs, 'output' => $finalOutput];
        } catch (\Throwable $e) {
            $duration = (int) ((microtime(true) - $start) * 1000);
            if ($this->run) {
                $this->saveFullTrace('error', "Fehler im Workflow: " . $e->getMessage(), $duration, 1, $phases);
            }
            throw $e;
        }
    }

    private function runResearch(string $topic): array
    {
        $agent = NeuronResearchAgent::make()->withContext($this->strategyKey, $this->personaId, $this->toolkit);
        $prompt = "Thema: {$topic}\nErzeuge GENAU 5 Angles nach deinen Regeln und speichere jeden via create_angle(). Keine weitere Textausgabe.";

        $this->recordEvent(
            type: 'llm_input',
            agent: 'research',
            title: 'Research-Agent Prompt & Kontext übergeben',
            details: [
                'model' => $agent->getModelInfo(),
                'user_prompt' => $prompt,
                'system_prompt' => $agent->getFullInstructions(),
                'topic' => $topic,
                'strategy' => $this->strategyKey,
                'persona_id' => $this->personaId,
            ]
        );

        $response = $agent->chat(new UserMessage($prompt))->getMessage();
        $output = $response->getContent() ?? '';

        $this->recordEvent(
            type: 'llm_output',
            agent: 'research',
            title: 'Research-Agent Antwort empfangen',
            details: [
                'output' => $output,
            ]
        );

        return ['output' => $output];
    }

    private function runAngle(string $topic, string $researchContext): array
    {
        $agent = NeuronAngleAgent::make()->withContext($this->strategyKey, $this->personaId, $this->toolkit);
        $prompt = "Bewerte die gefundenen Angles zum Thema '{$topic}'.\n\nRecherche-Kontext:\n{$researchContext}\n\nBewerte alle Angles mit Zielgruppe, Fit, Schärfe und Timing (Scores 1-3).";

        $this->recordEvent(
            type: 'llm_input',
            agent: 'angle',
            title: 'Angle-Agent Prompt & Recherche-Kontext übergeben',
            details: [
                'model' => $agent->getModelInfo(),
                'user_prompt' => $prompt,
                'system_prompt' => $agent->getFullInstructions(),
                'topic' => $topic,
                'research_context' => $researchContext,
                'strategy' => $this->strategyKey,
                'persona_id' => $this->personaId,
            ]
        );

        $response = $agent->chat(new UserMessage($prompt))->getMessage();
        $output = $response->getContent() ?? '';

        $this->recordEvent(
            type: 'llm_output',
            agent: 'angle',
            title: 'Angle-Agent Bewertung abgeschlossen',
            details: [
                'output' => $output,
            ]
        );

        return ['output' => $output];
    }

    private function runProductionAndReview(string $topic, string $angleContext, bool $includeReview = true): array
    {
        $loopsConfig = Setting::where('key', 'workflow_loops')->first()?->value ?? [];
        $maxRounds = 2;
        foreach ($loopsConfig as $l) {
            if (($l['from_agent'] ?? '') === 'review' && ($l['to_agent'] ?? '') === 'production') {
                $maxRounds = max(1, (int) ($l['max_rounds'] ?? 2));
                break;
            }
        }

        $prodAgent = NeuronProductionAgent::make()->withContext($this->strategyKey, $this->personaId, $this->toolkit);
        $revAgent = NeuronReviewAgent::make()->withContext($this->strategyKey, $this->personaId, $this->toolkit);

        $prodRes = null;
        $revRes = null;
        $round = 0;
        $feedback = '';

        do {
            $round++;
            $this->currentPhase = 'production';

            $prodPrompt = $round === 1
                ? "Produziere Content für die Top-Angles zum Thema '{$topic}'.\n\nAngles & Kontext:\n{$angleContext}"
                : "Überarbeite den produzierten Content anhand des Feedbacks aus der Qualitätsprüfung (Feedback-Runde {$round}/{$maxRounds}):\n\n{$feedback}";

            $this->recordEvent(
                type: 'llm_input',
                agent: 'production',
                title: "Production-Agent Input (Runde {$round}/{$maxRounds})",
                details: [
                    'model' => $prodAgent->getModelInfo(),
                    'round' => $round,
                    'user_prompt' => $prodPrompt,
                    'system_prompt' => $prodAgent->getFullInstructions(),
                    'topic' => $topic,
                    'strategy' => $this->strategyKey,
                    'persona_id' => $this->personaId,
                    'has_previous_feedback' => $round > 1,
                ]
            );

            $prodResponse = $prodAgent->chat(new UserMessage($prodPrompt))->getMessage();
            $prodText = $prodResponse->getContent() ?? '';
            $prodRes = ['round' => $round, 'output' => $prodText];

            $this->recordEvent(
                type: 'llm_output',
                agent: 'production',
                title: "Production-Agent Entwurf erstellt (Runde {$round})",
                details: [
                    'round' => $round,
                    'output' => $prodText,
                ]
            );

            if (!$includeReview) {
                break;
            }

            // Hand-off Production -> Review
            $this->recordCommunication(
                from: 'production',
                to: 'review',
                title: "Übergabe: Entwurf (Runde {$round}) an Review-Agent eingereicht",
                payload: $prodText,
                summary: "Content-Entwurf zur Überprüfung auf Brand Voice, Funnel und ICP-Fit übergeben."
            );

            $this->currentPhase = 'review';
            $revPrompt = "Prüfe den folgenden produzierten Content (Runde {$round}/{$maxRounds}):\n\n{$prodText}\n\nKriterien: Brand Voice, Funnel-Dramaturgie, ICP-Schmerzpunkt, Tonalität.\nGib am Ende ein klares verdict: 'pass' oder 'fail' mit Begründung.";

            $this->recordEvent(
                type: 'llm_input',
                agent: 'review',
                title: "Review-Agent Input (Runde {$round})",
                details: [
                    'model' => $revAgent->getModelInfo(),
                    'round' => $round,
                    'user_prompt' => $revPrompt,
                    'system_prompt' => $revAgent->getFullInstructions(),
                    'topic' => $topic,
                    'strategy' => $this->strategyKey,
                    'persona_id' => $this->personaId,
                ]
            );

            $revResponse = $revAgent->chat(new UserMessage($revPrompt))->getMessage();
            $revText = $revResponse->getContent() ?? '';

            $isPass = str_contains(strtolower($revText), 'verdict: pass')
                || str_contains(strtolower($revText), 'verdict: "pass"')
                || str_contains(strtolower($revText), '"pass"');

            $feedback = $revText;
            $verdict = $isPass ? 'pass' : 'fail';

            $revRes = [
                'round' => $round,
                'verdict' => $verdict,
                'output' => $revText,
            ];

            $this->recordEvent(
                type: 'llm_output',
                agent: 'review',
                title: "Review-Agent Urteil: " . strtoupper($verdict) . " (Runde {$round})",
                details: [
                    'round' => $round,
                    'verdict' => $verdict,
                    'output' => $revText,
                ]
            );

            // Loop recording
            if (!$isPass && $round < $maxRounds) {
                $loopInfo = [
                    'round' => $round,
                    'max_rounds' => $maxRounds,
                    'from' => 'review',
                    'to' => 'production',
                    'verdict' => 'fail',
                    'feedback' => $feedback,
                    'timestamp' => now()->toIso8601String(),
                ];
                $this->loops[] = $loopInfo;

                $this->recordEvent(
                    type: 'loop',
                    agent: 'review->production',
                    title: "🔄 Feedback-Loop ausgelöst (Runde {$round}/{$maxRounds}): Review fordert Überarbeitung",
                    details: $loopInfo
                );

                $this->recordCommunication(
                    from: 'review',
                    to: 'production',
                    title: "Rückmeldung: Review-Kritik an Production zurückgespielt",
                    payload: $feedback,
                    summary: "Review abgelehnt (verdict: fail). Feedback wird in Runde " . ($round + 1) . " eingearbeitet."
                );
            } elseif ($isPass) {
                $loopInfo = [
                    'round' => $round,
                    'verdict' => 'pass',
                    'summary' => 'Qualitätsprüfung erfolgreich bestanden.',
                    'timestamp' => now()->toIso8601String(),
                ];
                $this->loops[] = $loopInfo;

                $this->recordEvent(
                    type: 'loop',
                    agent: 'review',
                    title: "✅ Review erfolgreich bestanden (verdict: pass)",
                    details: $loopInfo
                );
            } else {
                $loopInfo = [
                    'round' => $round,
                    'max_rounds' => $maxRounds,
                    'verdict' => 'max_rounds_reached',
                    'summary' => "Maximale Rundenanzahl ({$maxRounds}) erreicht.",
                    'timestamp' => now()->toIso8601String(),
                ];
                $this->loops[] = $loopInfo;

                $this->recordEvent(
                    type: 'loop',
                    agent: 'review',
                    title: "⚠️ Max. Runden ({$maxRounds}) erreicht – Workflow abgeschlossen",
                    details: $loopInfo
                );
            }

        } while (!$isPass && $round < $maxRounds);

        return [$prodRes, $revRes];
    }

    private function recordToolCall(array $callInfo): void
    {
        $this->recordEvent(
            type: 'tool_call',
            agent: $this->currentPhase,
            title: "Tool: {$callInfo['tool']}()",
            details: [
                'tool' => $callInfo['tool'],
                'input' => $callInfo['input'] ?? [],
                'output' => $callInfo['output'] ?? null,
                'duration_ms' => $callInfo['duration_ms'] ?? 0,
                'error' => $callInfo['error'] ?? null,
            ]
        );
    }

    private function recordCommunication(string $from, string $to, string $title, string $payload, string $summary): void
    {
        $this->recordEvent(
            type: 'communication',
            agent: "{$from}->{$to}",
            title: $title,
            details: [
                'from' => $from,
                'to' => $to,
                'summary' => $summary,
                'payload' => $payload,
            ]
        );
    }

    private function recordEvent(string $type, string $agent, string $title, array $details): void
    {
        $this->eventCounter++;
        $event = [
            'id' => 'evt_' . $this->eventCounter,
            'type' => $type, // llm_input | tool_call | llm_output | communication | loop
            'agent' => $agent,
            'title' => $title,
            'details' => $details,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->timeline[] = $event;

        // Also add to steps for phase-specific filtering
        $this->steps[] = [
            'id' => $event['id'],
            'agent' => explode('->', $agent)[0],
            'type' => $type,
            'title' => $title,
            'tool' => $details['tool'] ?? null,
            'content' => $details['output'] ?? $details['prompt'] ?? $details['payload'] ?? null,
            'result' => $details['output'] ?? null,
            'raw' => $details,
            'timestamp' => $event['timestamp'],
        ];

        // Persist trace incrementally so frontend polling sees real-time progress
        if ($this->run) {
            $trace = [
                'strategy' => $this->strategyKey,
                'test_mode' => $this->testMode,
                'timeline' => $this->timeline,
                'loops' => $this->loops,
                'proposed_angles' => array_values($this->toolkit->getProposedAngles()),
                'proposed_sources' => array_values($this->toolkit->getProposedSources()),
                'steps' => $this->steps,
                'updated_at' => now()->toIso8601String(),
            ];
            $this->run->update(['trace' => json_encode($trace, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function updateRunStep(string $phase, string $status, string $message): void
    {
        if (!$this->run) return;
        $trace = json_decode($this->run->trace ?? '{}', true) ?: [];
        $steps = $trace['steps'] ?? [];
        $steps[] = [
            'agent' => $phase,
            'status' => $status,
            'message' => mb_substr($message, 0, 500),
            'timestamp' => now()->toIso8601String(),
        ];
        $trace['steps'] = $steps;
        $this->run->update(['trace' => json_encode($trace, JSON_UNESCAPED_UNICODE)]);
    }

    private function isCancelled(): bool
    {
        if (!$this->run) return false;
        return $this->run->fresh()?->status === 'cancelled';
    }

    private function saveFullTrace(string $status, string $output, int $duration, int $exitCode, array $phases): void
    {
        if (!$this->run) return;

        $trace = [
            'strategy' => $this->strategyKey,
            'phases' => $phases,
            'test_mode' => $this->testMode,
            'timeline' => $this->timeline,
            'loops' => $this->loops,
            'proposed_angles' => array_values($this->toolkit->getProposedAngles()),
            'proposed_sources' => array_values($this->toolkit->getProposedSources()),
            'steps' => $this->steps,
            'completed_at' => now()->toIso8601String(),
        ];

        $this->run->update([
            'status' => $status,
            'output' => $output,
            'duration_ms' => $duration,
            'exit_code' => $exitCode,
            'trace' => json_encode($trace, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function formatFinalOutput(array $logs): string
    {
        $lines = ["=== NEURON AI MULTI-AGENT WORKFLOW ERGEBNIS ===\n"];

        if (!empty($logs['research'])) {
            $lines[] = "## 🔍 PHASE 1: RESEARCH\n" . $logs['research']['output'] . "\n";
        }
        if (!empty($logs['angle'])) {
            $lines[] = "## 🎯 PHASE 2: ANGLE RANKING\n" . $logs['angle']['output'] . "\n";
        }
        if (!empty($logs['production'])) {
            $lines[] = "## ✍️ PHASE 3: PRODUCTION\n" . $logs['production']['output'] . "\n";
        }
        if (!empty($logs['review'])) {
            $verdict = ($logs['review']['verdict'] ?? '') === 'pass' ? '✅ PASS' : '⚠️ REVIEW FEEDBACK';
            $lines[] = "## ✅ PHASE 4: REVIEW ({$verdict})\n" . $logs['review']['output'] . "\n";
        }

        return implode("\n", $lines);
    }
}
