<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $strategies = \App\Models\Strategy::all();
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'strategies' => $strategies,
            'activeCampaign' => $request->input('strategy', $strategies->first()?->key ?? 'viscale'),
        ];
    }
}
