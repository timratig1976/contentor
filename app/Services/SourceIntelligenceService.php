<?php

namespace App\Services;

use App\Models\Source;
use App\Models\SourceInputQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Source Intelligence: Typ-basiertes Abrufen von Inhalten aus automatisierten
 * Quellen (RSS-Feeds, Screenshots/OCR, Community …) und Einspeisen der neuen
 * Items in die SourceInputQueue zur späteren Angle-Extraktion + Approval.
 *
 * URL-Quellen bleiben bewusst beim bestehenden SourceMonitorService-Pfad
 * (Scrape + Hash-Change-Detection + direkte Heuristik), um das Verhalten
 * nicht zu ändern.
 */
class SourceIntelligenceService
{
    public function __construct(
        private EdenAIWebService $web,
    ) {}

    /**
     * Quelle nach Typ abrufen und neue Items in die Queue stellen.
     *
     * @return array{status: string, items_new?: int, angles_created?: int, error?: string}
     */
    public function check(Source $source): array
    {
        $result = match ($source->type) {
            'rss'        => $this->fetchRss($source),
            'screenshot' => $this->fetchScreenshot($source),
            'community'  => $this->fetchCommunity($source),
            default      => null,
        };

        $source->update(['last_checked_at' => now()]);

        if ($result === null) {
            return ['status' => 'unsupported', 'error' => "Typ '{$source->type}' wird vom Intelligence-Service nicht unterstützt."];
        }

        return $result;
    }

    /**
     * RSS/Atom-Feed ziehen, nur bisher unbekannte Items in die Queue stellen.
     * Bekannte GUIDs werden in source.meta['items'] geführt (max. 200).
     */
    private function fetchRss(Source $source): array
    {
        try {
            $response = Http::timeout(30)->withHeaders(['User-Agent' => 'contentor/1.0'])->get($source->url);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => 'Feed nicht erreichbar: ' . $e->getMessage()];
        }

        if (! $response->ok()) {
            return ['status' => 'error', 'error' => 'Feed HTTP ' . $response->status()];
        }

        libxml_use_internal_errors(true);
        $feed = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if (! $feed) {
            return ['status' => 'error', 'error' => 'Kein valides XML'];
        }

        $entries = $this->extractFeedEntries($feed);
        if ($entries === []) {
            return ['status' => 'error', 'error' => 'Keine Items im Feed gefunden (RSS oder Atom?)'];
        }

        $meta = $source->meta ?? [];
        $seenGuids = array_flip(array_column($meta['items'] ?? [], 'guid'));
        $batchKey = $source->batch_key ?: 'rss-' . now()->format('Ymd');

        $newCount = 0;
        $queued = [];
        foreach ($entries as $entry) {
            if (isset($seenGuids[$entry['guid']])) {
                continue;
            }

            SourceInputQueue::create([
                'source_id'   => $source->id,
                'strategy_id' => $source->strategy_id,
                'raw_content' => trim(($entry['title'] ? "## " . $entry['title'] . "\n\n" : '') . $entry['content']),
                'item_title'  => mb_substr($entry['title'], 0, 255),
                'item_url'    => $entry['url'],
                'item_guid'   => $entry['guid'],
                'batch_key'   => $batchKey,
                'status'      => 'pending',
            ]);

            $queued[] = ['guid' => $entry['guid'], 'title' => mb_substr($entry['title'], 0, 120), 'seen_at' => now()->toIso8601String()];
            $newCount++;
        }

        // Meta aktualisieren: letzte 200 GUIDs behalten
        $meta['items'] = array_slice(array_merge($meta['items'] ?? [], $queued), -200);
        $meta['feed_title'] = $meta['feed_title'] ?? (string) ($feed->channel->title ?? $feed->title ?? '');
        $meta['last_fetched_at'] = now()->toIso8601String();
        $source->update([
            'meta' => $meta,
            'last_content_preview' => mb_substr(collect($entries)->pluck('title')->take(5)->implode(' | '), 0, 480),
        ]);

        $this->logEvent('rss_fetch', $source, $newCount);

