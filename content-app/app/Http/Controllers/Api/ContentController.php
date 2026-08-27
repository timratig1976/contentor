<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Angle;
use App\Models\ContentItem;
use App\Models\ContentMedia;
use App\Models\Persona;
use App\Models\Unit;
use App\Services\ContentRulesService;
use App\Services\MediaBriefingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function __construct(
        private ContentRulesService $rulesService,
        private MediaBriefingService $mediaService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ContentItem::with(['unit', 'angle', 'media']);

        if ($request->filled('unit')) {
            $query->whereHas('unit', fn ($q) => $q->where('key', $request->input('unit')));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('icp')) {
            $query->where('icp', $request->input('icp'));
        }

        $items = $query->latest()->paginate($request->input('per_page', 50));

        return response()->json($items);
    }

    public function storeIdee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'input' => 'required|string',
            'unit' => 'required|string|exists:units,key',
            'icp' => 'nullable|string',
            'source_type' => 'nullable|string',
        ]);

        $unit = Unit::where('key', $validated['unit'])->firstOrFail();

        // Auto-detect ICP and cluster
        $icp = $this->rulesService->guessIcp($validated['input'], $validated['icp'] ?? null, $unit);
        $cluster = $this->rulesService->pickPainCluster($validated['input'], $unit);
        $sourceType = $this->rulesService->guessSourceType($validated['input'], $validated['source_type'] ?? null);
        $statementType = $this->rulesService->pickStatementType(null, $sourceType, $validated['input']);

        // Match persona
        $persona = Persona::where('unit_id', $unit->id)->where('active', true)->first();

        $item = ContentItem::create([
            'unit_id' => $unit->id,
            'type' => 'idea',
            'content' => $validated['input'],
            'status' => 'idee',
            'icp' => $icp,
            'pain_cluster' => $cluster ? "{$cluster['code']} · {$cluster['name']}" : null,
            'statement_type' => $statementType,
            'persona_id' => $persona?->id,
            'owner' => $unit->config['rules']['defaultOwner'] ?? $unit->key,
        ]);

        return response()->json($item->load(['unit', 'media']), 201);
    }

    public function produzieren(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'angle_id' => 'required|string|exists:angles,id',
            'format' => 'required|string|in:linkedin_post,ad_copy,newsletter_acquisition,landing_page_headlines,newsletter_bk',
            'metric' => 'nullable|string',
            'mechanism' => 'nullable|string',
            'proofs' => 'nullable|string',
            'kpis' => 'nullable|string',
            'cta' => 'nullable|string',
            'unit' => 'nullable|string|exists:units,key',
        ]);

        $angle = Angle::with('unit')->findOrFail($validated['angle_id']);
        $unit = $angle->unit;

        // Get strategy context
        $strategyCtx = [];
        foreach ($unit->strategies as $strategy) {
            $strategyCtx[$strategy->key] = $strategy->content;
        }

        // Generate content text (placeholder — in production this calls an LLM)
        $content = $this->generateContent($angle, $validated, $strategyCtx);

        // Enforce tone
        $content = $this->rulesService->enforceTone($content, $validated['format'], $unit, $strategyCtx);

        // Create content item
        $item = ContentItem::create([
            'unit_id' => $unit->id,
            'angle_id' => $angle->id,
            'type' => 'post',
            'format' => $validated['format'],
            'title' => mb_substr($angle->angle, 0, 80),
            'content' => $content,
            'status' => 'in_produktion',
            'icp' => $angle->icp,
            'pain_cluster' => $angle->pain_cluster,
            'statement_type' => $angle->statement_type,
            'owner' => $unit->config['rules']['defaultOwner'] ?? $unit->key,
        ]);

        // Auto-create media briefings
        $briefings = $this->mediaService->buildBriefings($validated['format'], [
            'angle' => $angle->angle,
            'icp' => $angle->icp,
        ], $strategyCtx);

        foreach ($briefings as $briefing) {
            ContentMedia::create([
                'content_item_id' => $item->id,
                'unit_id' => $unit->id,
                'type' => $briefing['type'],
                'format' => $briefing['briefing']['generation_params']['aspect_ratio'] ?? null,
                'briefing' => $briefing['briefing'],
                'position' => $briefing['position'],
            ]);
        }

        return response()->json($item->load(['unit', 'angle', 'media']), 201);
    }

    public function update(Request $request, ContentItem $contentItem): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'status' => 'sometimes|string|in:' . implode(',', ContentItem::STATUSES),
            'format' => 'sometimes|string',
            'owner' => 'sometimes|string',
            'live_date' => 'sometimes|date',
            'icp' => 'sometimes|string',
            'persona_id' => 'sometimes|string',
        ]);

        $contentItem->update($validated);

        return response()->json($contentItem->load(['unit', 'angle', 'media']));
    }

    public function overview(Request $request): JsonResponse
    {
        $unitKey = $request->input('unit', 'viscale');
        $unit = Unit::where('key', $unitKey)->firstOrFail();

        $totalAngles = Angle::where('unit_id', $unit->id)->count();
        $topAngles = Angle::where('unit_id', $unit->id)
            ->whereNotNull('ranking_score')
            ->orderByDesc('ranking_score')
            ->limit(5)
            ->get();

        $totalItems = ContentItem::where('unit_id', $unit->id)->count();
        $byStatus = ContentItem::where('unit_id', $unit->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $totalMedia = ContentMedia::where('unit_id', $unit->id)->count();
        $mediaByStatus = ContentMedia::where('unit_id', $unit->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $upcomingPlan = \App\Models\RedaktionsplanEntry::where('unit_id', $unit->id)
            ->where('planned_date', '>=', now()->toDateString())
            ->orderBy('planned_date')
            ->limit(10)
            ->with('contentItem')
            ->get();

        return response()->json([
            'unit' => $unit->only(['key', 'name']),
            'stats' => [
                'total_angles' => $totalAngles,
                'total_content_items' => $totalItems,
                'total_media' => $totalMedia,
                'by_status' => $byStatus,
                'media_by_status' => $mediaByStatus,
            ],
            'top_angles' => $topAngles,
            'upcoming_plan' => $upcomingPlan,
        ]);
    }

    private function generateContent(Angle $angle, array $params, array $strategyCtx): string
    {
        // Placeholder for LLM-based content generation
        // In production, this calls the AI to generate the actual post
        $parts = [];
        $parts[] = $angle->angle;
        $parts[] = '';

        if (!empty($params['metric'])) {
            $parts[] = $params['metric'];
        }
        if (!empty($params['mechanism'])) {
            $parts[] = $params['mechanism'];
        }
        if (!empty($params['proofs'])) {
            $parts[] = $params['proofs'];
        }
        if (!empty($params['cta'])) {
            $parts[] = '';
            $parts[] = $params['cta'];
        }

        // Add hashtags
        $unit = $angle->unit;
        $hashtags = $unit->hashtags ?? [];
        if ($hashtags) {
            $parts[] = '';
            $parts[] = implode(' ', $hashtags);
        }

        return implode("\n", $parts);
    }
}
