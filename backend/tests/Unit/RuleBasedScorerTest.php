<?php

namespace Tests\Unit;

use App\Models\Lead;
use App\Services\Scoring\RuleBasedScorer;
use Tests\TestCase;

class RuleBasedScorerTest extends TestCase
{
    private function perfect(array $overrides = []): Lead
    {
        return new Lead(array_merge([
            'source' => 'referral',
            'project_type' => 'new_build',
            'budget_min' => 600_000,
            'budget_max' => 800_000,
            'timeline' => 'asap',
            'postcode' => '10115',
            'city' => 'Berlin',
            'email' => 'a@example.com',
            'phone' => '+49 30 1234567',
            'area_sqm' => 300,
            'description' => str_repeat('Detailed project description. ', 3),
        ], $overrides));
    }

    private function factor(Lead $lead, string $factor): array
    {
        return (new RuleBasedScorer)->score($lead)['factors'][$factor];
    }

    public function test_weights_sum_to_100_and_every_factor_is_reported(): void
    {
        $this->assertSame(100, array_sum(config('scoring.weights')));

        $result = (new RuleBasedScorer)->score($this->perfect());

        $this->assertSame(['budget', 'project_type', 'location', 'urgency', 'source', 'completeness'], array_keys($result['factors']));
        foreach ($result['factors'] as $factor) {
            $this->assertArrayHasKey('note', $factor);
            $this->assertNotSame('', $factor['note']);
        }
    }

    public function test_perfect_lead_scores_100(): void
    {
        $this->assertSame(100, (new RuleBasedScorer)->score($this->perfect())['score']);
    }

    public function test_budget_bands(): void
    {
        $cases = [800_000 => 30.0, 300_000 => 27.0, 120_000 => 22.5, 60_000 => 18.0, 30_000 => 12.0, 12_000 => 7.5, 8_000 => 3.0];
        foreach ($cases as $max => $points) {
            $this->assertSame($points, $this->factor($this->perfect(['budget_min' => null, 'budget_max' => $max]), 'budget')['points'], "budget {$max}");
        }

        $none = $this->factor($this->perfect(['budget_min' => null, 'budget_max' => null]), 'budget');
        $this->assertSame(0.0, $none['points']);
        $this->assertSame('No budget given', $none['note']);

        $this->assertStringContainsString('€ 600.000–€ 800.000', $this->factor($this->perfect(), 'budget')['note']);
    }

    public function test_project_type_weights(): void
    {
        $this->assertSame(20.0, $this->factor($this->perfect(['project_type' => 'commercial_fit_out']), 'project_type')['points']);
        $this->assertSame(16.0, $this->factor($this->perfect(['project_type' => 'extension']), 'project_type')['points']);
        $this->assertSame(6.0, $this->factor($this->perfect(['project_type' => 'repair']), 'project_type')['points']);
        $this->assertSame(0.0, $this->factor($this->perfect(['project_type' => null]), 'project_type')['points']);
    }

    public function test_location_core_secondary_other_and_missing(): void
    {
        foreach (['10115', '12043', '13353', '14467'] as $pc) {
            $this->assertSame(15.0, $this->factor($this->perfect(['postcode' => $pc]), 'location')['points'], $pc);
        }
        foreach (['15230', '16225', '03046', '04916'] as $pc) {
            $this->assertSame(9.0, $this->factor($this->perfect(['postcode' => $pc]), 'location')['points'], $pc);
        }

        $outside = $this->factor($this->perfect(['postcode' => '19348', 'city' => 'Perleberg']), 'location');
        $this->assertSame(0.0, $outside['points']);
        $this->assertSame('19348 Perleberg — outside service area', $outside['note']);

        $this->assertSame(0.0, $this->factor($this->perfect(['postcode' => null]), 'location')['points']);
    }

    public function test_urgency_and_source(): void
    {
        $this->assertSame(20.0, $this->factor($this->perfect(['timeline' => 'asap']), 'urgency')['points']);
        $this->assertSame(13.0, $this->factor($this->perfect(['timeline' => 'within_6_months']), 'urgency')['points']);
        $this->assertSame(2.0, $this->factor($this->perfect(['timeline' => 'just_researching']), 'urgency')['points']);

        $this->assertSame(10.0, $this->factor($this->perfect(['source' => 'referral']), 'source')['points']);
        $this->assertSame(4.0, $this->factor($this->perfect(['source' => 'portal']), 'source')['points']);
        $this->assertSame('Source: Website form', $this->factor($this->perfect(['source' => 'website_form']), 'source')['note']);
    }

    public function test_completeness_lists_missing_fields(): void
    {
        $factor = $this->factor($this->perfect(['phone' => null, 'area_sqm' => null, 'description' => 'Too short']), 'completeness');

        $this->assertSame(2.5, $factor['points']); // 3 of 6 × 5
        $this->assertSame('3 of 6 key details (missing: phone, area, description)', $factor['note']);
    }

    public function test_custom_config_can_be_passed_in(): void
    {
        $config = config('scoring');
        $config['weights'] = ['budget' => 50, 'project_type' => 50, 'location' => 0, 'urgency' => 0, 'source' => 0, 'completeness' => 0];

        $result = (new RuleBasedScorer)->score($this->perfect(['project_type' => 'repair']), $config);

        $this->assertSame(65, $result['score']); // 50 + 50 × 0.3
    }
}
