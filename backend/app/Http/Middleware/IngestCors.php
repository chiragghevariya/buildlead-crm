<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before Laravel's HandleCors (which re-reads config/cors.php per request):
 * the ingest webhook is called from customer websites, so it gets its own origin
 * list while the authenticated API stays restricted to the SPA origins.
 */
class IngestCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/ingest/*')) {
            config([
                'cors.allowed_origins' => config('services.leads.ingest_allowed_origins', ['*']),
                'cors.allowed_methods' => ['POST', 'OPTIONS'],
                'cors.allowed_headers' => ['Content-Type', 'Accept', 'X-Ingest-Token', 'X-Requested-With'],
                'cors.supports_credentials' => false,
            ]);
        }

        return $next($request);
    }
}
