<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class CorsTest extends TestCase
{
    private function preflight(string $uri, string $origin, string $method = 'GET', string $headers = 'authorization')
    {
        return $this->call('OPTIONS', $uri, server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => $headers,
        ]);
    }

    public function test_spa_origin_is_allowed_for_the_api(): void
    {
        $this->preflight('/api/leads', 'http://localhost:5173')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_foreign_origins_are_not_allowed_for_the_api(): void
    {
        $this->assertFalse(
            $this->preflight('/api/leads', 'https://evil.example')->headers->has('Access-Control-Allow-Origin')
        );
    }

    public function test_ingest_webhook_accepts_any_website(): void
    {
        $this->preflight('/api/ingest/website', 'https://www.buildlead-bau.example', 'POST', 'content-type,x-ingest-token')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Headers', 'content-type, accept, x-ingest-token, x-requested-with');
    }

    public function test_ingest_origins_can_be_restricted(): void
    {
        config(['services.leads.ingest_allowed_origins' => ['https://www.buildlead-bau.example']]);

        // With a single allowed origin the header is always that origin; the browser rejects the mismatch.
        $this->assertNotSame(
            'https://evil.example',
            $this->preflight('/api/ingest/website', 'https://evil.example', 'POST', 'x-ingest-token')->headers->get('Access-Control-Allow-Origin')
        );
        $this->preflight('/api/ingest/website', 'https://www.buildlead-bau.example', 'POST', 'x-ingest-token')
            ->assertHeader('Access-Control-Allow-Origin', 'https://www.buildlead-bau.example');
    }
}
