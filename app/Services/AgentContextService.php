<?php

namespace App\Services;

use App\Models\ContentStrategy;
use App\Models\Persona;
use App\Models\Strategy;

/**
 * Baut den Strategie-Kontext für Agent-Prompts.
 *
 * Stellt sicher, dass jeder Agent (Test-UI oder Python-Workflow) denselben
 * vollständigen Kontext erhält: Strategy-Regeln, Pain-Cluster, Tonalität,
 * Personas — optional auf eine einzelne Ziel-Persona eingeschränkt.
 */
class AgentContextService
{
    /**
     * Kontext-Block als Markdown-Text für einen System-Prompt.
     */
    public function build(string $strategyKey, ?int $personaId = null): string
    {
        $strategy = Strategy::where('key', $strategyKey)->first();
        if (!$strategy) {
            return "## Kampagnen-Kontext\nKeine Strategie mit Key '{$strategyKey}' gefunden.";
        }

        $rules = $strategy->config['rules'] ?? [];
        $sections = ["## Kampagnen-Kontext — Strategie: {$strategy->name} ({$strategy->key})"];

        // ── ICPs ────────────────────────────────────────────
        if (!empty($rules['icpKeys'])) {
            $sections[] = "### ICPs (Zielgruppen-Segmente)\n" . implode(', ', $rules['icpKeys']);
        }

        // ── Pain-Cluster ────────────────────────────────────
        if (!empty($rules['clusters'])) {
            $lines = ['### Pain-Cluster'];
            foreach ($rules['clusters'] as $c) {
                $lines[] = "- **{$c['code']}** — {$c['name']}";
            }
            $sections[] = implode("\n", $lines);
        }

        // ── Markt-Lücken (Monitoring) ───────────────────────
        if (!empty($rules['monitoring']['gaps'])) {
            $lines = ['### Markt-Lücken (diese Positionen besetzen wir)'];
            foreach ($rules['monitoring']['gaps'] as $gap) {
                $lines[] = "- {$gap}";
            }
            $sections[] = implode("\n", $lines);
        }

        // ── Tonalität & Verbote ─────────────────────────────
        $toneLines = ['### Tonalität & Regeln'];
        if (!empty($rules['toneLabel'])) {
            $toneLines[] = "- Regelwerk: {$rules['toneLabel']}";
        }
        if (!empty($rules['hashtags'])) {
            $toneLines[] = '- Hashtags: ' . implode(' ', $rules['hashtags']);
        }
        if (!empty($rules['signoff'])) {
            $toneLines[] = "- Sign-off: {$rules['signoff']}";
        }
        if (!empty($rules['forbiddenPatterns'])) {
            $toneLines[] = '- VERBOTEN (niemals verwenden): ' . implode(', ', $rules['forbiddenPatterns']);
        }
        if (count($toneLines) > 1) {
            $sections[] = implode("\n", $toneLines);
        }

        // ── Brand Voice aus ContentStrategy ─────────────────
        $brandVoice = ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'brand_voice')->first()?->content;
        if ($brandVoice) {
            $sections[] = "### Brand Voice\n" . $this->flatten($brandVoice);
        }

        // ── Personas ────────────────────────────────────────
        $sections[] = $this->personaSection($strategy, $personaId);

        return implode("\n\n", array_filter($sections));
    }

    /**
     * Persona-Abschnitt: entweder eine Ziel-Persona oder alle aktiven als Auswahl.
     */
    private function personaSection(Strategy $strategy, ?int $personaId): string
    {
        if ($personaId) {
            $persona = Persona::find($personaId);
            if (!$persona || !$persona->strategies->contains('id', $strategy->id)) {
                return "### Ziel-Persona\nPersona #{$personaId} nicht in Strategie '{$strategy->key}' gefunden.";
            }
            return "### Ziel-Persona (ALLE Inhalte zielen auf diese Persona)\n"
                . $this->describePersona($persona, $strategy->id);
        }

        // Globale Personas, die dieser Strategie zugeordnet sind
        $personas = $strategy->personas()->where('active', true)->get();
        if ($personas->isEmpty()) {
            return "### Personas\nKeine aktiven Personas hinterlegt. "
                . "Recherchiere breit für die oben genannten ICPs und ordne Angles selbst dem passendsten ICP zu.";
        }

        $lines = ['### Verfügbare Personas (wähle die passendste für das Thema)'];
        foreach ($personas as $p) {
            $lines[] = $this->describePersona($p, $strategy->id);
        }
        return implode("\n\n", $lines);
    }

    /**
     * Kompakte Persona-Beschreibung für den Prompt.
     * Themen-Cluster kommen aus dem Strategie-Mapping (nicht global).
     */
    private function describePersona(Persona $p, ?int $strategyId = null): string
    {
        $lines = ["#### Persona: {$p->name} (ID {$p->id})"];
        if ($p->role) {
            $lines[] = "- Rolle: {$p->role}";
        }
        $mapping = $p->strategyMapping($strategyId);
        if ($mapping && !empty($mapping['topic_clusters'])) {
            $lines[] = '- Themen (diese Strategie): ' . implode(', ', (array) $mapping['topic_clusters']);
        }
        if (!empty($p->core_statements)) {
            $lines[] = '- Core Statements: ' . implode(' | ', array_slice((array) $p->core_statements, 0, 5));
        }
        if (!empty($p->tonality)) {
            $lines[] = '- Tonalität: ' . $this->flatten($p->tonality);
        }
        if (!empty($p->positioning)) {
            $pos = is_array($p->positioning) ? $this->flatten($p->positioning) : $p->positioning;
            $lines[] = "- Positionierung: {$pos}";
        }
        if (!empty($p->content_attributes)) {
            $lines[] = '- Content-Attribute: ' . $this->flatten($p->content_attributes);
        }
        if (!empty($p->perspective)) {
            $lines[] = "- Perspektive: {$p->perspective}";
        }
        if (!empty($p->emoji_usage)) {
            $lines[] = "- Emoji-Nutzung: {$p->emoji_usage}";
        }
        if (!empty($p->max_sentence_length)) {
            $lines[] = "- Max. Satzlänge: {$p->max_sentence_length} Wörter";
        }
        if (!empty($p->forbidden_words)) {
            $lines[] = "- Verbotene Wörter: " . implode(', ', (array) $p->forbidden_words);
        }
        return implode("\n", $lines);
    }

    /**
     * Beliebige Config-Arrays flach als lesbaren Text ausgeben.
     */
    private function flatten(mixed $value, string $prefix = ''): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }
        if (!is_array($value)) {
            return json_encode($value);
        }
        $parts = [];
        $isList = array_keys($value) === range(0, count($value) - 1);
        foreach ($value as $k => $v) {
            $label = $isList ? '' : "{$k}: ";
            $parts[] = $label . $this->flatten($v, $k);
        }
        return implode($isList ? ', ' : '; ', $parts);
    }
}
