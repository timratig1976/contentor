<?php

namespace App\Services;

use App\Models\ContentStrategy;
use App\Models\Strategy;

/**
 * Reichhaltiger ICP-Kontext für Generierungs-Prompts.
 *
 * Liest die icp_definitions einer Strategie und baut für einen konkreten ICP
 * einen fokussierten Prompt-Block: Stimme-des-Kunden (echte Zitate als
 * Stil-Referenz), Statement-Typ-Gewichtung, Kauf-Trigger, Messaging-Kern
 * und Objection-Handling. Ziel: Angles/Content klingen wie der echte Kunde
 * und treffen die richtige Tonalität — statt generischer Marketing-Floskeln.
 */
class IcpContextService
{
    /**
     * Alle ICP-Definitionen einer Strategie.
     */
    public function all(Strategy $strategy): array
    {
        return ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'icp_definitions')
            ->first()?->content['icps'] ?? [];
    }

    /**
     * Eine ICP-Definition per Key (z. B. 'ICP-2').
     */
    public function find(Strategy $strategy, string $icpKey): ?array
    {
        foreach ($this->all($strategy) as $icp) {
            if (($icp['key'] ?? '') === $icpKey) {
                return $icp;
            }
        }

        return null;
    }

    /**
     * Prompt-Block für einen ICP (Markdown). Leer, wenn ICP unbekannt.
     *
     * @param  array<string,string>|null  $personaMap  optionale Buyer-Centre-Personas
     */
    public function block(Strategy $strategy, string $icpKey, ?array $personaMap = null): string
    {
        $icp = $this->find($strategy, $icpKey);
        if (! $icp) {
            return '';
        }

        $lines = ["### Ziel-ICP: {$icp['key']} · {$icp['name']}" . (! empty($icp['role']) ? " ({$icp['role']})" : '')];

        if (! empty($icp['description'])) {
            $lines[] = "Situation: {$icp['description']}";
        }

        // Messaging-Kern (strategischer Frame, der jeden Post steuert)
        if (! empty($icp['messaging_core'])) {
            $lines[] = 'Messaging-Frame (die Grundhaltung, die jeder Text transportieren soll): '
                . $this->line($icp['messaging_core']);
        }

        // Kauf-Trigger (Timing-/Relevanz-Signale → gute Angle-Hooks)
        if (! empty($icp['buying_triggers'])) {
            $lines[] = "Kauf-Trigger (aktuelle Auslöser, auf die Angles anspielen können):\n- "
                . implode("\n- ", (array) $icp['buying_triggers']);
        }

        // Statement-Typ-Gewichtung (welche Tonalität bei diesem ICP zieht)
        if (! empty($icp['statement_types'])) {
            $lines[] = 'Bevorzugte Statement-Typen für diesen ICP: '
                . implode(', ', (array) $icp['statement_types'])
                . ' — wähle statement_type bevorzugt daraus.';
        }

        // Stimme des Kunden (echte Zitate — Stil-Referenz, nicht kopieren)
        if (! empty($icp['voice_statements'])) {
            $lines[] = "So spricht dieser ICP wirklich (Stil-Referenz — Tonalität & Wortwahl übernehmen, Inhalt NICHT kopieren):\n"
                . $this->quotes($icp['voice_statements']);
        }

        // Objections + Rebuttals (für BoFu/Ad Copy)
        if (! empty($icp['objections'])) {
            $obj = [];
            foreach ((array) $icp['objections'] as $o) {
                if (is_array($o)) {
                    $obj[] = '„' . ($o['objection'] ?? '') . '" → ' . ($o['rebuttal'] ?? '');
                } else {
                    $obj[] = (string) $o;
                }
            }
            if ($obj) {
                $lines[] = "Typische Einwände & Entgegnungen:\n- " . implode("\n- ", $obj);
            }
        }

        // Buyer-Centre (Entscheider-Personas mit ihren Pains)
        $personas = $personaMap ?? ($icp['buyer_personas'] ?? []);
        if (! empty($personas)) {
            $p = [];
            foreach ((array) $personas as $bp) {
                if (is_array($bp)) {
                    $p[] = '- ' . ($bp['name'] ?? '?') . ' (' . ($bp['role'] ?? '') . '): '
                        . $this->line($bp['focus'] ?? ($bp['top_pains'] ?? ''));
                } else {
                    $p[] = '- ' . $bp;
                }
            }
            if ($p) {
                $lines[] = "Entscheider im Buyer-Centre (adressiere die passende Rolle):\n" . implode("\n", $p);
            }
        }

        return implode("\n\n", $lines);
    }

    private function line($value): string
    {
        return is_array($value) ? implode(' · ', array_map('strval', $value)) : (string) $value;
    }

    private function quotes($statements): string
    {
        $out = [];
        foreach (array_slice((array) $statements, 0, 6) as $s) {
            $out[] = '„' . trim((string) $s, " \"„“") . '"';
        }

        return implode('  ', $out);
    }
}
