<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-secret check for the public lead webhook: header "X-Ingest-Token".
 */
class VerifyIngestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.leads.ingest_token');

        if ($expected === '') {
            return response()->json(['message' => 'Lead ingest is not configured.'], 503);
        }

        $given = (string) $request->header('X-Ingest-Token', '');

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid ingest token.'], 401);
        }

        return $next($request);
    }
}
