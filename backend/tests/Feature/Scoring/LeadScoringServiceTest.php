<?php

namespace Tests\Feature\Scoring;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Events\HotLeadDetected;
use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Services\Scoring\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeadScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LeadScoringService
    {
        return app(LeadScoringService::class);
    }

    private function rules(int $score): array
    {
        return ['score' => $score, 'factors' => []];
    }

    private function enableAi(array|int|null $response): void
    {
        config(['services.openai.key' => 'sk-test', 'scoring.ai.retry_delay_ms' => 0]);

        Http::fake(['*' => is_array($response) || is_int($response)
            ? Http::response(OpenAiScorerTest::completion(is_int($response) ? ['score' => $response] : $response))
            : Http::response([], 500)]);
    }

    /** A lead whose rule score is exactly 100. */
    private function strongLead(array $overrides = []): Lead
    {
        return Lead::factory()->unscored()->create(array_merge([
            'source' => 'referral', 'project_type' => 'new_build', 'budget_min' => 600_000, 'budget_max' => 900_000,
            'timeline' => 'asap', 'postcode' => '10115', 'area_sqm' => 400,
            'description' => str_repeat('A detailed description of the project. ', 2),
        ], $overrides));
    }

    // ---- blend math ----------------------------------------------------

    public function test_blend_is_ai_60_rules_40_by_default(): void
    {
        $result = $this->service()->blend($this->rules(50), ['score' => 90]);

        $this->assertSame(74, $result->score); // 90×0.6 + 50×0.4
        $this->assertSame(50, $result->ruleScore);
        $this->assertSame(90, $result->aiScore);
        $this->assertSame(0.6, $result->aiWeight);
        $this->assertSame(LeadTemperature::Warm, $result->temperature);
    }

    public function test_blend_rounds_and_respects_admin_weight(): void
    {
        $this->assertSame(73, $this->service()->blend($this->rules(41), ['score' => 95])->score); // 57 + 16.4 = 73.4

        ScoringSetting::put('ai_weight', 0.5);
        $this->assertSame(70, $this->service()->blend($this->rules(50), ['score' => 90])->score);
    }

    public function test_rules_only_when_ai_missing(): void
    {
        $result = $this->service()->blend($this->rules(63), null);

        $this->assertSame(63, $result->score);
        $this->assertNull($result->aiScore);
        $this->assertFalse($result->usedAi());
        $this->assertSame('rules_only', $result->toBreakdown()['method']);
        $this->assertSame(0.0, $result->toBreakdown()['ai_weight']);
    }

    // ---- thresholds -----------------------------------------------------

    public function test_temperature_thresholds(): void
    {
        $cases = [100 => 'hot', 75 => 'hot', 74 => 'warm', 50 => 'warm', 49 => 'cold', 0 => 'cold'];
        foreach ($cases as $score => $expected) {
            $this->assertSame($expected, $this->service()->blend($this->rules($score), null)->temperature->value, "score {$score}");
        }
    }

    public function test_thresholds_follow_admin_overrides(): void
    {
        ScoringSetting::put('bands.hot', 85);
        ScoringSetting::put('bands.warm', 60);

        $this->assertSame(LeadTemperature::Warm, $this->service()->blend($this->rules(80), null)->temperature);
        $this->assertSame(LeadTemperature::Cold, $this->service()->blend($this->rules(55), null)->temperature);
    }

    // ---- end to end score() -------------------------------------------------

    public function test_score_without_api_key_uses_rules_and_persists_everything(): void
    {
        Event::fake([HotLeadDetected::class]);
        Http::fake();
        $lead = $this->strongLead();

        $result = $this->service()->score($lead);

        Http::assertNothingSent();
        $lead->refresh();
        $this->assertSame(100, $lead->score);
        $this->assertSame(100, $lead->rule_score);
        $this->assertNull($lead->ai_score);
        $this->assertSame(LeadTemperature::Hot, $lead->temperature);
        $this->assertNotNull($lead->scored_at);
        $this->assertSame('rules_only', $lead->score_breakdown['method']);
        $this->assertSame(30.0, (float) $lead->score_breakdown['rules']['budget']['points']);
        $this->assertStringContainsString('Scored by rules only', $lead->ai_summary);
        $this->assertSame(LeadScoringService::defaultNextAction(LeadTemperature::Hot), $lead->ai_next_action);
        $this->assertSame($result->score, $lead->score);

        $activity = $lead->activities()->where('type', 'score')->sole();
        $this->assertSame('Scored 100 · Hot', $activity->title);
        $this->assertNull($activity->user_id);
    }

    public function test_score_with_ai_blends_and_stores_ai_output(): void
    {
        Event::fake([HotLeadDetected::class]);
        $this->enableAi(['score' => 40, 'reasons' => ['Vague request'], 'summary' => 'AI summary.', 'next_action' => 'AI action.', 'project_type' => 'new_build']);
        $lead = $this->strongLead();

        $this->service()->score($lead);

        $lead->refresh();
        $this->assertSame(64, $lead->score); // 40×0.6 + 100×0.4
        $this->assertSame(40, $lead->ai_score);
        $this->assertSame(LeadTemperature::Warm, $lead->temperature);
        $this->assertSame('AI summary.', $lead->ai_summary);
        $this->assertSame('AI action.', $lead->ai_next_action);
        $this->assertSame('hybrid', $lead->score_breakdown['method']);
        $this->assertSame(['Vague request'], $lead->score_breakdown['ai']['reasons']);
        $this->assertSame('gpt-4o-mini', $lead->score_breakdown['model']);
    }

    public function test_ai_failure_falls_back_to_rules(): void
    {
        Event::fake([HotLeadDetected::class]);
        $this->enableAi(null); // 500s
        $lead = $this->strongLead();

        $this->service()->score($lead);

        $this->assertSame(100, $lead->fresh()->score);
        $this->assertSame('rules_only', $lead->fresh()->score_breakdown['method']);
    }

    public function test_ai_fills_missing_project_type(): void
    {
        Event::fake([HotLeadDetected::class]);
        $this->enableAi(['score' => 55, 'project_type' => 'roofing']);
        $lead = $this->strongLead(['project_type' => null]);

        $this->service()->score($lead);

        $this->assertSame('roofing', $lead->fresh()->project_type->value);
    }

    // ---- hot lead event ------------------------------------------------

    public function test_hot_event_fires_when_lead_becomes_hot(): void
    {
        Event::fake([HotLeadDetected::class]);
        $lead = $this->strongLead();

        $this->service()->score($lead);

        Event::assertDispatched(HotLeadDetected::class, fn ($e) => $e->lead->is($lead) && $e->result->score === 100);
    }

    public function test_hot_event_not_fired_again_for_already_hot_lead(): void
    {
        Event::fake([HotLeadDetected::class]);
        $lead = $this->strongLead(['temperature' => LeadTemperature::Hot, 'score' => 90]);

        $this->service()->score($lead);

        Event::assertNotDispatched(HotLeadDetected::class);
    }

    public function test_hot_event_not_fired_for_closed_or_non_hot_leads(): void
    {
        Event::fake([HotLeadDetected::class]);

        $this->service()->score($this->strongLead(['status' => LeadStatus::Won]));
        $this->service()->score(Lead::factory()->unscored()->create([
            'source' => 'portal', 'project_type' => 'repair', 'budget_min' => null, 'budget_max' => 9_000,
            'timeline' => 'just_researching', 'postcode' => '19348',
        ]));

        Event::assertNotDispatched(HotLeadDetected::class);
    }
}
