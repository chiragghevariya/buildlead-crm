<?php

namespace Tests\Feature\Scoring;

use App\Models\Lead;
use App\Services\Scoring\OpenAiScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiScorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openai.key' => 'sk-test',
            'services.openai.base_url' => 'https://api.openai.test/v1',
            'scoring.ai.retry_delay_ms' => 0,
        ]);
    }

    public static function completion(array|string $content): array
    {
        return ['choices' => [['message' => ['content' => is_string($content) ? $content : json_encode($content)]]]];
    }

    private function lead(): Lead
    {
        return Lead::factory()->create([
            'contact_name' => 'Jürgen Möller',
            'email' => 'juergen.moeller@example.com',
            'phone' => '+49 30 9876543',
            'street' => 'Kastanienallee 12',
            'company' => 'Spreeblick Immobilien GmbH',
            'description' => 'Neubau Bürogebäude, Budget 1.200.000 €. Rückruf bitte unter 0171 2345678 oder privat@example.org.',
            'raw_payload' => ['secret' => 'x'],
        ]);
    }

    public function test_success_returns_normalised_result(): void
    {
        Http::fake(['api.openai.test/*' => Http::response(self::completion([
            'score' => 87,
            'reasons' => ['Large budget', 'Core Berlin location', '', 42],
            'summary' => 'Commercial new build, serious buyer.',
            'next_action' => 'Call today.',
            'project_type' => 'new_build',
        ]))]);

        $result = (new OpenAiScorer)->score($this->lead());

        $this->assertSame([
            'score' => 87,
            'reasons' => ['Large budget', 'Core Berlin location'],
            'summary' => 'Commercial new build, serious buyer.',
            'next_action' => 'Call today.',
            'project_type' => 'new_build',
        ], $result);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-test')
                && $request['model'] === 'gpt-4o-mini'
                && $request['response_format'] === ['type' => 'json_object'];
        });
    }

    public function test_no_personal_contact_data_is_sent(): void
    {
        Http::fake(['*' => Http::response(self::completion(['score' => 50]))]);

        (new OpenAiScorer)->score($this->lead());

        Http::assertSent(function (Request $request) {
            $body = $request->body();
            foreach (['juergen.moeller@example.com', '9876543', 'Jürgen', 'Möller', 'Kastanienallee', 'privat@example.org', '2345678', 'secret'] as $needle) {
                $this->assertStringNotContainsString($needle, $body, "'{$needle}' leaked to OpenAI");
            }
            // Useful project info still goes through (budget not mistaken for a phone number).
            $this->assertStringContainsString('1.200.000', $body);
            $this->assertStringContainsString('[phone]', $body);

            return true;
        });
    }

    public function test_score_is_clamped_and_unknown_project_type_dropped(): void
    {
        Http::fake(['*' => Http::response(self::completion(['score' => 150, 'project_type' => 'spaceship']))]);

        $result = (new OpenAiScorer)->score($this->lead());

        $this->assertSame(100, $result['score']);
        $this->assertNull($result['project_type']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_invalid_json_returns_null(): void
    {
        Http::fake(['*' => Http::response(self::completion('Sure! Here is my analysis: {score: high'))]);

        $this->assertNull((new OpenAiScorer)->score($this->lead()));
    }

    public function test_json_without_score_returns_null(): void
    {
        Http::fake(['*' => Http::response(self::completion(['summary' => 'no score here']))]);

        $this->assertNull((new OpenAiScorer)->score($this->lead()));
    }

    public function test_server_errors_are_retried_then_return_null(): void
    {
        Http::fake(['*' => Http::response(['error' => 'overloaded'], 503)]);

        $this->assertNull((new OpenAiScorer)->score($this->lead()));

        Http::assertSentCount(3); // 1 + 2 retries
    }

    public function test_retry_recovers_after_transient_error(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429)
            ->push(self::completion(['score' => 64]))]);

        $this->assertSame(64, (new OpenAiScorer)->score($this->lead())['score']);
        Http::assertSentCount(2);
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid key'], 401)]);

        $this->assertNull((new OpenAiScorer)->score($this->lead()));
        Http::assertSentCount(1);
    }

    public function test_connection_failure_returns_null(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertNull((new OpenAiScorer)->score($this->lead()));
    }

    public function test_disabled_without_api_key(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        $scorer = new OpenAiScorer;
        $this->assertFalse($scorer->isEnabled());
        $this->assertNull($scorer->score($this->lead()));
        Http::assertNothingSent();
    }
}
