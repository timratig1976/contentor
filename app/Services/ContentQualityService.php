<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\Strategy;

/**
 * Qualitäts-Gate für generierten Content.
 *
 * Dreistufig — analog zum Review-Loop des Python-Multi-Agent-Workflows,
 * aber direkt im Editor-Produktionspfad:
 *
 * 1. RULES  — deterministische Checks (Tonalität, Pflicht-CTA, Länge)
 * 2. FIX    — bei Regel-Funden: gezielte Korrektur-Anweisung an das
 *             Production-Modell (max. MAX_FIX_ROUNDS Runden)
 * 3. SCORE  — schnelles Review-Modell vergibt 0–10 + Kommentar
 *
 * Ergebnisse werden am ContentItem persistiert (quality_score,
 * quality_comment, quality_flags) und in der UI als Badge/Banner gezeigt.
 * Sie dienen später als Vorhersage-Signal für die KPI-Lernschleife
 * (korreliert LLM-Score mit echter Post-Publishing-Performance).
 */
class ContentQualityService
{
    /**
     * Maximale Auto-Korrektur-Runden bei Regel-Funden (wie Python-Workflow).
     */
    private const MAX_FIX_ROUNDS = 2;

    /**
     * Ab diesem Score gilt ein Post als freigabefähig (UI-Ampel).
     */
    public const PASS_THRESHOLD = 7;

    public function __construct(
        private ContentRulesService $rulesService,
        private LlmService $llm,
    ) {}

    /**
     * Stufe 1: deterministische Regel-Checks.
     *
     * @return array{violations:array<int,string>, missing_cta:bool, too_short:bool, too_long:bool, wall_of_text:bool}
     */
    public function ruleCheck(string $content, string $format, Strategy $strategy, array $strategyCtx): array
    {
        $violations = $this->rulesService->checkToneViolations($content, $strategy, $strategyCtx);
        $missingCta = ! $this->rulesService->checkMandatoryCta($content, $strategy);

        // Wortzahl gegen zentrale Kanal-Regeln prüfen (ContentRulesService, inkl. DB-Overrides)
        $channelRules = $this->rulesService->channelRules($format, $strategyCtx['channel_rules'] ?? []);
        $words = str_word_count(strip_tags($content));
        $min = $channelRules['word_count']['min'] ?? null;
        $max = $channelRules['word_count']['max'] ?? null;

        return [
            'violations' => $violations,
            'missing_cta' => $missingCta,
            'too_short' => $min !== null && $words < $min,
            'too_long' => $max !== null && $words > (int) round($max * 1.35), // 35% Toleranz
            'wall_of_text' => $this->isWallOfText($content, $words, $format),
        ];
    }

    /**
     * Erkennt Fließtext-Wände: zu wenige Absätze (Leerzeilen) für die Textlänge.
     * Faustregel: ab ~60 Wörtern sollte mind. 1 Absatzumbruch pro ~70 Wörter da sein.
     * blog_post hat längere Absätze — hier reicht 1 pro ~100 Wörter.
     */
    private function isWallOfText(string $content, int $words, string $format): bool
    {
        if ($words < 60) {
            return false; // kurze Texte brauchen keine Absatztrennung
        }

        $paragraphs = preg_split('/\n\s*\n/', trim($content));
        $paragraphCount = count(array_filter($paragraphs, fn ($p) => trim($p) !== ''));

        $wordsPerParagraphLimit = $format === 'blog_post' ? 100 : 70;
        $expectedMinParagraphs = max(2, (int) ceil($words / $wordsPerParagraphLimit));

        return $paragraphCount < $expectedMinParagraphs;
    }

    /**
     * Ist irgendein Regel-Fund kritisch (erfordert Auto-Fix)?
     *
     * @param array{violations:array,missing_cta:bool,too_short:bool,too_long:bool,wall_of_text:bool} $check
     */
    public function hasBlockingFindings(array $check): bool
    {
        return $check['violations'] !== []
            || $check['missing_cta']
            || $check['too_short']
            || $check['too_long']
            || ($check['wall_of_text'] ?? false);
    }

