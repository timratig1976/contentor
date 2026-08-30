<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Angle;
use App\Models\Strategy;
use App\Services\ContentRulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AngleController extends Controller
{
    public function __construct(
        private ContentRulesService $rulesService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Angle::with(['strategy', 'source']);

        if ($request->filled('strategy')) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('batch')) {
            $query->where('batch_key', $request->input('batch'));
        }
        if ($request->filled('icp')) {
            $query->where('icp', $request->input('icp'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('funnel')) {
            $query->where('funnel', $request->input('funnel'));
        }
        if ($request->filled('min_score')) {
            $query->where('ranking_score', '>=', (int) $request->input('min_score'));
        }

        $sortField = $request->input('sort', 'created_at');
        $sortDir = $request->input('dir', 'desc');
        $query->orderBy($sortField, $sortDir);

        $angles = $query->paginate($request->input('per_page', 50));

        return response()->json($angles);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'angle' => 'required|string',
            'strategy' => 'required|string|exists:strategies,key',
            'icp' => 'nullable|string',
            'pain_cluster' => 'nullable|string',
            'statement_type' => 'nullable|string',
            'source_id' => 'nullable|string|exists:sources,id',
            'batch_key' => 'nullable|string',
            'funnel' => 'nullable|in:ToFu,MoFu,BoFu',
            'viscale_phase' => 'nullable|string',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();

        // Auto-detect ICP and pain cluster if not provided
        $icp = $validated['icp'] ?? $this->rulesService->guessIcp($validated['angle'], null, $strategy);
        $painCluster = $validated['pain_cluster'] ?? null;

        if (!$painCluster) {
            $cluster = $this->rulesService->pickPainCluster($validated['angle'], $strategy);
            $painCluster = $cluster ? "{$cluster['code']} · {$cluster['name']}" : null;
        }

        $statementType = $validated['statement_type']
            ?? $this->rulesService->pickStatementType(null, null, $validated['angle']);

        $angle = Angle::create([
            'strategy_id' => $strategy->id,
            'source_id' => $validated['source_id'] ?? null,
            'batch_key' => $validated['batch_key'] ?? null,
            'angle' => $validated['angle'],
            'icp' => $icp,
            'pain_cluster' => $painCluster,
            'statement_type' => $statementType,
            'funnel' => $validated['funnel'] ?? null,
            'viscale_phase' => $validated['viscale_phase'] ?? null,
        ]);

        return response()->json($angle->load(['strategy', 'source']), 201);
    }

    public function update(Request $request, Angle $angle): JsonResponse
    {
        $validated = $request->validate([
            'angle' => 'sometimes|string',
            'icp' => 'sometimes|string',
            'pain_cluster' => 'sometimes|string',
            'statement_type' => 'sometimes|string',
            'funnel' => 'sometimes|in:ToFu,MoFu,BoFu',
            'viscale_phase' => 'sometimes|string',
            'status' => 'sometimes|string',
            'r_zielgruppe' => 'sometimes|integer|min:1|max:3',
            'r_viscale_fit' => 'sometimes|integer|min:1|max:3',
            'r_schaerfe' => 'sometimes|integer|min:1|max:3',
            'r_timing' => 'sometimes|integer|min:1|max:3',
        ]);

        $angle->update($validated);

        // Auto-calculate ranking if any ranking criteria changed
        if (array_intersect(array_keys($validated), ['r_zielgruppe', 'r_viscale_fit', 'r_schaerfe', 'r_timing'])) {
            $angle->updateRanking();
            $angle->refresh();
        }

        return response()->json($angle->load(['strategy', 'source']));
    }

    public function batchRanking(Request $request, string $batchKey): JsonResponse
    {
        $query = Angle::with(['strategy', 'source'])
            ->where('batch_key', $batchKey)
            ->whereNotNull('ranking_score')
            ->orderByDesc('ranking_score');

        if ($request->filled('strategy')) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('min_score')) {
            $query->where('ranking_score', '>=', (int) $request->input('min_score'));
        }

        $angles = $query->get();

        return response()->json([
            'batch_key' => $batchKey,
            'angles' => $angles,
            'total' => $angles->count(),
        ]);
    }
}