        return ['status' => $newCount > 0 ? 'changed' : 'unchanged', 'items_new' => $newCount, 'items_total' => count($entries)];
    }

    /**
     * RSS-2.0 (<channel><item>) und Atom (<entry>) gleichermaßen unterstützen.
     *
     * @return array<int,array{title:string,content:string,url:string,guid:string}>
     */
    private function extractFeedEntries(\SimpleXMLElement $feed): array
    {
        $entries = [];
        $isAtom = isset($feed->entry);

        $nodes = $isAtom ? $feed->entry : ($feed->channel->item ?? []);
        foreach ($nodes as $node) {
            if ($isAtom) {
                $title = (string) $node->title;
                $content = trim((string) ($node->summary ?? $node->content ?? ''));
                $url = (string) ($node->link['href'] ?? $node->id ?? '');
                $guid = (string) ($node->id ?? $url);
            } else {
                $title = (string) $node->title;
                $content = trim((string) ($node->description ?? ''));
                $url = (string) ($node->link ?? '');
                $guid = (string) ($node->guid ?? $url);
            }

            $content = strip_tags($content);
            if ($guid === '' && $url === '' && $title === '') {
                continue;
            }

            $entries[] = [
                'title' => trim($title),
                'content' => mb_substr($content, 0, 8000),
                'url' => trim($url),
                'guid' => $guid !== '' ? $guid : md5($title . $content),
            ];

            if (count($entries) >= 50) {
                break; // Schutz vor riesigen Feeds
            }
        }

        return $entries;
    }

    /**
     * Screenshot-Quelle: OCR via EdenAI (Google Vision, Amazon-Fallback),
     * erkannten Text in die Queue stellen.
     */
    private function fetchScreenshot(Source $source): array
    {
        if (! $this->web->configured()) {
            return ['status' => 'error', 'error' => 'EdenAI-Key fehlt (Einstellungen).'];
        }

        $imagePath = $this->resolveLocalPath($source);
        if (! $imagePath) {
            return ['status' => 'error', 'error' => 'Bild nicht gefunden: ' . ($source->file_ref ?? '?')];
        }

        $ocr = $this->web->ocr($imagePath);
        if (! $ocr['success']) {
            return ['status' => 'error', 'error' => $ocr['error']];
        }

        $text = trim($ocr['text']);
        if (mb_strlen($text) < 20) {
            return ['status' => 'unchanged', 'items_new' => 0]; // nichts erkannt
        }

        $meta = $source->meta ?? [];
        $hash = md5($text);
        if (($meta['ocr_hash'] ?? null) === $hash) {
            return ['status' => 'unchanged', 'items_new' => 0];
        }

        $meta['ocr_hash'] = $hash;
        $meta['ocr_confidence'] = $ocr['confidence'] ?? null;
        $meta['last_ocr_at'] = now()->toIso8601String();
        $source->update(['meta' => $meta]);

        SourceInputQueue::create([
            'source_id'   => $source->id,
            'strategy_id' => $source->strategy_id,
            'raw_content' => $text,
            'item_title'  => $source->title,
            'item_guid'   => $hash,
            'batch_key'   => $source->batch_key ?: 'ocr-' . now()->format('Ymd'),
            'status'      => 'pending',
        ]);

        $this->logEvent('ocr', $source, 1);

        return ['status' => 'changed', 'items_new' => 1];
    }

    /**
     * Community-Quellen (Reddit/HN): JSON-APIs (keyless), Keyword- + Score-Filter,
     * nur neue Post-IDs in die Queue.
     */
    private function fetchCommunity(Source $source): array
    {
        $meta = $source->meta ?? [];
        $provider = $meta['provider'] ?? 'reddit';

        $fetched = $provider === 'hackernews'
            ? $this->fetchHackerNews($meta)
            : $this->fetchReddit($meta);

        if (! $fetched['success']) {
            return ['status' => 'error', 'error' => $fetched['error']];
        }

        $seen = array_flip($meta['post_ids_seen'] ?? []);
        $newPosts = array_values(array_filter($fetched['posts'], fn ($p) => ! isset($seen[$p['guid']])));

        $batchKey = $source->batch_key ?: 'community-' . now()->format('Ymd');
        foreach ($newPosts as $post) {
            SourceInputQueue::create([
                'source_id'   => $source->id,
                'strategy_id' => $source->strategy_id,
                'raw_content' => trim($post['title'] . "\n\n" . ($post['content'] ?? '')),
                'item_title'  => mb_substr($post['title'], 0, 255),
                'item_url'    => $post['url'],
                'item_guid'   => $post['guid'],
                'batch_key'   => $batchKey,
                'status'      => 'pending',
            ]);
        }

        $meta['post_ids_seen'] = array_slice(
            array_merge(array_keys($seen), array_column($newPosts, 'guid')),
            -500
        );
        $meta['last_fetched_at'] = now()->toIso8601String();
        $source->update(['meta' => $meta]);

        $this->logEvent('community_fetch', $source, count($newPosts));

        return ['status' => count($newPosts) > 0 ? 'changed' : 'unchanged', 'items_new' => count($newPosts)];
    }

    private function fetchReddit(array $meta): array
    {
        $subreddit = $meta['subreddit'] ?? null;
        if (! $subreddit) {
            return ['success' => false, 'posts' => [], 'error' => 'meta.subreddit fehlt'];
        }
        $minScore = (int) ($meta['min_score'] ?? 5);
        $keywords = array_filter((array) ($meta['keywords'] ?? []));

        try {
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'contentor/1.0'])
                ->get("https://www.reddit.com/r/{$subreddit}/new.json?limit=25");
        } catch (\Throwable $e) {
            return ['success' => false, 'posts' => [], 'error' => $e->getMessage()];
        }

        if (! $response->ok()) {
            return ['success' => false, 'posts' => [], 'error' => 'Reddit HTTP ' . $response->status()];
        }

        $posts = [];
        foreach ($response->json('data.children') ?? [] as $child) {
            $d = $child['data'] ?? [];
            if (($d['score'] ?? 0) < $minScore) {
                continue;
            }
            $haystack = mb_strtolower(($d['title'] ?? '') . ' ' . ($d['selftext'] ?? ''));
            if ($keywords !== [] && ! collect($keywords)->contains(fn ($kw) => str_contains($haystack, mb_strtolower((string) $kw)))) {
                continue;
            }
            $posts[] = [
                'guid' => $d['id'],
                'title' => $d['title'] ?? '',
                'content' => mb_substr((string) ($d['selftext'] ?? ''), 0, 6000),
                'url' => 'https://www.reddit.com' . ($d['permalink'] ?? ''),
            ];
        }

        return ['success' => true, 'posts' => $posts];
    }

    private function fetchHackerNews(array $meta): array
    {
        $query = $meta['query'] ?? null;
        if (! $query) {
            return ['success' => false, 'posts' => [], 'error' => 'meta.query fehlt'];
        }
        $minScore = (int) ($meta['min_score'] ?? 10);

        try {
            $response = Http::timeout(30)->get('https://hn.algolia.com/api/v1/search', [
                'query' => $query, 'tags' => 'story', 'hitsPerPage' => 20, 'numericFilters' => "points>={$minScore}",
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'posts' => [], 'error' => $e->getMessage()];
        }

        if (! $response->ok()) {
            return ['success' => false, 'posts' => [], 'error' => 'HN HTTP ' . $response->status()];
        }

        $posts = [];
        foreach ($response->json('hits') ?? [] as $hit) {
            $posts[] = [
                'guid' => 'hn-' . ($hit['objectID'] ?? md5($hit['title'] ?? '')),
                'title' => $hit['title'] ?? '',
                'content' => mb_substr((string) ($hit['story_text'] ?? ''), 0, 6000),
                'url' => $hit['url'] ?: ('https://news.ycombinator.com/item?id=' . ($hit['objectID'] ?? '')),
            ];
        }

        return ['success' => true, 'posts' => $posts];
    }

    /**
     * file_ref (Storage-Pfad) zu einem realen Dateipfad auflösen.
     */
    private function resolveLocalPath(Source $source): ?string
    {
        $ref = $source->file_ref;
        if (! $ref) {
            return null;
        }
        $abs = storage_path('app/' . ltrim($ref, '/'));
        return file_exists($abs) ? $abs : null;
    }

    /**
     * Monitoring-Event loggen (konsistent mit EdenAIWebService-Logging).
     */
    private function logEvent(string $type, Source $source, int $items): void
    {
        try {
            \App\Models\MonitoringEvent::create([
                'type' => $type,
                'status' => 'success',
                'url' => $source->url,
                'provider' => 'contentor/intelligence',
                'payload' => ['source_id' => $source->id, 'items_new' => $items],
            ]);
        } catch (\Throwable $e) {
            Log::warning('MonitoringEvent-Log fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
