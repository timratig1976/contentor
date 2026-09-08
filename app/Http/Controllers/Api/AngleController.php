<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Angle;
use App\Models\Strategy;
use App\Services\ContentRulesService;
use App\Services\EmbeddingService;
use App\Services\QuickInputAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AngleController extends Controller
{
    public function __construct(
        private ContentRulesService $rulesService,
        private EmbeddingService $embeddingService,
        private QuickInputAgentService $agentService,
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
        $angle = $this->persistAngle($strategy, $validated);

        return response()->json($angle->load(['strategy', 'source']), 201);
    }

    /**
     * Batch-Zulassung (Quick Input): selektierte Draft-Angles erst hier
     * in die angles-Tabelle schreiben — nach User-Approval.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'required|string|exists:strategies,key',
            'source_id' => 'nullable|string|exists:sources,id',
            'batch_key' => 'nullable|string',
            'angles' => 'required|array|min:1',
            'angles.*.angle' => 'required|string',
            'angles.*.icp' => 'nullable|string',
            'angles.*.pain_cluster' => 'nullable|string',
            'angles.*.statement_type' => 'nullable|string',
            'angles.*.funnel' => 'nullable|in:ToFu,MoFu,BoFu',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();

        $created = [];
        foreach ($validated['angles'] as $data) {
            $data['source_id'] = $data['source_id'] ?? $validated['source_id'] ?? null;
            $data['batch_key'] = $data['batch_key'] ?? $validated['batch_key'] ?? null;
            $created[] = $this->persistAngle($strategy, $data);
        }

        return response()->json([
            'created' => count($created),
            'angles' => $created,
        ], 201);
    }

    /**
     * Erzeugt einen Angle (inkl. ICP/Cluster-Ableitung, Funnel-Auflösung,
     * LLM-Scoring, Auto-Approve und Embedding/Duplikat-Check).
     */
    private function persistAngle(Strategy $strategy, array $data): Angle
    {
        $icp = $data['icp'] ?? $this->rulesService->guessIcp($data['angle'], null, $strategy);
        $painCluster = $data['pain_cluster'] ?? null;

        if (! $painCluster) {
            $cluster = $this->rulesService->pickPainCluster($data['angle'], $strategy);
            $painCluster = $cluster ? "{$cluster['code']} · {$cluster['name']}" : null;
        }

        $statementType = $data['statement_type']
            ?? $this->rulesService->pickStatementType(null, null, $data['angle']);

        // Funnel: explizit übergeben > default_funnel des ICPs (aus icp_definitions)
        $funnel = $data['funnel']
            ?? $this->rulesService->resolveIcpFunnel($strategy, $icp);

        $angle = Angle::create([
            'strategy_id' => $strategy->id,
            'source_id' => $data['source_id'] ?? null,
            'batch_key' => $data['batch_key'] ?? null,
            'angle' => $data['angle'],
            'icp' => $icp,
            'pain_cluster' => $painCluster,
            'statement_type' => $statementType,
            'funnel' => $funnel,
            'viscale_phase' => $data['viscale_phase'] ?? null,
        ]);

        // Automatisches LLM-Scoring + Auto-Approve.
        try {
            $score = $this->agentService->scoreAngle($angle->angle, $strategy, $icp, $painCluster);
            if ($score) {
                $angle->updateQuietly([
                    'r_zielgruppe'    => $score['r_zielgruppe'],
                    'r_viscale_fit'   => $score['r_viscale_fit'],
                    'r_schaerfe'      => $score['r_schaerfe'],
                    'r_timing'        => $score['r_timing'],
                    'score_reasoning' => $score['score_reasoning'] ?? null,
                ]);

                if (empty($score['icp_valid'])) {
                    $angle->icp = $this->rulesService->guessIcp($angle->angle, null, $strategy);
                }
                if (empty($score['cluster_valid'])) {
                    $cluster = $this->rulesService->pickPainCluster($angle->angle, $strategy);
                    $angle->pain_cluster = $cluster ? "{$cluster['code']} · {$cluster['name']}" : null;
                }
                $angle->saveQuietly();

                $angle->updateRanking();
                $angle->refresh();
            }
        } catch (\Throwable $e) {
            Log::warning("Scoring für Angle {$angle->id} fehlgeschlagen: {$e->getMessage()}");
        }

        // Embedding + Duplikat-Check
        $this->attachEmbeddingAndCheckDuplicate($angle);

        return $angle->fresh();
    }

    /**
     * Berechnet das Embedding für den Angle und prüft auf Duplikate.
     * Setzt embedding, duplicate_of_id und similarity_score.
     */
    private function attachEmbeddingAndCheckDuplicate(Angle $angle): void
    {
        $vector = $this->embeddingService->embed($angle->angle);
        if (!is_array($vector)) {
            return; // kein Key / API-Fehler → still skip
        }

        $angle->forceFill([
            'embedding' => json_encode($vector),
        ])->save();

        $similar = $this->embeddingService->findMostSimilar($vector, $angle->strategy_id, $angle->id);
        $angle->forceFill([
            'duplicate_of_id'  => $similar['id'] ?? null,
            'similarity_score' => $similar['similarity'] ?? null,
        ])->save();
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
            'score_reasoning' => 'sometimes|nullable|string',
        ]);

        $angleTextChanged = isset($validated['angle']) && $validated['angle'] !== $angle->angle;

        $angle->update($validated);

        // Auto-calculate ranking if any ranking criteria changed
        if (array_intersect(array_keys($validated), ['r_zielgruppe', 'r_viscale_fit', 'r_schaerfe', 'r_timing'])) {
            $angle->updateRanking();
            $angle->refresh();
        }

        // Angle-Text geändert → Embedding + Duplikat-Check neu
        if ($angleTextChanged) {
            $this->attachEmbeddingAndCheckDuplicate($angle);
            $angle->refresh();
        }

        return response()->json($angle->load(['strategy', 'source']));
    }

    public function destroy(Request $request, Angle $angle): JsonResponse
    {
        // Nur Angles löschen, zu denen noch KEIN Content produziert wurde.
        if ($angle->contentItems()->exists()) {
            return response()->json([
                'message' => 'Angle kann nicht gelöscht werden: Es existiert bereits Content.',
            ], 422);
        }

        $angle->delete();

        return response()->json([
            'deleted' => true,
            'message' => 'Angle gelöscht.',
        ]);
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
