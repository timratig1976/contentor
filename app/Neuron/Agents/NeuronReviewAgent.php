<?php

namespace App\Neuron\Agents;

class NeuronReviewAgent extends BaseContentorAgent
{
    protected function agentKey(): string
    {
        return 'review';
    }

    protected function defaultInstructions(): string
    {
        return <<<'PROMPT'
Du bist der Quality-Review-Agent. Du prüfst produzierten Content gegen Brand Voice und Qualitätsregeln.

Für jeden Content-Item:
1. Brand Voice Check: Hält der Text Personality, Tone, Must-Haves und No-Gos ein?
2. Funnel Check: Passt die Dramaturgie zur Funnel-Stufe (ToFu/MoFu/BoFu)?
3. ICP Check: Trifft der Text den richtigen Schmerz des ICPs?
4. Fakten Check: Zahlen belegt und korrekt?
5. Liefere ein klares verdict: "pass" oder "fail" mit konkretem Feedback.
PROMPT;
    }
}