    /**
     * Stufe 2: konkrete Korrektur-Anweisung aus den Regel-Funden ableiten.
     *
     * @param array{violations:array,missing_cta:bool,too_short:bool,too_long:bool,wall_of_text:bool} $check
     */
    public function fixInstruction(array $check, Strategy $strategy): string
    {
        $parts = [];

        if ($check['violations']) {
            $parts[] = 'Entferne/ersetze diese verbotenen Begriffe oder Muster (Brand-Voice-Verstoß): '
                . implode(', ', array_map(fn ($v) => '"' . $v . '"', $check['violations'])) . '.';
        }
        if ($check['missing_cta']) {
            $cta = $strategy->config['rules']['mandatoryCta'] ?? null;
            $parts[] = $cta
                ? "Ergänze den Pflicht-CTA: \"{$cta}\" — natürlich einbetten, nicht anhängen wie eine Signatur."
                : 'Ergänze einen klaren, zum Format passenden CTA am Ende.';
        }
        if ($check['too_short']) {
            $parts[] = 'Der Text ist zu kurz für das Format — baue ihn inhaltlich aus (konkrete Punkte, Beispiele, Mechanismus), ohne zu schwafeln.';
        }
        if ($check['too_long']) {
            $parts[] = 'Der Text ist deutlich zu lang für das Format — kürze auf das Wesentliche, streiche Wiederholungen und Füllsätze.';
        }
        if ($check['wall_of_text'] ?? false) {
            $parts[] = 'Der Text wirkt als eine zusammenhängende Textwand — teile ihn in mehr Absätze auf (Leerzeile zwischen Sinnabschnitten: Hook, Kontext, Mechanismus, Konsequenz, CTA jeweils eigener Absatz). Inhalt dabei unverändert lassen.';
        }

        return implode("\n", $parts);
    }

    /**
     * Stufe 2 (Call): lässt das Production-Modell den Text anhand der
     * Korrektur-Anweisung überarbeiten. Liefert null bei LLM-Fehler
     * (dann bleibt der vorherige Text stehen — kein Datenverlust).
     */
    public function applyFix(string $content, string $instruction, string $format): ?string
    {
        $system = 'Du bist ein präziser Content-Redakteur. Du erhältst einen Text und konkrete Korrektur-Anweisungen. '
            . 'Gib NUR den vollständig überarbeiteten Text aus — keine Erklärungen, keine Meta-Kommentare, keine Markdown-Codeblöcke. '
            . 'Erhalte Stil, Persona-Stimme und Struktur des Originals; ändere nur, was die Anweisungen verlangen.';

        $user = "AKTUELLER TEXT (Format: {$format}):\n```\n{$content}\n```\n\nKORREKTUR-ANWEISUNGEN:\n{$instruction}";

        $result = $this->llm->chat('production', $system, [
            ['role' => 'user', 'content' => $user],
        ], ['timeout' => 90, 'agent_log' => 'quality_fix', 'reasoning_effort' => 'low']);

        if ($result['status'] === 'success' && $result['text'] && mb_strlen($result['text']) > 30) {
            return trim($result['text']);
        }

        return null;
    }

