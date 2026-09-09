<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\ContentKpi;
use App\Models\Strategy;
use App\Services\KpiLearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * KPI-Erfassung + Lernschleife-Auswertung.
 *
 * POST /content-kpis            — Messung für ein Content-Item erfassen/aktualisieren
 * GET  /content-kpis            — alle Messungen einer Strategie
 * GET  /content-kpis/learnings  — aggregierter Learning-Report (Performance-Board)
 */
class KpiController extends Controller
{
    public function __construct(private KpiLearningService $learning) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content_item_id' => 'required|string|exists:content_items,id',
            'measured_at' => 'nullable|date',
            'impressions' => 'nullable|integer|min:0',
            'reach' => 'nullable|integer|min:0',
            'clicks' => 'nullable|integer|min:0',
            'likes' => 'nullable|integer|min:0',
            'comments' => 'nullable|integer|min:0',
            'shares' => 'nullable|integer|min:0',
            'leads' => 'nullable|integer|min:0',
            'conversions' => 'nullable|integer|min:0',
            'revenue_eur' => 'nullable|numeric|min:0',
            'open_rate' => 'nullable|numeric|min:0|max:100',
            'click_rate' => 'nullable|numeric|min:0|max:100',
            'primary_metric' => 'nullable|string|max:50',
            'primary_value' => 'nullable|numeric',
            'notes' => 'nullable|string|max:2000',
        ]);

        $item = ContentItem::findOrFail($validated['content_item_id']);
        $measuredAt = $validated['measured_at'] ?? now()->toDateString();

        // KPI-Erfassung impliziert: das Item ist live
        if ($item->status !== 'live') {
            $item->update(['status' => 'live', 'live_date' => $item->live_date ?? $measuredAt]);
        }

        // Ein Eintrag pro (Item, Messdatum) — wo-clause über whereDate,
        // weil der date-Cast '2026-09-09' als '2026-09-09 00:00:00' speichert
        $attributes = array_merge(
            ['strategy_id' => $item->strategy_id],
            collect($validated)->except(['content_item_id', 'measured_at'])->all()
        );

        $kpi = ContentKpi::where('content_item_id', $item->id)
            ->whereDate('measured_at', $measuredAt)
            ->first();

        if ($kpi) {
            $kpi->update($attributes);
        } else {
            $kpi = ContentKpi::create(array_merge($attributes, [
                'content_item_id' => $item->id,
                'measured_at' => $measuredAt,
            ]));
        }

        return response()->json($kpi->load('contentItem'), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContentKpi::with('contentItem:id,title,format,variant_pattern,statement_type,icp,status', 'strategy:id,key,name');

        if ($request->filled('strategy')) {
            $strategy = Strategy::where('key', $request->input('strategy'))->first();
            $query->where('strategy_id', $strategy?->id ?? 0);
        }

        return response()->json($query->latest('measured_at')->paginate($request->input('per_page', 100)));
    }

    /**
     * Aggregierter Learning-Report für das Performance-Board.
     */
    public function learnings(Request $request): JsonResponse
    {
        $strategyKey = $request->input('strategy', 'viscale');
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();

        return response()->json([
            'strategy' => $strategy->only(['key', 'name']),
            'report' => $this->learning->report($strategy),
        ]);
    }
}
