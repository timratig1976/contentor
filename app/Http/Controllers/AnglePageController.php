<?php

namespace App\Http\Controllers;

use App\Models\Angle;
use App\Models\Strategy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnglePageController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Angle::with(['strategy', 'source'])->withCount('contentItems');

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

        $angles = $query->orderByDesc('ranking_score')->orderByDesc('created_at')->paginate(25)->withQueryString();

        // Benutzerdefinierte ICP-Namen (z. B. B2B-4 / B2B-5) aus den icp_definitions
        // der Strategie auflösen — ohne N+1.
        $customIcpNames = $this->resolveCustomIcpNames($angles->getCollection());

        if ($customIcpNames) {
            $angles->getCollection()->transform(function (Angle $angle) use ($customIcpNames) {
                if (isset($customIcpNames[$angle->icp])) {
                    $angle->setAttribute('icp_name', $customIcpNames[$angle->icp]);
                }
                return $angle;
            });
        }

        $strategies = Strategy::all();
        $batches = Angle::distinct()->whereNotNull('batch_key')->pluck('batch_key');

        // Strategie-spezifische ICPs laden (aus icp_definitions der aktiven Strategie)
        $activeStrategyKey = $request->input('strategy') ?: ($strategies->first()?->key ?? 'viscale');
        $activeStrategy = $strategies->firstWhere('key', $activeStrategyKey);
        $strategyIcps = [];
        if ($activeStrategy) {
            $stratContent = \App\Models\ContentStrategy::where('strategy_id', $activeStrategy->id)
                ->where('key', 'icp_definitions')->first()?->content;
            if (!empty($stratContent['icps'])) {
                foreach ($stratContent['icps'] as $item) {
                    if (!empty($item['key'])) {
                        $strategyIcps[] = [
                            'key' => $item['key'],
                            'name' => $item['name'] ?? $item['key'],
                        ];
                    }
                }
            }
            // Fallback auf rules.icpKeys
            if (empty($strategyIcps) && !empty($activeStrategy->config['rules']['icpKeys'])) {
                foreach ($activeStrategy->config['rules']['icpKeys'] as $k) {
                    $strategyIcps[] = ['key' => $k, 'name' => $k];
                }
            }
        }

        return Inertia::render('Angles/Index', [
            'angles' => $angles,
            'strategies' => $strategies,
            'batches' => $batches,
            'strategyIcps' => $strategyIcps,
            'filters' => $request->only(['strategy', 'batch', 'icp', 'status', 'funnel']),
        ]);
    }

    /**
     * Lädt die ICP-Definitionen (icp_definitions) aller beteiligten Strategien
     * und liefert eine Map `icpKey => Name` zurück.
     */
    private function resolveCustomIcpNames($angles): array
    {
        $strategyIds = $angles->pluck('strategy_id')->filter()->unique()->values();

        if ($strategyIds->isEmpty()) {
            return [];
        }

        $definitions = \App\Models\ContentStrategy::whereIn('strategy_id', $strategyIds)
            ->where('key', 'icp_definitions')
            ->get();

        $map = [];
        foreach ($definitions as $definition) {
            foreach ($definition->content['icps'] ?? [] as $icp) {
                if (! empty($icp['key']) && ! empty($icp['name'])) {
                    $map[$icp['key']] = $icp['name'];
                }
            }
        }

        return $map;
    }

    public function show(Angle $angle): Response
    {
        $angle->load(['strategy', 'source', 'contentItems.media']);

        // Benutzerdefinierten ICP-Namen (z. B. B2B-4 / B2B-5) auflösen.
        if ($angle->strategy_id) {
            $customNames = $this->resolveCustomIcpNames(collect([$angle]));
            if (isset($customNames[$angle->icp])) {
                $angle->setAttribute('icp_name', $customNames[$angle->icp]);
            }
        }

        // Globaler Template-Katalog — alle aktiven Templates stehen
        // jeder Strategie zur Verfügung (keine Strategie-Auswahl mehr).
        $templates = [];
        // Der Strategie zugeordnete Personas (für Stil-Auswahl beim Produzieren)
        $personas = [];
        if ($angle->strategy) {
            $templates = \App\Models\PostTemplate::where('active', true)
                ->orderBy('format')->orderBy('name')->get();

            $personas = $angle->strategy->personas()
                ->where('active', true)
                ->get(['personas.id', 'name', 'role'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'role' => $p->role,
                    'is_default' => (bool) $p->pivot?->is_default,
                ]);
        }

        return Inertia::render('Angles/Show', [
            'angle' => $angle,
            'templates' => $templates,
            'personas' => $personas,
        ]);
    }
}