    /**
     * Stufe 3: LLM-Review — Score 0–10 + Kommentar (schnelles Review-Modell).
     *
     * @return array{score:?int, comment:?string}
     */
    public function score(string $content, string $format, Strategy $strategy, array $strategyCtx, ?string $icp = null): array
    {
        $brandSummary = '';
        $voice = $strategyCtx['brand_voice'] ?? [];
        if (! empty($voice['tone'])) {
            $brandSummary .= 'Tonalität: ' . $voice['tone'] . '. ';
        }
        if (! empty($voice['never'])) {
            $brandSummary .= 'Tabus: ' . implode(', ', (array) $voice['never']) . '. ';
        }

        $system = 'Du bist ein strenger, aber fairer Content-Reviewer für B2B-Content. '
            . 'Bewerte den Text nach: Hook-Stärke, Klarheit des Mechanismus, Belegqualität, '
            . 'Zielgruppen-Treffer, Markenkonformität, CTA-Wirksamkeit UND Menschlichkeit '
            . '(klingt der Text wie von einem echten Menschen geschrieben, oder erkennt man typische KI-Rhetorik '
            . 'wie "Das klingt nach X. Es ist Y.", perfekte 3er-Aufzählungen, isolierte Merksatz-Pointen, '
            . 'übermäßig glatte/symmetrische Sätze)? '
            . 'Ziehe für schwache Menschlichkeit klar Punkte ab, auch wenn der Rest stark ist. '
            . 'Antworte AUSSCHLIESSLICH mit validem JSON in genau diesem Schema: '
            . '{"score": <Ganzzahl 0-10>, "comment": "<2-3 Sätze: was stark ist, was konkret fehlt>", "issues": ["<Mangel 1>", ...], "ai_sound_detected": <true/false>}';

        $user = "FORMAT: {$format}\n"
            . ($icp ? "ZIELGRUPPE (ICP): {$icp}\n" : '')
            . ($brandSummary !== '' ? "BRAND-VORGABEN: {$brandSummary}\n" : '')
            . "\nZU PRÜFENDER TEXT:\n```\n{$content}\n```";

        $result = $this->llm->chat('review', $system, [
            ['role' => 'user', 'content' => $user],
        ], ['timeout' => 60, 'temperature' => 0.2, 'max_tokens' => 800, 'reasoning_effort' => 'low']);

        if ($result['status'] !== 'success' || ! $result['text']) {
            return ['score' => null, 'comment' => null];
        }

        // JSON aus der Antwort extrahieren (ggf. mit Codeblock)
        $text = trim($result['text']);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
            $text = trim($m[1]);
        }
        // Falls Text vor/nach dem JSON steht: erstes {...}-Block fischen
        if (! str_starts_with($text, '{') && preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $text = $m[0];
        }

        $data = json_decode($text, true);
        if (! is_array($data) || ! isset($data['score'])) {
            return ['score' => null, 'comment' => null];
        }

        $score = max(0, min(10, (int) $data['score']));
        $comment = (string) ($data['comment'] ?? '');
        if (! empty($data['issues']) && is_array($data['issues'])) {
            $comment = trim($comment . ' Mängel: ' . implode('; ', array_slice($data['issues'], 0, 4)));
        }

