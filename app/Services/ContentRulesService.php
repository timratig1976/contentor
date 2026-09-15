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
     * Alle gültigen Statement-Typen (kanonisch, für UI + Validierung).
     */
    public const STATEMENT_TYPES = [
        'Direkt', 'Drastisch', 'Bedrohlich', 'Gain', 'Mechanismus', 'Vision', 'Sarkastisch',
    ];

    /**
     * Kurzer Schreib-Hinweis pro Statement-Typ (wird in den LLM-Prompt injiziert),
     * damit der Content den gewählten Frame tatsächlich trifft.
     */
    public const STATEMENT_TYPE_HINTS = [
        'Direkt'      => 'Formuliere sachlich und direkt — eine klare, unmissverständliche Aussage ohne Drama.',
        'Drastisch'   => 'Formuliere hart und alarmierend — betone die drastische Konsequenz, wenn nichts passiert.',
        'Bedrohlich'  => 'Formuliere als drohende Konsequenz — mache klar, was das Problem kostet, wenn es ignoriert wird.',
        'Gain'        => 'Formuliere ergebnis-/nutzenorientiert — stelle den positiven Outcome und den Gewinn in den Vordergrund.',
        'Mechanismus' => 'Formuliere erklärend — erläutere den Mechanismus/Wirkzusammenhang hinter der Aussage.',
        'Vision'      => 'Formuliere visionär — zeichne ein Bild des erstrebenswerten Zielzustands.',
        'Sarkastisch' => 'Formuliere mit spitzer, leis ironischer Zuspitzung — ohne giftig zu werden.',
    ];

    /**
     * Empfiehlt den passendsten Statement-Typ anhand von Format, Funnel und Text.
     * Liefert Typ + Begründung zurück — der Nutzer kann im UI übersteuern.
     *
     * @return array{type: string, reason: string}
     */
    public function recommendStatementType(?string $format, ?string $funnel, ?string $icp = null, string $angleText = ''): array
    {
        // 1) Kanal/Format dominiert
        if ($format === 'landing_page_headlines') {
            return ['type' => 'Gain', 'reason' => 'Landing Pages müssen einen klaren Nutzen/Outcome versprechen — ein Gain-Frame konvertiert hier am besten.'];
        }
        if ($format === 'ad_copy') {
            $dramatic = preg_match('/roi|zahl|kosten|€|\$|%|\d/i', $angleText);
            return $dramatic
                ? ['type' => 'Drastisch', 'reason' => 'Bezahlte Ads brauchen einen klaren Pain-Frame. Da der Angle konkrete Zahlen/Kosten nennt, wirkt ein drastischer Frame am stärksten.']
                : ['type' => 'Bedrohlich', 'reason' => 'Bezahlte Ads brauchen einen klaren Pain-Frame — bei einem eher qualitativen Problem ist ein bedrohlicher (Konsequenz-)Frame passend.'];
        }
        if ($format === 'newsletter') {
            return ['type' => 'Mechanismus', 'reason' => 'Newsletter dürfen nicht alarmieren — hier überzeugt ein erklärender Mechanismus-Frame (so funktioniert es).'];
        }

        // 2) Funnel-Stufe
        $funnelMap = [
            'ToFu' => ['type' => 'Drastisch', 'reason' => 'ToFu (Aufmerksamkeit erzeugen): ein drastischer Frame bricht durch den News-Feed und stoppt das Scrollen.'],
            'MoFu' => ['type' => 'Mechanismus', 'reason' => 'MoFu (Überzeugen): hier zählt Erklärung — ein Mechanismus-Frame liefert den Wirkzusammenhang, der Vertrauen schafft.'],
            'BoFu' => ['type' => 'Gain', 'reason' => 'BoFu (Abschluss): kurz vor der Entscheidung überzeugt der konkrete Nutzen — ein Gain-Frame macht den Outcome greifbar.'],
        ];
        if ($funnel && isset($funnelMap[$funnel])) {
            return $funnelMap[$funnel];
        }

        // 3) Text-Heuristik: konträr/harte Aussage → drastisch
        if (preg_match('/kostet|scheitert|blindflug|insellösung|siloolösung|kein[\w\s]{0,20}sondern|hängt an personen/i', $angleText)) {
            return ['type' => 'Drastisch', 'reason' => 'Der Angle formuliert eine harte, konträre Konsequenz (Kosten/Scheitern) — das verlangt einen drastischen Frame.'];
        }

        // 4) Default: sachlich-direkt
        return ['type' => 'Direkt', 'reason' => 'Kein spezieller Frame nötig — eine klare, direkte Aussage transportiert den Angle am verständlichsten.'];
    }

    /**
     * Schreib-Hinweis für einen Statement-Typ (LLM-Prompt-Injection).
     */
    public function statementTypeHint(string $statementType): ?string
    {
        return self::STATEMENT_TYPE_HINTS[$statementType] ?? null;
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
     * Liest den default_funnel eines ICP aus den icp_definitions der Strategie.
     * Rückgabe null, wenn der ICP nicht definiert ist oder keinen Funnel hat.
     */
    public function resolveIcpFunnel(Strategy $strategy, string $icp): ?string
    {
        $definitions = \App\Models\ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'icp_definitions')
            ->value('content');

        foreach ($definitions['icps'] ?? [] as $entry) {
            if (($entry['key'] ?? null) === $icp && ! empty($entry['default_funnel'])) {
                return $entry['default_funnel'];
            }
        }

        return null;
    }

    /**
     * Schätzt die Funnel-Stufe eines Angles anhand des Textes.
     *
     * Logik:
     * - BoFu: Kauf-/Entscheidungssignale (ROI, Kosten sparen, Wechsel, System einführen)
     * - MoFu: Lösungs-/Mechanismus-Signale (wie funktioniert, Prozess, System, Modell)
     * - ToFu: Problem-/Awareness-Signale (Problembeschreibung, Schmerz, These)
     *
     * @return array{funnel: string, reason: string}
     */
    public function guessFunnel(string $angleText): array
    {
        $lower = mb_strtolower($angleText);

        // BoFu: Entscheidung / Outcome / Investition
        if (preg_match('/roi|investition|einspar|kosten sparen|amortis|wechsel|umstieg|einführ|implementier|angebot|demo|pilot/i', $lower)) {
            return ['funnel' => 'BoFu', 'reason' => 'Der Angle spricht Entscheidung/Implementierung an — Zielgruppe steht vor der Wahl.'];
        }

        // MoFu: Mechanismus / Lösung / Struktur
        if (preg_match('/system|prozess|mechanismus|methodik|lösung|architektur|aufbau|struktur|workflow|automatisier|standard|regel|ritual|review/i', $lower)) {
            return ['funnel' => 'MoFu', 'reason' => 'Der Angle dreht sich um Lösung/Mechanismus — Zielgruppe kennt das Problem und sucht das Wie.'];
        }

        // ToFu: Problem / Schmerz / These (Default)
        return ['funnel' => 'ToFu', 'reason' => 'Der Angle beschreibt ein Problem oder eine These — gut, um Aufmerksamkeit zu erzeugen (kalte Zielgruppe).'];
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
     * Zentrale Kanal-Standardregeln pro Format (Single Source of Truth).
     *
     * Wird von ContentController (Prompt-Aufbau) UND ContentQualityService
     * (Längenprüfung) gelesen, damit Word-Count, Aufbau und Hook-Regeln an
     * genau EINER Stelle definiert sind und nicht auseinanderdriften.
     *
     * Strategie-spezifische Abweichungen liegen in der DB unter
     * ContentStrategy.key='channel_rules'[format] und werden per Merge
     * über diese Defaults gelegt (siehe channelRules()).
     */
    public const DEFAULT_CHANNEL_RULES = [
        'linkedin_post' => [
            'word_count' => ['min' => 120, 'max' => 220],
            'hook_max_words' => 12,
            // Zeichen, bevor LinkedIn die "…mehr anzeigen"-Falte setzt (Desktop ~140).
            // Hook + Kernaussage MÜSSEN in diesem sichtbaren Fenster stehen.
            'visible_hook_max_chars' => 140,
            'structure' => [
                'Hook (Zeile 1, max 12 Wörter, provokante These)',
                'Kontext/Problem (2-3 Sätze)',
                'Mechanismus/Beleg (3-5 Sätze, konkreter Nutzen)',
                'Konsequenz für den Leser (1-2 Sätze)',
                'Soft CTA (1 Satz)',
            ],
            'cta_style' => 'Soft CTA — Frage oder Einladung zum Austausch, kein harter Verkauf',
        ],
        'ad_copy' => [
            'primary_text_max_chars' => 125,
            'headline_max_chars' => 40,
            'structure' => ['Primary Text (max 125 Zeichen)', 'Headline (max 40 Zeichen)', 'Description', 'CTA'],
            'cta_style' => 'Klarer Handlungs-CTA',
        ],
        /* legacy */ 'newsletter_acquisition' => [
            'word_count' => ['min' => 200, 'max' => 400],
            'subject_max_chars' => 50,
            'structure' => ['Betreff (max 50 Zeichen)', 'Preview-Text', 'Body (Problem → Lösung → Beweis)', 'CTA'],
            'cta_style' => 'Ein klarer CTA-Link',
        ],
        /* legacy */ 'newsletter_bk' => [
            'word_count' => ['min' => 150, 'max' => 350],
            'structure' => ['Betreff', 'Persönliche Einleitung', 'Hauptteil (1-2 konkrete Punkte)', 'Next Step', 'Sign-off'],
            'cta_style' => 'Konkreter nächster Schritt',
        ],
        // Konsolidiertes Newsletter-Format (ersetzt bk + acquisition)
        'newsletter' => [
            'word_count' => ['min' => 150, 'max' => 400],
            'subject_max_chars' => 50,
            'structure' => ['Betreff (max 50 Zeichen)', 'Preview-Text oder persönliche Einleitung', 'Hauptteil (Problem → Lösung oder 1-2 konkrete Punkte)', 'Next Step / CTA', 'Sign-off'],
            'cta_style' => 'Ein klarer nächster Schritt — Klick-Link oder Handlungsaufforderung',
        ],
        'landing_page_headlines' => [
            'structure' => ['Hero Headline (max 20 Wörter)', 'Sub-Headline (max 30 Wörter)', '3 Bullet Points', 'CTA-Button-Text'],
            'cta_style' => 'Kurzer Button-Text (2-4 Wörter)',
        ],
        'blog_post' => [
            'word_count' => ['min' => 400, 'max' => 800],
            'title_max_chars' => 60,
            'structure' => ['Titel (einprägsam, max 60 Zeichen, OHNE #)', 'Einleitung (Hook + These)', '2-4 Absätze mit je einem Punkt', 'Fazit + CTA'],
            'cta_style' => 'CTA am Ende',
        ],
    ];

    /**
     * Kanal-Regeln für ein Format: zentrale Defaults, überschrieben durch
     * strategie-spezifische Konfiguration aus der DB (falls vorhanden).
     *
     * @param  array<string,mixed>  $channelRulesCtx  Inhalt ContentStrategy.key='channel_rules' der Strategie
     * @return array<string,mixed>
     */
    public function channelRules(string $format, array $channelRulesCtx = []): array
    {
        $defaults = self::DEFAULT_CHANNEL_RULES[$format] ?? [];

        // Strategie-Override: nur wenn DB-Wert ein Format-Array ist (nicht die
        // alte channels[]-Liste aus der Strategie-UI).
        $override = $channelRulesCtx[$format] ?? null;
        if (is_array($override) && $override !== []) {
            // word_count tief mergen, Rest flach überschreiben
            $mergedWordCount = array_merge(
                $defaults['word_count'] ?? [],
                $override['word_count'] ?? []
            );
            $defaults = array_merge($defaults, $override);
            if ($mergedWordCount) {
                $defaults['word_count'] = $mergedWordCount;
            }
        }

        return $defaults;
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

        // Hashtag-Spam verhindern: Duplikate entfernen & auf max. 5 kappen
        $output = $this->sanitizeHashtags($output);

        return trim($output);
    }

    /**
     * Entfernt doppelte Hashtags und begrenzt sie auf eine sinnvolle Anzahl
     * (Default: 5). Verhindert, dass ein KI-Modell im Enhancement-Pfad
     * Dutzende gleichlautende Hashtags anhängt.
     *
     * Die bereinigten Hashtags werden als eine Zeile am Textende gesetzt.
     */
    public function sanitizeHashtags(string $text, int $max = 5): string
    {
        preg_match_all('/#[\p{L}\p{N}_]+/u', $text, $matches);
        if (empty($matches[0])) {
            return $text;
        }

        // Dedupe case-insensitive, Reihenfolge beibehalten
        $seen = [];
        $unique = [];
        foreach ($matches[0] as $tag) {
            $key = mb_strtolower($tag);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $tag;
            }
        }
        $hashtags = array_slice($unique, 0, max(1, $max));

        // Alle Hashtags aus dem Text entfernen
        $cleaned = preg_replace('/\s*#[\p{L}\p{N}_]+/u', '', $text);
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);
        $cleaned = trim($cleaned);

        if ($hashtags === []) {
            return $cleaned;
        }

        return $cleaned . ($cleaned !== '' ? "\n\n" : '') . implode(' ', $hashtags);
    }

    /**
     * Typische LLM-Rhetorik-Tics, die generierten Text sofort als KI-Text
     * verraten (case-insensitive Regex, ohne Wortgrenzen-Overhead).
     * Wird von checkToneViolations() als zusätzliche Fund-Quelle genutzt,
     * damit der Auto-Fix-Loop im Quality-Gate diese Muster aktiv entfernt.
     */
    private const AI_SOUND_PATTERNS = [
        '/das (klingt|wirkt|scheint) (nach|wie)[^.!?]*\.\s*(es ist|in wahrheit|tatsächlich)/i' => 'Antithese-Klischee ("Das klingt nach X. Es ist Y.")',
        '/\b(kein[e]?\s+[\wäöüß-]+(?:\s+[\wäöüß-]+){0,3},\s*kein[e]?\s+[\wäöüß-]+(?:\s+[\wäöüß-]+){0,3},\s*kein[e]?\s+[\wäöüß-]+)/i' => 'perfekte 3er-Aufzählung mit "kein/keine"',
        '/\b(weder\s+[\wäöüß-]+\s+noch\s+[\wäöüß-]+\s+noch\s+[\wäöüß-]+)/i' => 'perfekte 3er-Aufzählung mit "weder/noch"',
        '/^(das ergebnis|die konsequenz|fazit|die lösung)\s*:\s*$/im' => 'Einzeiler-Übergang als eigene Zeile (z.B. "Fazit:")',
        // Satzbau-Regel: enumerative Prosa ("X in einem Tool, Y in einem anderen, Z im Kopf")
        '/\bin einem [^,.!?]{2,40},\s*[^,.!?]{3,40} in einem anderen/i' => 'Aufzählung als Satzersatz ("X in einem Tool, Y in einem anderen") — zu einem Fließsatz verweben',
        '/:\s*[^.!?\n]{3,60},\s*[^.!?\n]{3,60},\s*(und|oder)\s+[^.!?\n]{3,60}$/m' => 'Satz endet mit Doppelpunkt + Liste — zu einem Fließsatz verweben',
    ];

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

        foreach (self::AI_SOUND_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $raw)) {
                $violations[] = "KI-Muster: {$label}";
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
