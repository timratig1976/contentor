<?php

namespace App\Services\Agents;

use App\Services\LlmService;

/**
 * Core tool-call loop — replaces Haystack's Agent class.
 *
 * Sends messages to the LLM, executes any tool calls it requests,
 * appends results, and repeats until the LLM stops calling tools
 * or $maxIterations is reached.
 */
class AgentOrchestrator
{
    private const MAX_ITERATIONS = 15;
    private const MAX_TOOL_RESULT_CHARS = 3000;

    public function __construct(
        private LlmService $llm,
    ) {}

    /**
     * Run the agent loop.
     *
     * @param  string  $agent         Agent key for LlmService config (research|angle|production|review|coordinator)
     * @param  string  $systemPrompt  Full system prompt (strategy context already injected)
     * @param  array<int,array{role:string,content:string}>  $messages
     * @param  array<int,array{type:string,function:array{name:string,description:string,parameters:array}}>  $tools
     * @return array{text:string, iterations:int, tool_calls:int, status:string, error:?string}
     */
    public function run(string $agent, string $systemPrompt, array $messages, array $tools, ToolRegistry $registry): array
    {
        $iterations = 0;
        $toolCallCount = 0;

        while ($iterations < self::MAX_ITERATIONS) {
            $iterations++;

            $result = $this->llm->chatWithTools($agent, $systemPrompt, $messages, $tools);

            if ($result['status'] === 'error') {
                return [
                    'text' => $result['error'] ?? 'Unknown error',
                    'iterations' => $iterations,
                    'tool_calls' => $toolCallCount,
                    'status' => 'error',
                    'error' => $result['error'],
                ];
            }

            $raw = $result['raw'];
            $choice = $raw['choices'][0] ?? [];
            $message = $choice['message'] ?? [];
            $toolCalls = $message['tool_calls'] ?? [];

            // No tool calls → done, return final text
            if (empty($toolCalls)) {
                return [
                    'text' => $result['text'] ?? '',
                    'iterations' => $iterations,
                    'tool_calls' => $toolCallCount,
                    'status' => 'success',
                    'error' => null,
                ];
            }

            // Append assistant message (with tool calls) to history.
            // Sanitize: only keep fields the API expects back —
            // extra fields (provider_specific_fields, function_call etc.)
            // cause unexpected behaviour on subsequent calls.
            $assistantMessage = ['role' => 'assistant'];
            if (!empty($message['content'])) {
                $assistantMessage['content'] = $message['content'];
            }
            if (!empty($message['tool_calls'])) {
                $assistantMessage['tool_calls'] = array_map(
                    fn($tc) => [
                        'id' => $tc['id'] ?? '',
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['function']['name'] ?? '',
                            'arguments' => $tc['function']['arguments'] ?? '{}',
                        ],
                    ],
                    $message['tool_calls']
                );
            }
            $messages[] = $assistantMessage;

            // Execute each tool call and append results
            foreach ($toolCalls as $toolCall) {
                $toolCallCount++;
                $fn = $toolCall['function'] ?? [];
                $name = $fn['name'] ?? '';
                $arguments = json_decode($fn['arguments'] ?? '{}', true) ?? [];

                $output = $registry->execute($name, $arguments);

                // Compact large tool results before encoding —
                // truncate list fields rather than raw JSON to keep it valid.
                if (is_array($output)) {
                    $output = $this->compactToolResult($output);
                }
                $content = is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_UNICODE);
                if (mb_strlen($content) > self::MAX_TOOL_RESULT_CHARS) {
                    $content = mb_substr($content, 0, self::MAX_TOOL_RESULT_CHARS)
                        . '… [truncated]';
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? '',
                    'content' => $content,
                ];
            }
        }

        return [
            'text' => 'Max iterations (' . self::MAX_ITERATIONS . ') reached.',
            'iterations' => $iterations,
            'tool_calls' => $toolCallCount,
            'status' => 'error',
            'error' => 'Agent exceeded maximum iterations.',
        ];
    }

    /**
     * Compact large tool results before JSON encoding.
     * Limits list fields (results, items, data, angles etc.) to the first
     * few entries and shortens long content strings so the result stays
     * valid JSON and fits within API context limits.
     */
    private function compactToolResult(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return is_array($value) ? '[...]' : $value;
        }

        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500) . '…';
        }

        if (! is_array($value)) {
            return $value;
        }

        // List of items (numeric keys)
        if (array_is_list($value)) {
            $limited = array_slice($value, 0, 3);
            $result = array_map(fn($v) => $this->compactToolResult($v, $depth + 1), $limited);
            if (count($value) > 3) {
                $result[] = sprintf('[... %d more items]', count($value) - 3);
            }
            return $result;
        }

        // Associative array — compact each value
        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = $this->compactToolResult($v, $depth + 1);
        }
        return $result;
    }
}
