<?php

namespace App\Services;

use App\Models\AgentLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Zentraler LLM-Zugriff über EdenAI v3 (OpenAI-kompatibel).
 *
 * Bündelt die Konfiguration aller Agenten (Modell, Temperature, Max Tokens,
 * Reasoning-Effort) an einer Stelle und wendet Reasoning-Parameter
 * provider-spezifisch korrekt an:
 *
 * - Anthropic (Claude 4.6, adaptives Thinking):  output_config.effort
 * - OpenAI Reasoning-Modelle (o-Serie, gpt-5):    reasoning_effort
 * - andere Modelle:                               kein Reasoning-Parameter
 *
 * Der Reasoning-Effort steuert, WIE VIEL das Modell intern nachdenkt.
 * Thinking-Tokens zählen bei Claude in das max_tokens-Budget hinein.
 */
class LlmService
{
    private const ENDPOINT = 'https://api.edenai.run/v3/chat/completions';
    private const EFFORT_VALUES = ['none', 'low', 'medium', 'high'];

    /**
     * Liefert die gespeicherte Modell-Konfiguration eines Agenten
     * (mit sicheren Defaults, falls nichts konfiguriert ist).
     *
     * @return array{provider:string,model:string,temperature:float,max_tokens:int,reasoning_effort:string}
     */
    public function configFor(string $agent): array
    {
        static $models = null;
        $models ??= Setting::where('key', 'agent_models')->first()?->value ?? [];

        $defaults = $this->defaultsFor($agent);
        $cfg = $models[$agent] ?? [];

        $effort = strtolower((string) ($cfg['reasoning_effort'] ?? $defaults['reasoning_effort']));
        if (! in_array($effort, self::EFFORT_VALUES, true)) {
            $effort = $defaults['reasoning_effort'];
        }

        return [
            'provider' => $cfg['provider'] ?? $defaults['provider'],
            'model' => $cfg['model'] ?? $defaults['model'],
            'temperature' => (float) ($cfg['temperature'] ?? $defaults['temperature']),
            'max_tokens' => (int) ($cfg['max_tokens'] ?? $defaults['max_tokens']),
            'reasoning_effort' => $effort,
        ];
    }

