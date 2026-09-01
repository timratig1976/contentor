<?php

namespace App\Services;

use App\Models\MonitoringEvent;
use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * EdenAI Universal-AI Web-Features (Firecrawl) über den bestehenden EdenAI-Key.
 */
class EdenAIWebService
{
    private const BASE = 'https://api.edenai.run/v3/universal-ai/';
    private const API_TIMEOUT = 90;

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? '';
    }

    public function configured(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * Web-Suche über EdenAI/Firecrawl.
     *
     * @return array{success: bool, results: array, cost: ?float, error: ?string}
     */
    public function search(string $query, array $options = []): array
    {
        $start = microtime(true);
        $input = array_merge(['query' => $query, 'depth' => 'standard'], $options);

        $payload = [
            'model' => 'web/search/firecrawl',
            'input' => $input,
            'show_original_response' => false,
        ];

        try {
            $response = $this->post(self::BASE, $payload);
            $duration = (int) round((microtime(true) - $start) * 1000);

            $result = $this->parseResponse($response, 'search', $payload, $duration, [
                'url' => null,
                'query' => $query,
            ]);

            if (! $result['success']) {
                return ['success' => false, 'results' => [], 'cost' => null, 'error' => $result['error']];
            }

            $output = $result['output'];

            return [
                'success' => true,
                'results' => $this->normalizeSearchResults($output),
                'cost' => $output['cost'] ?? null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $duration = (int) round((microtime(true) - $start) * 1000);
            $this->log('search', 'error', null, $query, 'web/search/firecrawl', null, $payload, $duration, $e->getMessage());

            return ['success' => false, 'results' => [], 'cost' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Scraping einer einzelnen URL über EdenAI/Firecrawl.
     *
     * @return array{success: bool, content: ?string, cost: ?float, error: ?string}
     */
    public function scrape(string $url, array $options = []): array
    {
        $start = microtime(true);
        $payload = [
            'model' => 'web/scraping/firecrawl',
            'input' => array_merge(['url' => $url], $options),
            'show_original_response' => false,
        ];

        try {
            $response = $this->post(self::BASE, $payload);
            $duration = (int) round((microtime(true) - $start) * 1000);

            $result = $this->parseResponse($response, 'scrape', $payload, $duration, [
                'url' => $url,
                'query' => null,
            ]);

            if (! $result['success']) {
                return ['success' => false, 'content' => null, 'cost' => null, 'error' => $result['error']];
            }

            $output = $result['output'];

            return [
                'success' => true,
                'content' => $this->extractContent($output),
                'cost' => $output['cost'] ?? null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $duration = (int) round((microtime(true) - $start) * 1000);
            $this->log('scrape', 'error', $url, null, 'web/scraping/firecrawl', null, $payload, $duration, $e->getMessage());

            return ['success' => false, 'content' => null, 'cost' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Async-Crawl über EdenAI/Firecrawl (job erstellen).
     */
    public function crawl(string $url, array $options = []): array
    {
        $start = microtime(true);
        $payload = [
            'model' => 'web/crawl_async/firecrawl',
            'input' => array_merge(['url' => $url], $options),
            'show_original_response' => false,
        ];

        try {
            $response = $this->post(self::BASE . 'async', $payload);
            $duration = (int) round((microtime(true) - $start) * 1000);

            $result = $this->parseResponse($response, 'crawl_start', $payload, $duration, [
                'url' => $url,
                'query' => null,
            ]);

            if (! $result['success']) {
                return ['success' => false, 'job_id' => null, 'error' => $result['error']];
            }

            $data = $response->json();

            return [
                'success' => true,
                'job_id' => $data['public_id'] ?? null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $duration = (int) round((microtime(true) - $start) * 1000);
            $this->log('crawl_start', 'error', $url, null, 'web/crawl_async/firecrawl', null, $payload, $duration, $e->getMessage());

            return ['success' => false, 'job_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Crawl-Job-Status pollen.
     */
    public function pollCrawlJob(string $jobId): array
    {
        try {
            $response = Http::timeout(self::API_TIMEOUT)
                ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
                ->get(self::BASE . 'async/' . $jobId);

            $data = $response->json() ?? [];
            $status = $data['status'] ?? null;

            if ($status === 'success') {
                $output = $data['output'] ?? [];

                return [
                    'status' => 'success',
                    'results' => $this->normalizeSearchResults($output, true),
                    'cost' => $output['cost'] ?? null,
                ];
            }

            if ($status === 'fail') {
                return ['status' => 'fail', 'results' => [], 'cost' => null, 'error' => $data['error'] ?? 'Job failed'];
            }

            return ['status' => 'processing', 'results' => [], 'cost' => null];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'results' => [], 'cost' => null, 'error' => $e->getMessage()];
        }
    }

    private function post(string $url, array $payload): Response
    {
        return Http::timeout(self::API_TIMEOUT)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($url, $payload);
    }

    private function parseResponse(Response $response, string $type, array $payload, int $durationMs, array $meta): array
    {
        if ($response->failed()) {
            $body = $response->body();
            $error = 'EdenAI HTTP ' . $response->status() . ': ' . mb_substr($body, 0, 300);

            $this->log($type, 'error', $meta['url'], $meta['query'], $payload['model'], null, $payload, $durationMs, $error);

            return ['success' => false, 'output' => null, 'error' => $error];
        }

        $data = $response->json() ?? [];

        if (($data['status'] ?? null) !== 'success') {
            $error = $data['error'] ?? 'Unbekannter Fehler';
            if (is_array($error)) {
                $error = json_encode($error);
            }

            $this->log($type, 'error', $meta['url'], $meta['query'], $payload['model'], $data['cost'] ?? null, $payload, $durationMs, $error);

            return ['success' => false, 'output' => null, 'error' => is_string($error) ? $error : json_encode($error)];
        }

        $this->log($type, 'success', $meta['url'], $meta['query'], $payload['model'], $data['cost'] ?? null, $payload, $durationMs, null);

        return ['success' => true, 'output' => $data, 'error' => null];
    }

    private function normalizeSearchResults(array $output, bool $fromCrawl = false): array
    {
        $results = $output['output']['results'] ?? $output['results'] ?? [];

        if (! is_array($results)) {
            return [];
        }

        return array_map(fn ($r) => [
            'title' => $r['title'] ?? null,
            'url' => $r['url'] ?? null,
            'content' => $r['content'] ?? ($fromCrawl ? ($r['markdown'] ?? null) : null),
        ], $results);
    }

    private function extractContent(array $output): ?string
    {
        $o = $output['output'] ?? [];

        return $o['markdown'] ?? $o['content'] ?? (is_array($o) ? json_encode($o) : (string) $o);
    }

    private function log(
        string $type,
        string $status,
        ?string $url,
        ?string $query,
        ?string $model,
        ?float $credits,
        array $payload,
        int $durationMs,
        ?string $error
    ): void {
        try {
            MonitoringEvent::create([
                'type' => $type,
                'status' => $status,
                'url' => $url,
                'query' => $query ? mb_substr($query, 0, 255) : null,
                'provider' => 'edenai/firecrawl',
                'model' => $model,
                'credits' => $credits,
                'input' => $payload['input'] ?? null,
                'payload' => $payload,
                'duration_ms' => $durationMs,
                'error' => $error ? mb_substr($error, 0, 480) : null,
            ]);
        } catch (\Throwable $e) {
            \Log::error('MonitoringEvent log failed: ' . $e->getMessage());
        }
    }
}
