<?php

namespace App\Services\Agents;

/**
 * Maps tool names to PHP callables.
 * Each agent registers the tools it needs; the orchestrator executes them by name.
 */
class ToolRegistry
{
    /** @var array<string, callable> */
    private array $tools = [];

    public function register(string $name, callable $callable): void
    {
        $this->tools[$name] = $callable;
    }

    public function execute(string $name, array $arguments): mixed
    {
        if (! isset($this->tools[$name])) {
            return ['error' => "Tool '{$name}' not registered."];
        }

        try {
            return ($this->tools[$name])(...$arguments);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @return array<int,array> */
    public function definitions(): array
    {
        // Tool definitions are built per-agent in AgentTools.php,
        // so this is intentionally empty — definitions come from the agent class.
        return [];
    }
}