    /**
     * Führt einen Chat-Completion-Call aus und gibt den Antworttext zurück.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @param array{max_tokens?:int,temperature?:int|float,reasoning_effort?:string,timeout?:int,agent_log?:string} $overrides
     * @return array{text:?string, usage:array, cost:?float, status:string, error:?string, raw:array}
     */
    public function chat(string $agent, string $systemPrompt, array $messages, array $overrides = []): array
    {
        $cfg = $this->configFor($agent);

        $provider = $overrides['provider'] ?? $cfg['provider'];
        $model = $this->withProviderPrefix($overrides['model'] ?? $cfg['model'], $provider);
        $temperature = (float) ($overrides['temperature'] ?? $cfg['temperature']);
        $maxTokens = (int) ($overrides['max_tokens'] ?? $cfg['max_tokens']);
        $effort = strtolower((string) ($overrides['reasoning_effort'] ?? $cfg['reasoning_effort']));
        $timeout = (int) ($overrides['timeout'] ?? 120);

        $key = Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
        if (! $key) {
            return $this->fail('EdenAI-Key nicht konfiguriert (Einstellungen).', $agent, $provider, $model, $messages, $overrides);
        }

        $fullMessages = [];
        if ($systemPrompt !== '') {
            $fullMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($messages as $m) {
            $fullMessages[] = ['role' => $m['role'] ?? 'user', 'content' => $m['content'] ?? ''];
        }

        $body = [
            'model' => $model,
            'messages' => $fullMessages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        // Reasoning-Parameter provider-spezifisch anwenden
        $this->applyReasoning($body, $provider, $model, $effort);

        $start = microtime(true);
        $logAgent = $overrides['agent_log'] ?? $agent;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->timeout($timeout)->post(self::ENDPOINT, $body);

            $data = $response->json() ?? [];
            $duration = (int) ((microtime(true) - $start) * 1000);

            if ($response->failed()) {
                $err = $data['error']['message'] ?? $data['error'] ?? $data['message'] ?? $response->body();
                $this->log($logAgent, $provider, $model, $messages, 'HTTP ' . $response->status() . ': ' . (is_string($err) ? $err : json_encode($err)), 'error', null, $duration);
                return [
                    'text' => null, 'usage' => [], 'cost' => null,
                    'status' => 'error', 'error' => is_string($err) ? $err : json_encode($err), 'raw' => $data,
                ];
            }

            $text = $data['choices'][0]['message']['content'] ?? null;
            $usage = $data['usage'] ?? [];
            $cost = $data['cost'] ?? null;

            $this->log($logAgent, $provider, $model, $messages, (string) $text, $text ? 'success' : 'error', $usage['total_tokens'] ?? null, $duration);

            if (! $text) {
                $reason = $data['error']['message'] ?? $data['message'] ?? null;
                return [
                    'text' => null, 'usage' => $usage, 'cost' => $cost,
                    'status' => 'error',
                    'error' => 'EdenAI lieferte keine Antwort' . ($reason ? ': ' . (is_string($reason) ? $reason : json_encode($reason)) : ''),
                    'raw' => $data,
                ];
            }

            return ['text' => trim($text), 'usage' => $usage, 'cost' => $cost, 'status' => 'success', 'error' => null, 'raw' => $data];
        } catch (\Throwable $e) {
            $duration = (int) ((microtime(true) - $start) * 1000);
            $this->log($logAgent, $provider, $model, $messages, 'Exception: ' . $e->getMessage(), 'error', null, $duration);
            return [
                'text' => null, 'usage' => [], 'cost' => null,
                'status' => 'error', 'error' => $e->getMessage(), 'raw' => [],
            ];
        }
    }

    /**
     * Fügt den passenden Reasoning-Parameter zum Request-Body hinzu.
     * Wird nur gesetzt, wenn das jeweilige Modell/Provider das unterstützt.
     *
     * @param array<string,mixed> $body
     */
    private function applyReasoning(array &$body, string $provider, string $model, string $effort): void
    {
        // 'none' = Reasoning-Steuerung bewusst auslassen (Default des Anbieters)
        if ($effort === 'none' || $effort === '') {
            return;
        }

        if (str_contains($provider, 'anthropic')) {
            // Claude 4.6: adaptives Denken, Tiefe über output_config.effort
            $body['output_config'] = ['effort' => $effort];
            return;
        }

        if (str_contains($provider, 'openai') || str_contains($provider, 'azure')) {
            // reasoning_effort nur für echte Reasoning-Modelle (o-Serie, gpt-5)
            if ($this->isOpenAiReasoningModel($model)) {
                $body['reasoning_effort'] = $effort;
            }
            return;
        }

        // Google/Gemini & weitere: thinking_config (best-effort, wird ignoriert wenn unbekannt)
        if (str_contains($provider, 'google') || str_contains($provider, 'gemini')) {
            $budget = match ($effort) {
                'low' => 128,
                'medium' => 1024,
                'high' => 4096,
                default => null,
            };
            if ($budget !== null) {
                $body['thinking_config'] = ['thinking_budget' => $budget];
            }
        }
    }

    /**
     * Erkennt OpenAI-/Azure-Reasoning-Modelle, die reasoning_effort akzeptieren.
     * Klassische Modelle (gpt-4o, gpt-4-turbo) werden bewusst ausgeschlossen.
     */
    private function isOpenAiReasoningModel(string $model): bool
    {
        $m = strtolower($model);
        return (bool) preg_match('/\b(o1|o3|o4|gpt-5)/', $m);
    }

    /**
     * Modell-ID für EdenAI v3 normalisieren ("provider/modell").
     */
    public function withProviderPrefix(string $model, string $provider): string
    {
        $model = trim($model);
        if ($provider === '' || str_starts_with($model, $provider . '/')) {
            return $model;
        }
        return $provider . '/' . $model;
    }

    /**
     * Sinnvolle Defaults pro Agent (werden von DB-Werten überschrieben).
     *
     * @return array{provider:string,model:string,temperature:float,max_tokens:int,reasoning_effort:string}
     */
    private function defaultsFor(string $agent): array
    {
        return match ($agent) {
            'research' => [
                'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6',
                'temperature' => 0.7, 'max_tokens' => 4000, 'reasoning_effort' => 'medium',
            ],
            'angle' => [
                'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6',
                'temperature' => 0.5, 'max_tokens' => 8000, 'reasoning_effort' => 'medium',
            ],
            'production' => [
                'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6',
                'temperature' => 0.8, 'max_tokens' => 4000, 'reasoning_effort' => 'medium',
            ],
            'review' => [
                'provider' => 'openai', 'model' => 'gpt-4o',
                'temperature' => 0.3, 'max_tokens' => 3000, 'reasoning_effort' => 'low',
            ],
            'coordinator' => [
                'provider' => 'openai', 'model' => 'gpt-4o',
                'temperature' => 0.7, 'max_tokens' => 3000, 'reasoning_effort' => 'low',
            ],
            'assistant' => [
                'provider' => 'openai', 'model' => 'gpt-4o',
                'temperature' => 0.6, 'max_tokens' => 2000, 'reasoning_effort' => 'low',
            ],
            default => [
                'provider' => 'openai', 'model' => 'gpt-4o',
                'temperature' => 0.7, 'max_tokens' => 2000, 'reasoning_effort' => 'none',
            ],
        };
    }

    /**
     * Einheitliches Agent-Log (Tokens, Dauer, Status).
     *
     * @param array<int,array{role?:string,content?:string}> $messages
     */
    private function log(string $agent, string $provider, string $model, array $messages, string $output, string $status, ?int $tokens, int $durationMs): void
    {
        try {
            $input = '';
            foreach ($messages as $m) {
                $input .= ($m['content'] ?? '') . "\n";
            }

            AgentLog::create([
                'agent' => $agent,
                'provider' => $provider,
                'model' => $model,
                'input' => mb_substr($input, 0, 2000),
                'output' => mb_substr($output, 0, 2000),
                'status' => $status,
                'tokens_used' => $tokens,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable) {
            // Logging darf den Hauptpfad nie unterbrechen
        }
    }

    /**
     * @param array<int,array{role?:string,content?:string}> $messages
     * @param array<string,mixed> $overrides
     * @return array{text:null,usage:array,cost:null,status:string,error:?string,raw:array}
     */
    private function fail(string $error, string $agent, string $provider, string $model, array $messages, array $overrides): array
    {
        $this->log($overrides['agent_log'] ?? $agent, $provider, $model, $messages, 'Config-Fehler: ' . $error, 'error', null, 0);
        return ['text' => null, 'usage' => [], 'cost' => null, 'status' => 'error', 'error' => $error, 'raw' => []];
    }
}
