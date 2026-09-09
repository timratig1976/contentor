<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentKpi;
use App\Models\Strategy;
use App\Services\KpiLearningService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OutputPageController extends Controller
{
    public function index(Request $request, KpiLearningService $kpiLearning): Response
    {
        $strategyKey = $request->input('strategy', 'viscale');
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();
        $strategies = Strategy::all();

        $items = ContentItem::with(['strategy', 'angle', 'media', 'persona'])
            ->where('strategy_id', $strategy->id)
            ->where('type', 'post')
            ->latest()
            ->get()
            ->map(function ($item) {
                $item->preview = $this->buildPreview($item);
                return $item;
            });

        // Neueste KPI-Messung pro Content-Item (für Badges in der Liste)
        $kpis = ContentKpi::where('strategy_id', $strategy->id)
            ->orderBy('measured_at')
            ->get()
            ->groupBy('content_item_id')
            ->map(fn ($group) => $group->last())
            ->values()
            ->keyBy('content_item_id');

        return Inertia::render('Output/Index', [
            'strategies' => $strategies,
            'currentStrategy' => $strategy,
            'items' => $items,
            'kpis' => $kpis,
            'learningReport' => $kpiLearning->report($strategy),
        ]);
    }

    private function buildPreview(ContentItem $item): array
    {
        $persona = $item->persona;
        $content = $item->content ?? '';
        $lines = explode("\n", $content);

        return [
            'linkedin' => [
                'author' => $persona?->name ?? $item->strategy->name,
                'role' => $persona?->role ?? 'Content Team',
                'avatar' => mb_substr($persona?->name ?? $item->strategy->name, 0, 1),
                'text' => $content,
                'hashtags' => $item->strategy->hashtags ?? [],
                'time' => $item->created_at->diffForHumans(),
            ],
            'newsletter' => [
                'subject' => $lines[0] ?? $item->title,
                'preview' => $lines[1] ?? '',
                'body' => implode("\n\n", array_slice($lines, 2)),
            ],
            'ad' => [
                'headline' => $lines[0] ?? '',
                'primary_text' => $lines[1] ?? '',
                'cta' => 'Mehr erfahren',
            ],
            'blog' => [
                'title' => $item->title ?? $lines[0] ?? '',
                'excerpt' => mb_substr($content, 0, 200),
                'body' => $content,
            ],
        ];
    }
}