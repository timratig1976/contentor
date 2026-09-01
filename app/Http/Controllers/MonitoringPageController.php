<?php

namespace App\Http\Controllers;

use App\Models\MonitoringEvent;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringPageController extends Controller
{
    public function index(): Response
    {
        $events = MonitoringEvent::latest()->limit(200)->get();

        $stats = [
            'total' => MonitoringEvent::count(),
            'last_7d' => MonitoringEvent::where('created_at', '>=', now()->subDays(7))->count(),
            'changed' => MonitoringEvent::where('status', 'changed')->count(),
            'errors' => MonitoringEvent::where('status', 'error')->count(),
            'credits_7d' => round((float) MonitoringEvent::where('created_at', '>=', now()->subDays(7))->sum('credits'), 4),
        ];

        $monitoredSources = \App\Models\Source::where('monitor', true)
            ->with('strategy')
            ->latest('last_checked_at')
            ->get();

        return Inertia::render('Monitoring/Index', [
            'events' => $events,
            'stats' => $stats,
            'monitoredSources' => $monitoredSources,
        ]);
    }
}