        return [
            'score' => $score,
            'comment' => mb_substr($comment, 0, 1000) ?: null,
            'ai_sound_detected' => (bool) ($data['ai_sound_detected'] ?? false),
        ];
    }

    /**
     * Voller Quality-Gate-Durchlauf für ein frisch generiertes Item:
     * Regel-Check → (bis zu MAX_FIX_ROUNDS) Auto-Fix → LLM-Score → Persistenz.
     *
     * Mutiert $item->content bei erfolgreichen Fix-Runden und speichert
     * quality_score/comment/flags. Gibt das aktualisierte Item zurück.
     */
    public function gate(ContentItem $item, Strategy $strategy, array $strategyCtx): ContentItem
    {
        $content = $item->content;
        $flags = [
            'tone_violations' => [],
            'missing_cta' => false,
            'too_short' => false,
            'too_long' => false,
            'fix_rounds' => 0,
            'fixed_findings' => [],
            'steps' => [], // chronologischer Gate-Report für die UI
        ];

        // ── Regel-Check + Auto-Fix-Loop ─────────────────────────────
        for ($round = 1; $round <= self::MAX_FIX_ROUNDS; $round++) {
            $check = $this->ruleCheck($content, $item->format, $strategy, $strategyCtx);
            $flags['tone_violations'] = $check['violations'];
            $flags['missing_cta'] = $check['missing_cta'];
            $flags['too_short'] = $check['too_short'];
            $flags['too_long'] = $check['too_long'];
            $flags['wall_of_text'] = $check['wall_of_text'] ?? false;

            $findings = $this->findingsList($check);
            $flags['steps'][] = [
                'stage' => 'rules',
                'round' => $round,
                'findings' => $findings,
                'at' => now()->toIso8601String(),
            ];

            if ($findings === []) {
                break;
            }

            if ($round === 1) {
                $flags['fixed_findings'] = $findings;
            }

            $instruction = $this->fixInstruction($check, $strategy);
            $beforeLen = mb_strlen($content);
            $fixed = $this->applyFix($content, $instruction, $item->format);

            $flags['steps'][] = [
                'stage' => 'fix',
                'round' => $round,
                'instruction' => $instruction,
                'result' => $fixed === null ? 'llm_error' : 'ok',
                'chars_before' => $beforeLen,
                'chars_after' => $fixed === null ? $beforeLen : mb_strlen($fixed),
                'at' => now()->toIso8601String(),
            ];

            if ($fixed === null) {
                // LLM-Fehler: Fix-Runden abbrechen, Befund als Flag stehen lassen
                break;
            }

            // Tone-Enforcement auch auf den gefixten Text anwenden
            $content = $this->rulesService->enforceTone($fixed, $item->format, $strategy, $strategyCtx);
            $flags['fix_rounds'] = $round;
        }

        // ── LLM-Score (auf finalen Text) ────────────────────────────
        $review = $this->score($content, $item->format, $strategy, $strategyCtx, $item->icp);

        $flags['steps'][] = [
            'stage' => 'score',
            'score' => $review['score'],
            'comment' => $review['comment'],
            'ai_sound_detected' => $review['ai_sound_detected'] ?? false,
            'at' => now()->toIso8601String(),
        ];

        // ── Nachbesserung, wenn das Review-Modell KI-Sound erkannt hat ──
        // (regelbasierter Loop oben deckt nur bekannte Muster ab; das
        // Review-Modell erkennt auch subtilere Fälle wie glatte Symmetrie).
        if (($review['ai_sound_detected'] ?? false) === true) {
            $humanizeInstruction = 'Der Text klingt zu sehr wie von einer KI geschrieben (zu glatt, zu symmetrisch, '
                . 'klischeehafte Rhetorik). Überarbeite ihn so, dass er wie ein echter Mensch klingt: '
                . 'variiere Satzlängen unregelmäßig, vermeide perfekte Aufzählungen und Antithese-Formeln, '
                . 'lass Gedanken auch mal unrund enden. Inhalt und Kernaussage bleiben unverändert.';

            $humanized = $this->applyFix($content, $humanizeInstruction, $item->format);
            $flags['steps'][] = [
                'stage' => 'humanize',
                'result' => $humanized === null ? 'llm_error' : 'ok',
                'at' => now()->toIso8601String(),
            ];

            if ($humanized !== null) {
                $content = $this->rulesService->enforceTone($humanized, $item->format, $strategy, $strategyCtx);
                // Finalen Text erneut bewerten, damit quality_score den echten Endstand zeigt
                $review = $this->score($content, $item->format, $strategy, $strategyCtx, $item->icp);
                $flags['steps'][] = [
                    'stage' => 'score',
                    'score' => $review['score'],
                    'comment' => $review['comment'],
                    'ai_sound_detected' => $review['ai_sound_detected'] ?? false,
                    'at' => now()->toIso8601String(),
                ];
            }
        }

        $item->content = $content;
        $item->quality_score = $review['score'];
        $item->quality_comment = $review['comment'];
        $item->quality_flags = $flags;
        $item->save();

        return $item;
    }

    /**
     * Befunde als lesbare Liste (leer = alles in Ordnung).
     *
     * @param array{violations:array,missing_cta:bool,too_short:bool,too_long:bool,wall_of_text:bool} $check
     * @return array<int,string>
     */
    private function findingsList(array $check): array
    {
        return array_values(array_merge(
            $check['violations'],
            array_filter([
                $check['missing_cta'] ? 'Pflicht-CTA fehlt' : null,
                $check['too_short'] ? 'Text zu kurz fürs Format' : null,
                $check['too_long'] ? 'Text zu lang fürs Format' : null,
                ($check['wall_of_text'] ?? false) ? 'Text ist eine Fließtext-Wand (zu wenige Absätze)' : null,
            ])
        ));
    }
}
