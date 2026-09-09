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
     * @return array{violations:array<int,string>, missing_cta:bool, too_short:bool, too_long:bool}
     */
    public function ruleCheck(string $content, string $format, Strategy $strategy, array $strategyCtx): array
    {
        $violations = $this->rulesService->checkToneViolations($content, $strategy, $strategyCtx);
        $missingCta = ! $this->rulesService->checkMandatoryCta($content, $strategy);

        // Wortzahl gegen Kanal-Regeln prüfen (konfiguriert oder Defaults)
        $channelRules = $strategyCtx['channel_rules'][$format] ?? $this->defaultWordCount($format);
        $words = str_word_count(strip_tags($content));
        $min = $channelRules['word_count']['min'] ?? null;
        $max = $channelRules['word_count']['max'] ?? null;

        return [
            'violations' => $violations,
            'missing_cta' => $missingCta,
            'too_short' => $min !== null && $words < $min,
            'too_long' => $max !== null && $words > (int) round($max * 1.35), // 35% Toleranz
        ];
    }

    /**
     * Ist irgendein Regel-Fund kritisch (erfordert Auto-Fix)?
     *
     * @param array{violations:array,missing_cta:bool,too_short:bool,too_long:bool} $check
     */
    public function hasBlockingFindings(array $check): bool
    {
        return $check['violations'] !== []
            || $check['missing_cta']
            || $check['too_short']
            || $check['too_long'];
    }

    /**
     * Stufe 2: konkrete Korrektur-Anweisung aus den Regel-Funden ableiten.
     *
     * @param array{violations:array,missing_cta:bool,too_short:bool,too_long:bool} $check
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
            . 'Zielgruppen-Treffer, Markenkonformität und CTA-Wirksamkeit. '
            . 'Antworte AUSSCHLIESSLICH mit validem JSON in genau diesem Schema: '
            . '{"score": <Ganzzahl 0-10>, "comment": "<2-3 Sätze: was stark ist, was konkret fehlt>", "issues": ["<Mangel 1>", ...]}';

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

        return ['score' => $score, 'comment' => mb_substr($comment, 0, 1000) ?: null];
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
        ];

        // ── Regel-Check + Auto-Fix-Loop ─────────────────────────────
        for ($round = 1; $round <= self::MAX_FIX_ROUNDS; $round++) {
            $check = $this->ruleCheck($content, $item->format, $strategy, $strategyCtx);
            $flags['tone_violations'] = $check['violations'];
            $flags['missing_cta'] = $check['missing_cta'];
            $flags['too_short'] = $check['too_short'];
            $flags['too_long'] = $check['too_long'];

            if (! $this->hasBlockingFindings($check)) {
                break;
            }

            if ($round === 1) {
                $flags['fixed_findings'] = array_merge(
                    $check['violations'],
                    array_filter([
                        $check['missing_cta'] ? 'Pflicht-CTA fehlte' : null,
                        $check['too_short'] ? 'Text zu kurz' : null,
                        $check['too_long'] ? 'Text zu lang' : null,
                    ])
                );
            }

            $fixed = $this->applyFix($content, $this->fixInstruction($check, $strategy), $item->format);
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

        $item->content = $content;
        $item->quality_score = $review['score'];
        $item->quality_comment = $review['comment'];
        $item->quality_flags = $flags;
        $item->save();

        return $item;
    }

    /**
     * Default-Wortzahlregeln pro Format (Spiegel der ContentController-Defaults,
     * reduziert auf die word_count-Dimension).
     */
    private function defaultWordCount(string $format): array
    {
        return match ($format) {
            'linkedin_post' => ['word_count' => ['min' => 120, 'max' => 220]],
            'newsletter_acquisition' => ['word_count' => ['min' => 200, 'max' => 400]],
            'newsletter_bk' => ['word_count' => ['min' => 150, 'max' => 350]],
            'blog_post' => ['word_count' => ['min' => 400, 'max' => 800]],
            default => [],
        };
    }
}
