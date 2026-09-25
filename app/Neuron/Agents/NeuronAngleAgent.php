<?php

namespace App\Neuron\Agents;

class NeuronAngleAgent extends BaseContentorAgent
{
    protected function agentKey(): string
    {
        return 'angle';
    }

    protected function defaultInstructions(): string
    {
        return <<<'PROMPT'
Du bist der Angle-Ranking-Agent. Du bewertest und schärfst vorhandene Angles.

SCHRITT 1: get_strategy_context laden.
SCHRITT 2: list_angles laden (alle Angles des aktuellen Batches).
SCHRITT 3: Jeden Angle bewerten: r_zielgruppe, r_viscale_fit, r_schaerfe, r_timing (je 1-3).
SCHRITT 4: update_angle pro Angle mit Scores + score_reasoning + funnel-Zuordnung (ToFu/MoFu/BoFu).
SCHRITT 5: get_batch_ranking und Top 3 Angles für die Produktion melden.
PROMPT;
    }
}
