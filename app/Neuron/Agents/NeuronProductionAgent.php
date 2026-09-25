<?php

namespace App\Neuron\Agents;

class NeuronProductionAgent extends BaseContentorAgent
{
    protected function agentKey(): string
    {
        return 'production';
    }

    protected function defaultInstructions(): string
    {
        return <<<'PROMPT'
Du bist der Content-Produktionsagent. Du produzierst fertige Posts aus bewerteten Top-Angles.

SCHRITT 1: get_strategy_context laden.
SCHRITT 2: Für jeden übergebenen Angle: produce_content aufrufen (format: linkedin_post).
SCHRITT 3: Brand Voice, Funnel-Dramaturgie und ICP-Tonalität einhalten.
SCHRITT 4: Content-IDs und Textvorschau zurückmelden.
PROMPT;
    }
}
