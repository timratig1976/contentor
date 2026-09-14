<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SourceInputQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval-Queue für automatische Quellen
 * (RSS-Feeds, OCR, Community …).
 *
 * - index: pending/done Items gruppiert mit Draft-Angles
 * - approve: ausgewählte Draft-Angles übernehmen (via bestehende AngleService-Logik)
 * - reject: Item ablehnen
 * - process: Queue-Verarbeitung manuell anstoßen (extractAngles)
 */
class SourceQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'done');

        $query = SourceInputQueue::with(['source:id,title,type', 'strategy:id,key,name'])
            ->latest()
            ->limit(50);

        $items = match ($status) {
            'pending'  => $query->where('status', 'pending')->get(),
            'rejected' => $query->where('status', 'rejected')->get(),
            default    => $query->whereIn('status', ['done', 'processing'])->get(),
        };

        return response()->json([
            'items' => $items,
            'counts' => [
                'pending' => SourceInputQueue::where('status', 'pending')->count(),
                'done' => SourceInputQueue::where('status', 'done')->count(),
                'rejected' => SourceInputQueue::where('status', 'rejected')->count(),
            ],
        ]);
    }

    /**
     * Queue-Verarbeitung anstoßen (LLM-Extraktion für pending Items).
     */
    public function process(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Artisan::call('sources:process-queue', [
            '--limit' => (int) $request->input('limit', 20),
        ]);

        return response()->json([
            'output' => \Illuminate\Support\Facades\Artisan::output(),
            'counts' => [
                'pending' => SourceInputQueue::where('status', 'pending')->count(),
                'done' => SourceInputQueue::where('status', 'done')->count(),
            ],
        ]);
    }

    /**
     * Ausgewählte Draft-Angles eines Queue-Items als echte Angles übernehmen.
     * Nutzt denselben Pfad wie Quick Input (inkl. ICP/Cluster-Auflösung, Funnel, Scoring).
     */
    public function approve(Request $request, SourceInputQueue $queueItem): JsonResponse
    {
        $validated = $request->validate([
            'angle_indexes' => 'nullable|array',        // welche Drafts (per Index); null = alle
            'angle_indexes.*' => 'integer|min:0',
            'batch_key' => 'nullable|string|max:255',
        ]);

        $drafts = (array) ($queueItem->extracted_angles ?? []);
        if ($drafts === []) {
            return response()->json(['message' => 'Keine Draft-Angles in diesem Item.'], 422);
        }

        $indexes = $validated['angle_indexes'] ?? array_keys($drafts);
        $selected = array_values(array_intersect_key($drafts, array_flip($indexes)));
        if ($selected === []) {
            return response()->json(['message' => 'Keine Angles ausgewählt.'], 422);
        }

        $strategy = $queueItem->strategy;
        $source = $queueItem->source;
        $batchKey = $validated['batch_key'] ?? ($queueItem->batch_key ?: 'queue-' . now()->format('Ymd'));

        return $this->approveDirect($selected, $strategy, $source, $batchKey, $queueItem);
    }

    private function approveDirect(array $selected, $strategy, $source, string $batchKey, SourceInputQueue $queueItem): JsonResponse
    {
        if (! $strategy) {
            return response()->json(['message' => 'Strategie nicht gefunden.'], 422);
        }

        $rules = app(\App\Services\ContentRulesService::class);
        $agent = app(\App\Services\QuickInputAgentService::class);
        $created = [];

        foreach ($selected as $draft) {
            $text = trim((string) ($draft['angle'] ?? ''));
            if ($text === '') {
                continue;
            }

            $icp = $draft['icp'] ?: $rules->guessIcp($text, null, $strategy);
            $cluster = null;
            if (! empty($draft['pain_cluster'])) {
                $cluster = $draft['pain_cluster'];
            } elseif ($c = $rules->pickPainCluster($text, $strategy)) {
                $cluster = "{$c['code']} · {$c['name']}";
            }
            $statementType = $draft['statement_type'] ?: $rules->pickStatementType(null, 'text', $text);
            $funnel = $rules->resolveIcpFunnel($strategy, $icp) ?? $rules->guessFunnel($text)['funnel'];

            $angle = \App\Models\Angle::create([
                'strategy_id' => $strategy->id,
                'source_id' => $source?->id,
                'angle' => mb_substr($text, 0, 500),
                'icp' => $icp,
                'pain_cluster' => $cluster,
                'statement_type' => $statementType,
                'funnel' => $funnel,
                'batch_key' => $batchKey,
                'status' => 'neu',
            ]);

            // Auto-Scoring wie im regulären Angle-Flow
            if ($score = $agent->scoreAngle($angle->angle, $strategy, $angle->icp, $angle->pain_cluster)) {
                $angle->update([
                    'r_zielgruppe' => $score['r_zielgruppe'],
                    'r_viscale_fit' => $score['r_viscale_fit'],
                    'r_schaerfe' => $score['r_schaerfe'],
                    'r_timing' => $score['r_timing'],
                    'score_reasoning' => $score['score_reasoning'],
                ]);
                $angle->updateRanking();
            }

            $created[] = $angle->id;
        }

        if ($created === []) {
            return response()->json(['message' => 'Keine verwertbaren Angles im Auswahl.'], 422);
        }

        $queueItem->update(['status' => 'rejected', 'extracted_angles' => null]);

        return response()->json([
            'created' => count($created),
            'angle_ids' => $created,
            'batch_key' => $batchKey,
        ], 201);
    }

    public function reject(Request $request, SourceInputQueue $queueItem): JsonResponse
    {
        $queueItem->update(['status' => 'rejected']);

        return response()->json(['rejected' => $queueItem->id]);
    }
}
