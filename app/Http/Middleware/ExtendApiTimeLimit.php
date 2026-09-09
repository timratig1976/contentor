<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hebt das PHP-Ausführungslimit für API-Requests an.
 *
 * LLM-Calls (Content-Produktion mit Claude/GPT über EdenAI) dauern
 * regelmäßig 30–90+ Sekunden — das globale max_execution_time=30 der
 * lokalen php.ini killt solche Requests mitten im Call (Fatal Error),
 * und der Editor bleibt ohne Fehlermeldung leer.
 */
class ExtendApiTimeLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        // Unter CLI ein No-op (kein Limit), unter cli-server/fpm wirksam
        @set_time_limit(300);

        return $next($request);
    }
}
