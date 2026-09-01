<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoringEvent;
use App\Models\Source;
use App\Services\EdenAIWebService;
use App\Services\SourceMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monitoring-API: Event-Feed, manueller Monitoring-Run, Einzel-Checks,
 * Test-Suche und async-Crawl-Status.
 */
class MonitoringController extends Controller
{
    /**
     * Event-Feed (alle Search/Scrape/Crawl-Aufrufe).
     */
    public function index(Request $request): JsonResponse
    {
        $query = MonitoringEvent::query();

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $events = $query->latest()->paginate($request->input('per_page', 50));

        // Statistiken über die letzten 7 Tage
        $stats = [
            'total' => MonitoringEvent::count(),
            'last_7d' => MonitoringEvent::where('created_at', '>=', now()->subDays(7))->count(),
            'changed' => MonitoringEvent::where('status', 'changed')->count(),
            'errors' => MonitoringEvent::where('status', 'error')->count(),
            'credits_7d' => (float) MonitoringEvent::where('created_at', '>=', now()->subDays(7))->sum('credits'),
        ];

        return response()->json(['events' => $events, 'stats' => $stats]);
    }

    /**
     * Monitoring für alle fälligen Quellen ausführen.
     */
    public function run(Request $request): JsonResponse
    {
        $result = app(SourceMonitorService::class)->run(
            $request->input('strategy'),
            (bool) $request->input('force', false),
        );

        return response()->json($result);
    }

    /**
     * Einzelne Quelle sofort prüfen.
     */
    public function checkSource(Request $request, Source $source): JsonResponse
    {
        $result = app(SourceMonitorService::class)->checkSource($source->fresh());

        return response()->json($result);
    }

    /**
     * Test-Suche über EdenAI/Firecrawl (aus der UI, ohne Angle-Extraktion).
     */
    public function testSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:3|max:500',
        ]);

        $result = app(EdenAIWebService::class)->search($validated['query']);

        if (! $result['success']) {
            return response()->json(['error' => $result['error']], 502);
        }

        return response()->json([
            'results' => $result['results'],
            'cost' => $result['cost'],
        ]);
    }
}
