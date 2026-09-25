<?php

namespace App\Jobs;

use App\Models\WorkflowRun;
use App\Neuron\Workflows\ContentWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExecuteContentWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes timeout for multi-agent LLM workflow

    public function __construct(
        public int $runId,
        public string $strategyKey,
        public string $input,
        public array $phases,
        public bool $testMode = true,
        public ?int $personaId = null
    ) {}

    public function handle(): void
    {
        $run = WorkflowRun::find($this->runId);
        if (!$run) return;

        try {
            $workflow = new ContentWorkflow(
                $this->strategyKey,
                $this->personaId,
                $run,
                $this->testMode
            );
            $workflow->run($this->input, $this->phases);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'error',
                'output' => 'Fehler im Hintergrund-Workflow: ' . $e->getMessage(),
            ]);
        }
    }
}
