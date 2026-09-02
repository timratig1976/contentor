<?php

namespace App\Services;

use App\Models\Strategy;

class ContentRulesService
{
    /**
     * Detect the input type from raw text (ported from content-rules.js).
     */
    public function detectInputType(string $input): string
    {
        $trimmed = trim($input);
        if (preg_match('/^https?:\/\//i', $trimmed)) return 'url';
        if (preg_match('/screenshot|bildschirm|screen/i', $trimmed)) return 'screenshot';
        if (str_contains($trimmed, '"') || str_contains($trimmed, '"') || str_contains($trimmed, '"')) return 'kundenzitat';
        return 'freitext';
    }

    public function guessSourceType(string $input, ?string $explicitType = null): string
    {
        if ($explicitType) return $explicitType;
        return match ($this->detectInputType($input)) {
            'url' => 'url',
            'screenshot' => 'screenshot',
            'kundenzitat' => 'kundenzitat',
            default => 'beobachtung',
        };
    }

    /**
     * Pick a pain cluster for the given text using unit rules.
     */
    public function pickPainCluster(string $text, Strategy $strategy): ?array
    {
        $clusters = $strategy->clusters;
        $lower = mb_strtolower($text);

        foreach ($clusters as $cluster) {
            if (isset($cluster['match']) && preg_match('/' . $cluster['match'] . '/i', $lower)) {
                return $cluster;
            }
        }

        // Fallback to default cluster
        $defaultKey = $strategy->default_cluster_key;
        foreach ($clusters as $cluster) {
            if (($cluster['key'] ?? '') === $defaultKey) {
                return $cluster;
            }
        }

        return $clusters[0] ?? null;
    }

    /**
     * Pick a statement type based on format, source type, and input text.
     */
    public function pickStatementType(?string $format, ?string $sourceType, string $input): string
    {
        $lower = mb_strtolower($input);

        if ($format === 'landing_page_headlines') return 'Gain';
        if ($format === 'ad_copy') {
            return preg_match('/roi|zahl|kosten/i', $lower) ? 'Drastisch' : 'Bedrohlich';
        }
        if ($sourceType === 'kundenzitat') return 'Direkt';
        if (preg_match('/absurd|lächerlich|iron/i', $lower)) return 'Sarkastisch';
        return 'Direkt';
    }

    /**
     * Guess ICP from input text using unit's ICP guesser rules.
     */
    public function guessIcp(string $input, ?string $explicitIcp, Strategy $strategy): string
    {
        if ($explicitIcp) return $explicitIcp;

        $lower = mb_strtolower($input);
        foreach ($strategy->icp_guesser as $entry) {
            if (isset($entry['match']) && preg_match('/' . $entry['match'] . '/i', $lower)) {
                return $entry['icp'];
            }
        }

        return $strategy->default_icp;
    }

    /**
     * Build an angle sentence from input + metadata.
     */
    public function buildAngleSentence(string $input, array $painCluster, string $icp, string $statementType): string
    {
        $base = trim($input);
        if (preg_match('/\d/', $base)) return $base;

        $prefix = match ($statementType) {
            'Bedrohlich' => 'Euer Systemproblem kostet euch mehr als der Markt',
            'Drastisch' => 'Mehr Leads lösen kein Strukturproblem',
            default => 'Ein CRM ohne Mechanismus simuliert nur Sicherheit',
        };

        return "{$prefix} — {$painCluster['name']} ist für {$icp} kein Tool-, sondern ein Führungsproblem.";
    }

    /**
     * Build channel recommendations based on statement type.
     */
    public function buildChannelRecommendations(string $statementType): array
    {
        return [
            'linkedin_organic' => [
                'suitable' => true,
                'reason' => "{$statementType}-Frame funktioniert als LinkedIn-These mit Beweislast.",
            ],
            'paid_social' => [
                'suitable' => in_array($statementType, ['Direkt', 'Drastisch', 'Bedrohlich']),
                'reason' => 'Paid braucht klaren Pain-Frame und schnelle Beweislast.',
            ],
            'newsletter' => [
                'suitable' => true,
                'reason' => 'Newsletter kann Mechanismus und Zahlen sauber ausführen.',
            ],
            'landing_page' => [
                'suitable' => in_array($statementType, ['Gain', 'Direkt']),
                'reason' => 'Landing Pages brauchen klaren Outcome- oder Mechanismus-Frame.',
            ],
        ];
    }

    /**
     * Enforce tone rules on generated text.
     */
    public function enforceTone(string $text, ?string $format, Strategy $unit, ?array $strategyCtx = null): string
    {
        $output = $text;

        // Brand voice "never" patterns
        $never = $strategyCtx['brand_voice']['never'] ?? [];
        foreach ($never as $phrase) {
            $escaped = preg_quote($phrase, '/');
            $output = preg_replace("/{$escaped}/i", '', $output);
        }

        // Strategy forbidden patterns (word-boundary-ish, nicht auf Leerzeichen)
        foreach ($unit->forbidden_patterns as $pattern) {
            $output = preg_replace('/' . $pattern . '/i', 'konkret', $output);
        }

        // Ausrufezeichen entfernen, aber Satz-/Absatzstruktur erhalten:
        // "!"+Leerzeichen/Newline → "." ; sonst "!" → "."
        $output = preg_replace('/!+(?=\s|$)/m', '.', $output);
        $output = str_replace('!', '.', $output);

        // Horizontale Whitespace normalisieren (Tabs/Mehrfach-Leerzeichen),
        // aber Zeilenumbrüche und Absatzstruktur behalten
        $output = preg_replace('/[^\S\n]+/', ' ', $output);
        // Mehr als 2 aufeinanderfolgende Newlines auf Absatz (2) begrenzen
        $output = preg_replace('/\n{3,}/', "\n\n", $output);
        // Leerzeichen vor Newlines entfernen
        $output = preg_replace('/[ \t]+\n/', "\n", $output);

        return trim($output);
    }

    /**
     * Check for forbidden tone violations.
     */
    public function checkToneViolations(string $text, Strategy $unit, ?array $strategyCtx = null): array
    {
        $violations = [];
        $raw = $text;

        $never = $strategyCtx['brand_voice']['never'] ?? [];
        foreach ($never as $phrase) {
            $escaped = preg_quote($phrase, '/');
            if (preg_match("/{$escaped}/i", $raw)) {
                $violations[] = $phrase;
            }
        }

        foreach ($unit->forbidden_patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $raw)) {
                $violations[] = $pattern;
            }
        }

        return $violations;
    }

    /**
     * Prüft ob ein verpflichtender CTA vorhanden ist (aus Strategy.config.rules.mandatoryCta).
     * Rückgabe true, wenn kein Pflicht-CTA definiert oder der CTA enthalten ist.
     */
    public function checkMandatoryCta(string $text, Strategy $unit): bool
    {
        $mandatoryCta = $unit->config['rules']['mandatoryCta'] ?? null;
        if (!$mandatoryCta) {
            return true; // kein Pflicht-CTA definiert
        }

        return str_contains(mb_strtolower($text), mb_strtolower($mandatoryCta));
    }
}
