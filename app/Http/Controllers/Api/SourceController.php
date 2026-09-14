<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Source::with('strategy');

        if ($request->filled('strategy')) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('batch_key')) {
            $query->where('batch_key', $request->input('batch_key'));
        }

        $sources = $query->latest()->paginate($request->input('per_page', 50));

        return response()->json($sources);
    }

    public function store(Request $request): JsonResponse
    {
        $type = (string) $request->input('type', '');
        $urlRule = in_array($type, ['rss', 'url'], true) ? 'required|url' : 'nullable|url';

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:pdf,url,interview,intern,research,rss,community,screenshot',
            'strategy' => 'required|string|exists:strategies,key',
            'visibility' => 'string|in:intern,extern,partner',
            'file_ref' => 'nullable|string',
            'batch_key' => 'nullable|string',
            'url' => $urlRule,
            'monitor' => 'nullable|boolean',
            'frequency' => 'nullable|in:daily,weekly,biweekly',
            // RSS / Community-Metadaten
            'meta' => 'nullable|array',
            'meta.subreddit' => 'nullable|string|max:100',
            'meta.provider' => 'nullable|in:reddit,hackernews',
            'meta.query' => 'nullable|string|max:255',
            'meta.min_score' => 'nullable|integer|min:0|max:1000',
            'meta.keywords' => 'nullable|array',
            'meta.keywords.*' => 'string|max:100',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();

        $source = Source::create([
            'strategy_id' => $strategy->id,
            'title' => $validated['title'],
            'type' => $validated['type'],
            'visibility' => $validated['visibility'] ?? 'intern',
            'file_ref' => $validated['file_ref'] ?? null,
            'batch_key' => $validated['batch_key'] ?? null,
            'url' => $validated['url'] ?? null,
            'monitor' => $validated['monitor'] ?? in_array($validated['type'], ['rss', 'community'], true),
            'frequency' => $validated['frequency'] ?? 'daily',
            'meta' => $validated['meta'] ?? null,
        ]);

        // Bei RSS/Community: sofort erster Fetch (Items in Queue stellen)
        $firstFetch = null;
        if (in_array($source->type, ['rss', 'community'], true)) {
            $firstFetch = app(\App\Services\SourceIntelligenceService::class)->check($source);
        }

        return response()->json(array_merge(
            ['source' => $source->load('strategy')],
            $firstFetch ? ['first_fetch' => $firstFetch] : []
        ), 201);
    }

    public function angles(Source $source): JsonResponse
    {
        $angles = $source->angles()->with('strategy')->latest()->get();

        return response()->json([
            'source' => $source,
            'angles' => $angles,
            'total' => $angles->count(),
        ]);
    }

    /**
     * Manuelles Re-Crawlen einer URL-Quelle (Change-Detection via Hash).
     * Nur für Quellen vom Typ "url" mit hinterlegter URL.
     */
    public function crawl(Request $request, Source $source): JsonResponse
    {
        if ($source->type !== 'url' || empty($source->url)) {
            return response()->json([
                'message' => 'Nur Quellen mit hinterlegter URL können gecrawlt werden.',
            ], 422);
        }

        $result = app(\App\Services\SourceMonitorService::class)->checkSource($source);

        return response()->json([
            'source' => $source->fresh(),
            'crawl' => $result,
        ]);
    }

    /**
     * Quelle aktualisieren — inkl. Monitoring (URL, aktiv, Frequenz).
     * Beim (Re-)Aktivieren von Monitoring: sofortiger Erst-Crawl.
     */
    public function update(Request $request, Source $source): JsonResponse
    {
        $validated = $request->validate([
            'monitor' => 'sometimes|boolean',
            'frequency' => 'sometimes|in:daily,weekly,biweekly',
            'url' => 'sometimes|nullable|url',
            'batch_key' => 'sometimes|nullable|string',
        ]);

        $wasMonitored = (bool) $source->monitor;
        $source->update($validated);

        $startedFreshCrawl = false;
        $fresh = $source->fresh();

        // Nur crawlen, wenn tatsächlich eine URL vorhanden ist.
        if (! empty($fresh->url)) {
            // Neu überwacht (oder URL gewechselt) → sofort ersten Crawl ausführen
            if (($validated['monitor'] ?? false) && (! $wasMonitored || isset($validated['url']))) {
                app(\App\Services\SourceMonitorService::class)->checkSource($fresh);
                $startedFreshCrawl = true;
            } elseif (isset($validated['url']) && $fresh->monitor) {
                $fresh->update(['content_hash' => null, 'last_checked_at' => null]);
                app(\App\Services\SourceMonitorService::class)->checkSource($fresh->fresh());
                $startedFreshCrawl = true;
            }
        }

        if ($request->header('X-Inertia')) {
            return redirect()->back();
        }

        return response()->json([
            'source' => $source->fresh(),
            'crawled' => $startedFreshCrawl,
        ]);
    }
}
