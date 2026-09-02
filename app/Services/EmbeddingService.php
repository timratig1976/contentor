<?php

namespace App\Services;

use App\Models\Angle;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Erzeugt Embeddings via EdenAI (OpenAI text-embedding-3-small)
 * und findet ähnliche Angles per Cosine-Similarity.
 *
 * DB-agnostisch: Vektoren werden als JSON in `angles.embedding` gespeichert,
 * die Similarity wird in PHP berechnet (SQLite-freundlich, kein pgvector nötig).
 *
 * EdenAI-Endpoint: POST https://api.edenai.run/v2/text/embeddings
 * Body: { "providers": "openai", "texts": ["..."], "response_as_dict": true }
 */
class EmbeddingService
{
    private const ENDPOINT = 'https://api.edenai.run/v2/text/embeddings';
    private const PROVIDER = 'openai';
    private const MODEL = 'text-embedding-3-small';
    private const SIMILARITY_THRESHOLD = 0.92; // ab hier = Duplikat-Flag

    private function apiKey(): ?string
    {
        return Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
    }

    /**
     * Erzeugt einen Embedding-Vektor für den gegebenen Text.
     *
     * @return float[]|null Vektor-Array oder null bei Fehler/fehlendem Key
     */
    public function embed(string $text): ?array
    {
        $key = $this->apiKey();
        if (!$key) {
            return null;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(30)
                ->post(self::ENDPOINT, [
                    'providers'        => self::PROVIDER,
                    'model'            => self::MODEL,
                    'texts'            => [$text],
                    'response_as_dict' => true,
                ]);

            $vector = $response->json(self::PROVIDER . '.items.0.embedding')
                ?? $response->json('data.0.embedding');

            if (!is_array($vector) || count($vector) === 0) {
                Log::warning('EmbeddingService: leerer Vektor', [
                    'status'   => $response->status(),
                    'response' => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            return array_map('floatval', $vector);
        } catch (\Throwable $e) {
            Log::error('EmbeddingService::embed() fehlgeschlagen: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sucht den ähnlichsten Angle innerhalb einer Strategie per Cosine-Similarity.
     *
     * Gibt null zurück, wenn kein Embedding verfügbar oder keine Treffer.
     *
     * @param  float[]     $vector     Embedding des neuen Angles
     * @param  int         $strategyId Nur Angles dieser Strategie durchsuchen
     * @param  string|null $excludeId  Eigene Angle-ID ausschließen (bei Updates)
     * @return array{id: string, angle: string, similarity: float}|null
     */
    public function findMostSimilar(array $vector, int $strategyId, ?string $excludeId = null): ?array
    {
        $query = Angle::where('strategy_id', $strategyId)
            ->whereNotNull('embedding');

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $best = null;

        foreach ($query->get(['id', 'angle', 'embedding']) as $candidate) {
            $stored = json_decode($candidate->embedding, true);
            if (!is_array($stored) || count($stored) !== count($vector)) {
                continue;
            }

            $similarity = $this->cosineSimilarity($vector, $stored);

            if ($best === null || $similarity > $best['similarity']) {
                $best = [
                    'id'         => $candidate->id,
                    'angle'      => $candidate->angle,
                    'similarity' => round($similarity, 4),
                ];
            }
        }

        if ($best === null || $best['similarity'] < self::SIMILARITY_THRESHOLD) {
            return null; // nicht ähnlich genug
        }

        return $best;
    }

    /**
     * Cosine-Similarity zwischen zwei Vektoren (0..1 für gleiche Richtung).
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    public function getSimilarityThreshold(): float
    {
        return self::SIMILARITY_THRESHOLD;
    }
}
