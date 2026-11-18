<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\IngestLeadRequest;
use App\Services\Leads\LeadIngestService;
use Illuminate\Http\JsonResponse;

/**
 * Public webhook: POST /api/ingest/{website|email|call}
 * Auth: X-Ingest-Token header. Rate limited per IP.
 */
class IngestController extends Controller
{
    private const CHANNELS = [
        'website' => LeadSource::WebsiteForm,
        'email' => LeadSource::Email,
        'call' => LeadSource::PhoneCall,
    ];

    public function __invoke(IngestLeadRequest $request, LeadIngestService $ingest, string $channel): JsonResponse
    {
        ['lead' => $lead, 'duplicate' => $duplicate] = $ingest->ingest(
            $request->leadData(),
            self::CHANNELS[$channel],
            $request->rawPayload,
        );

        // Deliberately minimal: this endpoint is public-facing.
        return response()->json([
            'status' => $duplicate ? 'duplicate' : 'created',
            'reference' => $lead->reference,
            'message' => 'Thank you! Our team will get back to you within one business day.',
        ], $duplicate ? 200 : 201);
    }

    /** @return list<string> */
    public static function channels(): array
    {
        return array_keys(self::CHANNELS);
    }
}
