<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Strategy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nutzt EdenAI (OpenAI GPT-4o) für die Quick-Input-Analyse.
 * Extrahiert strukturierte Angles aus Text/URL-Content via LLM-Judge.
 */
class QuickInputAgentService
{
    private const ENDPOINT = 'https://api.edenai.run/v3/chat/completions';
    private const MODEL = 'openai/gpt-4o';
    private const TIMEOUT = 60;

    private function apiKey(): ?string
    {
        return Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
    }

    /**
     * Analysiert Content via LLM und extrahiert Angles.
     *
     * Nutzt den konfigurierbaren "angle"-Agenten (Einstellungen → agent_models),
     * z. B. anthropic/claude-sonnet-4-6 — NICHT mehr hartcodiert gpt-4o.
     * Die Angles werden in der Sprache der Strategie generiert (config.language).
     *
     * @return array{angles: array, error: ?string}
     */
    public function extractAngles(string $content, Strategy $strategy, int $maxAngles = 5): array
    {
        $key = $this->apiKey();
        if (!$key) {
            return ['angles' => [], 'error' => 'Kein EdenAI-Key in den Einstellungen.'];
        }

        // Kontext aus Strategie (ICPs, Pain-Cluster, Brand Voice)
        $rules = $strategy->config['rules'] ?? [];
        $icpList = implode(', ', $rules['icpKeys'] ?? ['B2B-1', 'B2B-2', 'B2C']);
        $clusterList = '';
        foreach ($rules['clusters'] ?? [] as $c) {
            $clusterList .= "- {$c['code']}: {$c['name']}\n";
        }
        
        // Neu: Content-Strategie Pillars & Unterthemen einbinden
        $contentStrategy = \App\Models\ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'content_strategy')->first()?->content;
        $pillarList = '';
        if (!empty($contentStrategy['pillars'])) {
            foreach ($contentStrategy['pillars'] as $p) {
                $pName = $p['name'] ?? '';
                $pGoal = !empty($p['goal']) ? " (Ziel: {$p['goal']})" : '';
                $pSubs = !empty($p['subtopics']) ? ' [Unterthemen: ' . implode(', ', (array) $p['subtopics']) . ']' : '';
                $pillarList .= "- Cluster: {$pName}{$pGoal}{$pSubs}\n";
            }
        }

