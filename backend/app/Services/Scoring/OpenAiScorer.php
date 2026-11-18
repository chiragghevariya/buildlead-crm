<?php

namespace App\Services\Scoring;

use App\Enums\ProjectType;
use App\Models\Lead;
use App\Models\ScoringSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks OpenAI (JSON mode) to judge a lead like an experienced head of sales.
 *
 * GDPR: only whitelisted project fields are sent (see payload()). No name,
 * email, phone, street or raw payload ever leaves the server.
 *
 * Returns null whenever AI scoring is unavailable or the answer is unusable,
 * so callers can fall back to rules only.
 */
class OpenAiScorer
{
    public function isEnabled(): bool
    {
        return (bool) ScoringSetting::config('ai.enabled', true) && filled(config('services.openai.key'));
    }

    public function model(): string
    {
        return (string) ScoringSetting::config('ai.model', 'gpt-4o-mini');
    }

    /**
     * @return array{score: int, reasons: list<string>, summary: string, next_action: string, project_type: ?string}|null
     */
    public function score(Lead $lead): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $ai = ScoringSetting::config('ai');

        try {
            $response = Http::baseUrl(rtrim((string) config('services.openai.base_url'), '/'))
                ->withToken((string) config('services.openai.key'))
                ->acceptJson()
                ->timeout((int) ($ai['timeout'] ?? 20))
                ->retry(
                    (int) ($ai['retries'] ?? 2) + 1,
                    (int) ($ai['retry_delay_ms'] ?? 800),
                    fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
                    throw: false,
                )
                ->post('/chat/completions', [
                    'model' => $this->model(),
                    'temperature' => (float) ($ai['temperature'] ?? 0.2),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => json_encode($this->payload($lead), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('OpenAI scoring request failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('OpenAI scoring returned an error', ['lead_id' => $lead->id, 'status' => $response->status()]);

            return null;
        }

        $parsed = $this->parse((string) $response->json('choices.0.message.content'));

        if ($parsed === null) {
            Log::warning('OpenAI scoring returned unusable JSON', ['lead_id' => $lead->id]);
        }

        return $parsed;
    }

    /**
     * The ONLY lead data sent to OpenAI. Whitelist — never add contact fields here.
     */
    public function payload(Lead $lead): array
    {
        return [
            'company_type' => $lead->company ? 'business' : 'private',
            'source' => $lead->source?->value,
            'project_type' => $lead->project_type?->value,
            'description' => $this->redact((string) $lead->description),
            'budget_min_eur' => $lead->budget_min !== null ? (float) $lead->budget_min : null,
            'budget_max_eur' => $lead->budget_max !== null ? (float) $lead->budget_max : null,
            'timeline' => $lead->timeline?->value,
            'postcode' => $lead->postcode,
            'city' => $lead->city,
            'area_sqm' => $lead->area_sqm,
            'has_phone' => filled($lead->phone),
            'has_email' => filled($lead->email),
        ];
    }

    /** Strip emails and phone numbers a customer may have typed into the free-text description. */
    private function redact(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text) ?? $text;

        // German numbers start with +49 / 0049 / 0 — so budgets like "1.200.000" are left intact.
        return preg_replace('/(?<![\w.])(?:\+49|0049|0)[\s\/\-()]*\d(?:[\s\/\-()]*\d){6,}/', '[phone]', $text) ?? $text;
    }

    private function systemPrompt(): string
    {
        $types = implode('|', ProjectType::values());

        return <<<PROMPT
        You are the head of sales at BuildLead Bau GmbH, a construction company in Berlin, Germany
        (service area: Berlin and Brandenburg; core postcodes start with 10, 12, 13, 14).
        Judge how likely this inbound lead is to become a profitable, winnable project and how
        urgently sales should act. Consider budget realism for the scope and area, project type,
        location/travel, urgency, lead source and how serious/specific the request reads.
        Descriptions may be English or German.

        Respond ONLY with a JSON object:
        {
          "score": integer 0-100,
          "reasons": array of 2-4 short strings (max 90 chars each, English),
          "summary": one sentence (max 200 chars, English) describing the opportunity,
          "next_action": one concrete instruction for the sales rep (max 140 chars),
          "project_type": one of {$types}
        }
        PROMPT;
    }

    /** Validate + normalise the model's JSON. Null if it isn't usable. */
    public function parse(string $content): ?array
    {
        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['score']) || ! is_numeric($data['score'])) {
            return null;
        }

        $reasons = collect(is_array($data['reasons'] ?? null) ? $data['reasons'] : [])
            ->filter(fn ($r) => is_string($r) && trim($r) !== '')
            ->map(fn (string $r) => mb_substr(trim($r), 0, 160))
            ->take(5)
            ->values()
            ->all();

        $projectType = is_string($data['project_type'] ?? null) && ProjectType::tryFrom($data['project_type'])
            ? $data['project_type']
            : null;

        return [
            'score' => (int) max(0, min(100, round((float) $data['score']))),
            'reasons' => $reasons,
            'summary' => is_string($data['summary'] ?? null) ? mb_substr(trim($data['summary']), 0, 400) : '',
            'next_action' => is_string($data['next_action'] ?? null) ? mb_substr(trim($data['next_action']), 0, 300) : '',
            'project_type' => $projectType,
        ];
    }
}
