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
        $forbidden = implode(', ', $rules['forbiddenPatterns'] ?? []);

        $systemPrompt = "Du bist ein Content-Analyst. Extrahiere aus dem folgenden Text die {$maxAngles} stärksten Content-Angles (Thesen/Aussagen, die sich als LinkedIn-Post eignen).

REGELN:
- Jeder Angle ist EIN präziser, knackiger Satz (max. 200 Zeichen)
- Keine Überschriften, keine Nummerierung, keine Einleitung
- Nur die inhaltliche Kernaussage — kein Fluff
- Wenn der Text weniger als {$maxAngles} starke Angles enthält, gib nur die gefundenen zurück

Format: valides JSON-Array, jedes Objekt hat:
  - \"angle\": string (die Kernaussage, max 200 Zeichen)
  - \"icp\": string (NUR wenn der Angle eindeutig zu einem ICP passt, sonst \"\")
  - \"pain_cluster\": string (NUR wenn der Angle eindeutig zu einem Cluster passt, sonst \"\")
  - \"statement_type\": string (einer von: Direkt, Drastisch, Bedrohlich, Gain, Mechanismus, Vision)

VERFÜGBARE ICPs: {$icpList}
VERFÜGBARE PAIN-CLUSTER:
{$clusterList}
VERBOTENE BEGRIFFE (vermeiden): {$forbidden}

Wichtig: icp und pain_cluster NUR setzen wenn der Angle-INHALT eindeutig dazu passt. Keine Defaults, kein Raten. Lieber leer lassen.
Antworte NUR mit dem JSON-Array, keine Erklärungen.";

        $userPrompt = "Extrahiere Angles aus diesem Text:\n\n---\n{$content}\n---";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->timeout(self::TIMEOUT)->post(self::ENDPOINT, [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 1500,
            ]);

            $text = $response->json('choices.0.message.content');
            if (!$text) {
                return ['angles' => [], 'error' => 'Leere LLM-Antwort.'];
            }

            // JSON aus der Antwort parsen (Markdown-Codeblock-Fallback)
            $text = trim($text);
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
                    ];
                } elseif (is_array($item) && !empty($item['angle'])) {
                    $angles[] = [
                        'angle' => mb_substr(trim($item['angle']), 0, 200),
                        'icp' => $item['icp'] ?? '',
                        'pain_cluster' => $item['pain_cluster'] ?? '',
                        'statement_type' => $item['statement_type'] ?? '',
                    ];
                }
            }

            return ['angles' => $angles, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('QuickInputAgentService::extractAngles() fehlgeschlagen: ' . $e->getMessage());
            return ['angles' => [], 'error' => 'LLM-Fehler: ' . $e->getMessage()];
        }
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