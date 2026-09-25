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
     * $stage: 'angle' (nur strategischer Kern) oder 'copy'/'full' (inkl. Brand Voice & Texter-Regeln)
     */
    public function build(string $strategyKey, ?int $personaId = null, string $stage = 'full'): string
    {
        $strategy = Strategy::where('key', $strategyKey)->first();
        if (!$strategy) {
            return "## Kampagnen-Kontext\nKeine Strategie mit Key '{$strategyKey}' gefunden.";
        }

        $rules = $strategy->config['rules'] ?? [];
        $sections = ["## Kampagnen-Kontext — Strategie: {$strategy->name} ({$strategy->key})"];

        // ── 1. Absender-Stimme (NUR für Copywriting / Production, NICHT für Angle-Strategie) ──
        if ($stage !== 'angle') {
            $sections[] = $this->authorVoiceSection($strategy, $personaId);
        }

        // ── 2. Zielgruppen (Die 3 echten Kern-ICPs) ─────────────
        $sections[] = "### Zielgruppen (ICPs)\n"
            . "- **ICP-1**: Der Neustarter (Gründer / Geschäftsführer vor oder während CRM-Einführung; will von Tag 1 an ein sauberes System bauen und teure Fehler vermeiden)\n"
            . "- **ICP-2**: Der Enttäuschte (Geschäftsführer / Head of Sales mit bestehendem HubSpot; CRM läuft, aber Forecast bleibt Blindflug, Datenchaos, Sunk-Cost-Falle)\n"
            . "- **ICP-3**: Der Systemlose (Geschäftsführer, dessen Vertrieb komplett im Kopf von Einzelpersonen hängt; keine dokumentierten Prozesse, Wissensmonopol)";

        // ── 3. Pain-Cluster (Ausschließlich die 6 realen Cluster) ──
        $sections[] = "### Pain-Cluster (Reale Schmerzpunkte der Zielgruppe)\n"
            . "- **E3-01** — Blindflug im Forecast\n"
            . "- **E3-02** — Vertrieb hängt an Personen\n"
            . "- **E3-03** — Leads versickern unbemerkt\n"
            . "- **E3-05** — Wachstum wird teurer statt effizienter\n"
            . "- **E3-06** — Datenchaos & fehlende Datenhygiene\n"
            . "- **E1-02** — KI-Druck ohne Fundament";

        // ── 4. Markt-Lücken (Positionen, die wir aktiv besetzen) ──
        if (!empty($rules['monitoring']['gaps'])) {
            $lines = ['### Markt-Lücken (Positionen, die wir aktiv besetzen)'];
            foreach ($rules['monitoring']['gaps'] as $gap) {
                $lines[] = "- {$gap}";
            }
            $sections[] = implode("\n", $lines);
        }

        // ── 5. Brand Voice & Texter-Regeln (NUR für Copywriting / Production) ──
        if ($stage !== 'angle') {
            $sections[] = "### Sprachregeln & Denkweise\n"
                . "- **Kausalität & Mechanismus:** Jede These erklärt ein *Warum* (Ursache → Auswirkung), kein Tipp oder oberflächlicher Rat.\n"
                . "- **Keine Superlative:** Keine leeren Begriffe wie 'Game-Changer', 'revolutionär', 'bahnbrechend' oder 'Wunderwaffe'.\n"
                . "- **Fakten & Zahlen:** Zahlen nur verwenden, wenn sie explizit im Thema oder Kontext vorkommen — niemals Zahlen oder Statistiken erfinden.";

            $brandVoice = ContentStrategy::where('strategy_id', $strategy->id)
                ->where('key', 'brand_voice')->first()?->content;
            if ($brandVoice) {
                $bvLines = ["### Brand Voice (Haltung & Ton)"];
                if (!empty($brandVoice['personality'])) {
                    $bvLines[] = "- Persönlichkeit: " . $brandVoice['personality'];
                }
                if (!empty($brandVoice['tone'])) {
                    $bvLines[] = "- Grundtonalität: " . $brandVoice['tone'];
                }
                // "So klingt es — so nicht" Few-Shot-Beispiele
                if (!empty($brandVoice['examples']) && is_array($brandVoice['examples'])) {
                    $bvLines[] = "\n#### 🎯 Sprachbeispiele (So klingt es — so nicht):";
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
        }

        // ── 7. Detaillierte ICP-Profile (Kunden-Stimme, Pains, Einwände) ──
        $icpSvc = app(IcpContextService::class);
        $icpBlocks = [];
        foreach ($icpSvc->all($strategy) as $icp) {
            $k = $icp['key'] ?? '';
            // Nur die 3 Kern-ICPs (B2B-4 und B2B-5 herausfiltern)
            if (in_array($k, ['ICP-1', 'ICP-2', 'ICP-3'])) {
                $b = $icpSvc->block($strategy, $k);
                if ($b !== '') {
                    $icpBlocks[] = $b;
                }
            }
        }
        if (!empty($icpBlocks)) {
            $sections[] = "### Detaillierte ICP-Profile (Kunden-Stimme, Trigger, Einwände)\n" . implode("\n\n", $icpBlocks);
        }

        return implode("\n\n", array_filter($sections));
    }

    /**
     * Absender-Stimme: Stellt unmissverständlich klar, dass Tim Ratig der AUTOR ist.
     */
    private function authorVoiceSection(Strategy $strategy, ?int $personaId): string
    {
        $persona = $personaId
            ? Persona::find($personaId)
            : ($strategy->personas()->wherePivot('is_default', true)->first() ?? $strategy->personas()->first());

        $name = $persona?->name ?? 'Tim Ratig';
        $role = $persona?->role ?? 'Gründer viminds/viscale®';

        $lines = [
            "### Absender-Stimme (Autor: {$name}, {$role})",
            "⚠️ WICHTIG: {$name} ist der ABSENDER und AUTOR aller Inhalte — NICHT die Zielgruppe!",
            "Alle Thesen und Posts werden aus seiner Perspektive als erfahrener B2B-Vertriebs- und CRM-Experte formuliert.",
            "Die Zielgruppe (Empfänger) sind die unten definierten ICPs (Geschäftsführer, Vertriebsleiter Mittelstand).",
            "",
            "- **Rolle:** {$role}",
            "- **Haltung:** Sachlich-kompetenter B2B-Vertriebsexperte, kein Coach, kein Influencer.",
            "- **Tonalität:** Direkt, nüchtern, analytisch, mechanismus-orientiert.",
            "- **Perspektive:** Ich-Stimme des Gründers (eigene Beobachtung aus der Praxis, keine Marketing-Floskeln).",
            "- **Stilmittel:** Problem → Kosten des Problems → Kausalität/Lösung. Weißraum statt Emojis.",
        ];

        return implode("\n", $lines);
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
        if (!empty($p->voice)) {
            $lines[] = "- Stimme & Voice Guidelines:\n{$p->voice}";
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