        // Neu: Brand Voice der Strategie einbinden (Personality, Musts, Nevers, Tonalität)
        $brandVoice = \App\Models\ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'brand_voice')->first()?->content;
        $bvBlock = '';
        if ($brandVoice) {
            $bvParts = [];
            if (!empty($brandVoice['personality'])) {
                $bvParts[] = "- Haltung & Charakter: " . $brandVoice['personality'];
            }
            if (!empty($brandVoice['tone'])) {
                $bvParts[] = "- Tonalität: " . $brandVoice['tone'];
            }
            if (!empty($brandVoice['must'])) {
                $bvParts[] = "- Pflicht-Kriterien (jeder Angle muss dem entsprechen): " . implode('; ', (array) $brandVoice['must']);
            }
            if (!empty($brandVoice['never'])) {
                $bvParts[] = "- Absolute No-Gos: " . implode('; ', (array) $brandVoice['never']);
            }
            if (!empty($brandVoice['examples'])) {
                $bvParts[] = "- Vorher/Nachher Sprachbeispiele beachten (kein Hype, sondern konkreter Klartext).";
            }
            if (!empty($bvParts)) {
                $bvBlock = "BRAND VOICE & HALTUNG DER STRATEGIE:\n" . implode("\n", $bvParts) . "\n\n";
            }
        }

        $forbidden = implode(', ', array_unique(array_merge(
            $rules['forbiddenPatterns'] ?? [],
            (array) ($brandVoice['never'] ?? [])
        )));

        // Zielsprache aus der Strategie (Default Deutsch)
        $langName = $strategy->language_name ?? 'Deutsch';

        // Reichhaltige ICP-Kontexte (Kunden-Stimme, Messaging-Frame, Statement-Typen)
        // damit extrahierte Angles kundennah klingen und die richtige Tonalität treffen.
        $icpContext = '';
        $icpSvc = app(\App\Services\IcpContextService::class);
        foreach ($icpSvc->all($strategy) as $icp) {
            if (! empty($icp['key'])) {
                $block = $icpSvc->block($strategy, $icp['key']);
                if ($block !== '') {
                    $icpContext .= $block . "\n\n";
                }
            }
        }

        // Prompt aus Einstellungen laden (editierbar), mit robustem Fallback
        $customPrompt = \App\Models\Setting::where('key', 'agent_prompts')->first()?->value['angle_extract'] ?? null;

        if ($customPrompt) {
            $systemPrompt = str_replace(
                ['{{maxAngles}}', '{{langName}}', '{{icpList}}', '{{pillarList}}', '{{forbidden}}', '{{bvBlock}}', '{{icpContext}}'],
                [
                    $maxAngles,
                    $langName,
                    $icpList,
                    ($pillarList ?: $clusterList),
                    $forbidden,
                    $bvBlock,
                    ($icpContext !== '' ? "ICP-KONTEXT (Kunden-Stimme, Messaging-Frames, bevorzugte Statement-Typen):\n{$icpContext}" : '')
                ],
                $customPrompt
            );
        } else {
            $systemPrompt = "Du bist ein Content-Analyst. Extrahiere aus dem folgenden Text die {$maxAngles} stärksten Content-Angles (Thesen/Aussagen, die sich als LinkedIn-Post eignen).

REGELN:
- Jeder Angle ist EIN präziser, knackiger Satz (max. 200 Zeichen)
- Keine Überschriften, keine Nummerierung, keine Einleitung
- Nur die inhaltliche Kernaussage — kein Fluff
- Wenn der Text weniger als {$maxAngles} starke Angles enthält, gib nur die gefundenen zurück

SPRACHE: Formuliere ALLE Angles auf {$langName} — auch wenn der Quelltext in einer anderen Sprache ist. Übersetze frei und idiomatisch, nicht wörtlich.

Format: valides JSON-Array, jedes Objekt hat:
  - \"angle\": string (die Kernaussage auf {$langName}, max 200 Zeichen)
  - \"icp\": string (NUR wenn der Angle eindeutig zu einem ICP passt, sonst \"\")
  - \"pain_cluster\": string (Name des passenden Themenclusters oder Pain-Clusters, sonst \"\")
  - \"statement_type\": string (einer von: Direkt, Drastisch, Bedrohlich, Gain, Mechanismus, Vision)
  - \"funnel\": string (einer von: ToFu, MoFu, BoFu — je nach Customer Journey Stufe)

VERFÜGBARE ICPs: {$icpList}
VERFÜGBARE THEMENCLUSTER & UNTERTHEMEN:
" . ($pillarList ?: $clusterList) . "
VERBOTENE BEGRIFFE (vermeiden): {$forbidden}

{$bvBlock}" . ($icpContext !== '' ? "ICP-KONTEXT (Kunden-Stimme, Messaging-Frames, bevorzugte Statement-Typen — damit Angles kundennah klingen und die richtige Tonalität treffen):\n{$icpContext}" : '') . "

Wichtig: icp und pain_cluster NUR setzen wenn der Angle-INHALT eindeutig dazu passt. Keine Defaults, kein Raten. Lieber leer lassen.
Antworte NUR mit dem JSON-Array, keine Erklärungen.";
        }

        $userPrompt = "Extrahiere Angles aus diesem Text (Angles auf {$langName}):\n\n---\n{$content}\n---";

        // Konfigurierbarer "angle"-Agent via LlmService (Modell in Einstellungen wählbar)
        $result = app(\App\Services\LlmService::class)->chat('angle', $systemPrompt, [
            ['role' => 'user', 'content' => $userPrompt],
        ], ['timeout' => self::TIMEOUT, 'temperature' => 0.3, 'max_tokens' => 1500]);

        if ($result['status'] !== 'success' || ! $result['text']) {
            return ['angles' => [], 'error' => $result['error'] ?? 'Leere LLM-Antwort.'];
        }

        $text = trim($result['text']);

        // JSON aus der Antwort parsen (Markdown-Codeblock-Fallback)
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
            $text = trim($m[1]);
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            return ['angles' => [], 'error' => 'LLM-Antwort nicht als JSON parsebar: ' . mb_substr($text, 0, 200)];
        }

        // Normalisieren: falls kein Array von Objekten, sondern ein Objekt mit "angles"-Key
        if (isset($parsed['angles'])) {
            $parsed = $parsed['angles'];
        }

        // Validieren und auf maxAngles begrenzen
        $angles = [];
        foreach (array_slice($parsed, 0, $maxAngles) as $item) {
            if (is_string($item)) {
                $angles[] = [
                    'angle' => mb_substr(trim($item), 0, 200),
                    'icp' => '',
                    'pain_cluster' => '',
                    'statement_type' => '',
                    'funnel' => '',
                ];
            } elseif (is_array($item)) {
                // Unterstützt sowohl das klassische {"angle": "..."} als auch das neue Schema mit {"angle_draft": "...", "claim": "..."}
                $angleText = $item['angle_draft'] ?? $item['angle'] ?? $item['claim'] ?? '';
                if (!empty($angleText)) {
                    $cluster = $item['cluster'] ?? $item['pain_cluster'] ?? '';
                    $statementType = $item['claim_type'] ?? $item['statement_type'] ?? '';
                    $funnel = $item['funnel'] ?? '';
                    $icp = $item['icp'] ?? '';

                    // Belegstelle & Verifikations-Hinweis zusammenstellen
                    $evidence = $item['evidence'] ?? null;
                    $reasoning = null;
                    if ($evidence) {
                        $reasoning = "Beleg: " . mb_substr($evidence, 0, 300);
                        if (!empty($item['needs_verification'])) {
                            $reasoning .= " | ⚠️ Zu verifizieren: " . ($item['verification_reason'] ?? 'Quelle unvollständig');
                        }
                    }

                    $angles[] = [
                        'angle' => mb_substr(trim($angleText), 0, 200),
                        'icp' => $icp,
                        'pain_cluster' => $cluster,
                        'statement_type' => $statementType,
                        'funnel' => $funnel,
                        'score_reasoning' => $reasoning,
                    ];
                }
            }
        }

        return ['angles' => $angles, 'error' => null];
    }

    /**
     * Bewertet einen Angle via LLM (4 Scoring-Kriterien + Begründung + ICP/Cluster-Validierung).
     *
     * @return array{r_zielgruppe: int, r_viscale_fit: int, r_schaerfe: int, r_timing: int, score_reasoning: string, icp_valid: bool, cluster_valid: bool}|null
     */
    public function scoreAngle(string $angleText, Strategy $strategy, ?string $currentIcp = null, ?string $currentCluster = null): ?array
    {
        $key = $this->apiKey();
        if (!$key) return null;

        $rules = $strategy->config['rules'] ?? [];
        $icpList = implode(', ', $rules['icpKeys'] ?? ['B2B-1']);
        $clusterLines = '';
        foreach ($rules['clusters'] ?? [] as $c) {
            $clusterLines .= "  - {$c['code']}: {$c['name']}\n";
        }

$prompt = "Bewerte folgenden Content-Angle nach 4 Kriterien (1-3 Punkte). Pruefe auch ob ICP und Cluster passen.

Angle: '{$angleText}'

Aktuell zugeordnet: ICP={$currentIcp}, Cluster={$currentCluster}

Verfügbare ICPs: {$icpList}
Verfügbare Cluster:
{$clusterLines}

Kriterien:
1. r_zielgruppe (1-3): Wie präzise trifft der Angle den ICP? 1=generisch, 2=relevant, 3=punktgenau
2. r_viscale_fit (1-3): Wie gut passt der Angle zur Strategie? 1=schwach, 2=passend, 3=perfekt
3. r_schaerfe (1-3): Wie provokativ? 1=neutral, 2=pointiert, 3=scharf
4. r_timing (1-3): Wie aktuell? 1=evergreen, 2=aktuell, 3=trend

Antworte NUR mit JSON:
{
  \"r_zielgruppe\": int,
  \"r_viscale_fit\": int,
  \"r_schaerfe\": int,
  \"r_timing\": int,
  \"score_reasoning\": \"1-2 Sätze Begründung\",
  \"icp_valid\": true|false,
  \"cluster_valid\": true|false
}";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post(self::ENDPOINT, [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein Angle-Scorer. Bewerte präzise und konsistent.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
                'max_tokens' => 500,
            ]);

            $text = trim($response->json('choices.0.message.content') ?? '');
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
                $text = trim($m[1]);
            }

            $data = json_decode($text, true);
            if (!$data || !isset($data['r_zielgruppe'], $data['r_viscale_fit'], $data['r_schaerfe'], $data['r_timing'])) {
                return null;
            }

            return [
                'r_zielgruppe' => max(1, min(3, (int) $data['r_zielgruppe'])),
                'r_viscale_fit' => max(1, min(3, (int) $data['r_viscale_fit'])),
                'r_schaerfe' => max(1, min(3, (int) $data['r_schaerfe'])),
                'r_timing' => max(1, min(3, (int) $data['r_timing'])),
                'score_reasoning' => mb_substr($data['score_reasoning'] ?? '', 0, 500),
                'icp_valid' => (bool) ($data['icp_valid'] ?? true),
                'cluster_valid' => (bool) ($data['cluster_valid'] ?? true),
            ];
        } catch (\Throwable $e) {
            Log::warning('QuickInputAgentService::scoreAngle() fehlgeschlagen: ' . $e->getMessage());
            return null;
        }
    }
}