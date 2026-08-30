<?php

namespace App\Http\Controllers;

use App\Models\Angle;
use App\Models\ContentItem;
use App\Models\ContentMedia;
use App\Models\RedaktionsplanEntry;
use App\Models\Strategy;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $strategies = Strategy::all();
        $defaultUnit = $strategies->first();

        $topAngles = Angle::with(['strategy', 'source'])
            ->whereNotNull('ranking_score')
            ->orderByDesc('ranking_score')
            ->limit(5)
            ->get();

        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = now()->endOfWeek()->toDateString();

        $weekPlan = RedaktionsplanEntry::with('contentItem.angle')
            ->whereBetween('planned_date', [$weekStart, $weekEnd])
            ->orderBy('planned_date')
            ->get();

        $stats = [
            'total_angles' => Angle::count(),
            'total_content' => ContentItem::count(),
            'total_media' => ContentMedia::count(),
            'content_by_status' => ContentItem::selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status'),
        ];

        return Inertia::render('Dashboard', [
            'strategies' => $strategies,
            'topAngles' => $topAngles,
            'weekPlan' => $weekPlan,
            'stats' => $stats,
        ]);
    }
}
