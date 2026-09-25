<?php

namespace App\Neuron\Agents;

use App\Models\Setting;
use App\Neuron\Providers\EdenAIProvider;
use App\Neuron\Tools\ContentorToolkit;
use App\Services\AgentContextService;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;

/**
 * BaseContentorAgent — Base class for all Contentor agents using Neuron AI.
 * Automatically resolves Provider/Model from the Settings table and
 * injects the strategy context into the instructions.
 */
abstract class BaseContentorAgent extends Agent
{
    public string $strategyKey = 'viscale';
    public ?int $personaId = null;
    public ?ContentorToolkit $toolkit = null;

    abstract protected function agentKey(): string;
    abstract protected function defaultInstructions(): string;

    public function withContext(string $strategyKey = 'viscale', ?int $personaId = null, ?ContentorToolkit $toolkit = null): static
    {
        $this->strategyKey = $strategyKey;
        $this->personaId = $personaId;
        $this->toolkit = $toolkit;
        $this->toolMaxRuns(6); // Circuit-Breaker: Verhindert Endlos-Schleifen von Tool-Calls
        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        $models = Setting::where('key', 'agent_models')->first()?->value ?? [];
        $cfg = $models[$this->agentKey()] ?? [];

        $provider = $cfg['provider'] ?? 'anthropic';
        $model = $cfg['model'] ?? 'anthropic/claude-sonnet-4-6';
        $temperature = (float) ($cfg['temperature'] ?? 0.5);
        $maxTokens = (int) ($cfg['max_tokens'] ?? 4000);

        // Direct keys take precedence if configured
        $directAnthropicKey = env('ANTHROPIC_API_KEY');
        $directOpenAiKey = env('OPENAI_API_KEY');

        if ($provider === 'anthropic' && !empty($directAnthropicKey)) {
            $cleanModel = ltrim(str_replace('anthropic/', '', $model), '/');
            return new Anthropic(
                key: $directAnthropicKey,
                model: $cleanModel,
                parameters: ['temperature' => $temperature, 'max_tokens' => $maxTokens]
            );
        }

        if ($provider === 'openai' && !empty($directOpenAiKey)) {
            $cleanModel = ltrim(str_replace('openai/', '', $model), '/');
            return new OpenAI(
                key: $directOpenAiKey,
                model: $cleanModel,
                parameters: ['temperature' => $temperature, 'max_tokens' => $maxTokens]
            );
        }

        // Default: Use EdenAI via our EdenAIProvider with configured EdenAI key
        $edenKey = Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? '';
        $fullModel = str_contains($model, '/') ? $model : "{$provider}/{$model}";

        return new EdenAIProvider(
            key: $edenKey,
            model: $fullModel,
            parameters: [
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]
        );
    }

    protected function instructions(): string
    {
        // 1. Custom Prompt aus DB (editierbar unter /agents)
        $dbPrompt = Setting::where('key', 'agent_prompts')->first()?->value[$this->agentKey()] ?? null;
        $base = $dbPrompt ?? $this->defaultInstructions();

        // 2. Strategie-Kontext injizieren:
        //    Stufe 1 (Research & Angle): Reiner strategischer Kontext (ICPs, Pains, Lücken, Zitate)
        //    Stufe 2 (Production & Review): Inkl. Absender-Stimme, Brand Voice & Copywriting-Regeln
        $stage = in_array($this->agentKey(), ['research', 'angle']) ? 'angle' : 'copy';
        $contextService = app(AgentContextService::class);
        $ctx = $contextService->build($this->strategyKey, $this->personaId, $stage);

        return $base . "\n\n---\n## STRATEGIE-KONTEXT\n" . $ctx;
    }

    public function getFullInstructions(): string
    {
        return $this->instructions();
    }

    public function getModelInfo(): array
    {
        $models = Setting::where('key', 'agent_models')->first()?->value ?? [];
        $cfg = $models[$this->agentKey()] ?? [];
        return [
            'provider'    => $cfg['provider'] ?? 'anthropic',
            'model'       => $cfg['model'] ?? 'anthropic/claude-sonnet-4-6',
            'temperature' => (float) ($cfg['temperature'] ?? 0.5),
            'max_tokens'  => (int) ($cfg['max_tokens'] ?? 4000),
        ];
    }

    protected function tools(): array
    {
        return ($this->toolkit ?? ContentorToolkit::make($this->strategyKey))->tools();
    }
}
