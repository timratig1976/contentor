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

        // ── Content-Keywords & Themenfokus (Strategie-Ebene) ──
        $contentStrategy = ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'content_strategy')->first()?->content;
        $kwLines = ['### Content-Schwerpunkte'];
        if (!empty($contentStrategy['goals'])) {
            $kwLines[] = '- Strategische Ziele: ' . $contentStrategy['goals'];
        }
        if (!empty($contentStrategy['keywords'])) {
            $kwLines[] = '- Kern-Keywords: ' . implode(', ', (array) $contentStrategy['keywords']);
        }
        if (!empty($contentStrategy['topic_focus'])) {
            $kwLines[] = '- Themenfokus: ' . implode(', ', (array) $contentStrategy['topic_focus']);
        }
        if (count($kwLines) > 1) {
            $sections[] = implode("\n", $kwLines);
        }

        // ── Themencluster (Pillars mit Zielen, Tonalität & Unterthemen) ──
        if (!empty($contentStrategy['pillars'])) {
            $pillarLines = ["### Themencluster (Content Pillars & Subtopics)\nOrdne jeden Angle/Post einem dieser Themencluster und Unterthemen zu:"];
            foreach ($contentStrategy['pillars'] as $p) {
                $name = $p['name'] ?? 'Unbenannt';
                $desc = !empty($p['description']) ? " — {$p['description']}" : '';
                $pillarLines[] = "#### 🏛️ Cluster: **{$name}**{$desc}";
                if (!empty($p['goal'])) {
                    $pillarLines[] = "  - **Ziel des Clusters:** {$p['goal']}";
                }
                if (!empty($p['tone'])) {
                    $pillarLines[] = "  - **Spezifische Tonalität/Haltung:** {$p['tone']}";
                }
                if (!empty($p['subtopics']) && is_array($p['subtopics'])) {
                    $subs = array_filter(array_map('trim', $p['subtopics']));
                    if (!empty($subs)) {
                        $pillarLines[] = "  - **Konkrete Unterthemen (Subtopics):** " . implode(', ', $subs);
                    }
                }
                if (!empty($p['icp_focus']) && is_array($p['icp_focus'])) {
                    $pillarLines[] = "  - **Fokus-ICPs:** " . implode(', ', $p['icp_focus']);
                }
            }
            $sections[] = implode("\n", $pillarLines);
        }

        // ── Brand Voice aus ContentStrategy ─────────────────
        $brandVoice = ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'brand_voice')->first()?->content;
        if ($brandVoice) {
            $bvLines = ["### Brand Voice"];
            if (!empty($brandVoice['personality'])) {
                $bvLines[] = "- Persönlichkeit: " . $brandVoice['personality'];
            }
            if (!empty($brandVoice['tone'])) {
                $bvLines[] = "- Grundtonalität: " . $brandVoice['tone'];
            }
            if (!empty($brandVoice['must'])) {
                $bvLines[] = "- Pflicht-Elemente (immer beachten): " . implode(', ', (array) $brandVoice['must']);
            }
            if (!empty($brandVoice['never'])) {
                $bvLines[] = "- Verboten / No-Gos: " . implode(', ', (array) $brandVoice['never']);
            }
            // "So klingt es — so nicht" Few-Shot-Beispiele
            if (!empty($brandVoice['examples']) && is_array($brandVoice['examples'])) {
                $bvLines[] = "\n#### 🎯 Konkrete Sprachbeispiele (So klingt es — so nicht):";
                foreach ($brandVoice['examples'] as $ex) {
                    $ctx = !empty($ex['context']) ? "[{$ex['context']}] " : '';
                    if (!empty($ex['bad'])) {
                        $bvLines[] = "🚫 NICHT SO: {$ctx}\"{$ex['bad']}\"";
                    }
                    if (!empty($ex['good'])) {
                        $bvLines[] = "✅ SONDERN SO: {$ctx}\"{$ex['good']}\"";
                    }
                }
            }
            $sections[] = implode("\n", $bvLines);
        }

        // ── Reichhaltige ICP-Definitionen (Pains, Gains, Triggers, Customer Voice) ──
        $icpSvc = app(IcpContextService::class);
        $icpBlocks = [];
        foreach ($icpSvc->all($strategy) as $icp) {
            if (!empty($icp['key'])) {
                $b = $icpSvc->block($strategy, $icp['key']);
                if ($b !== '') {
                    $icpBlocks[] = $b;
                }
            }
        }
        if (!empty($icpBlocks)) {
            $sections[] = "### Detaillierte ICP-Profile (Kunden-Stimme, Pains, Einwände)\n" . implode("\n\n", $icpBlocks);
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
        if (!empty($p->perspective)) {
            $lines[] = "- Perspektive: {$p->perspective}";
        }
        if (!empty($p->emoji_usage)) {
            $lines[] = "- Emoji-Nutzung: {$p->emoji_usage}";
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
