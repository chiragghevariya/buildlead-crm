<?php

namespace Tests\Feature\Scoring;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\Lead;
use App\Models\User;
use App\Services\Assignment\LeadAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function rep(array $attributes = []): User
    {
        return User::factory()->sales()->create(array_merge([
            'regions' => ['10'],
            'project_types' => ['renovation'],
            'max_open_leads' => 10,
        ], $attributes));
    }

    private function newLead(array $attributes = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'postcode' => '10115',
            'project_type' => 'renovation',
            'temperature' => LeadTemperature::Warm,
            'score' => 60,
        ], $attributes));
    }

    private function giveOpenLeads(User $rep, int $count): void
    {
        Lead::factory()->count($count)->assignedTo($rep)->create(['status' => LeadStatus::Contacted]);
    }

    private function giveHistory(User $rep, int $won, int $lost): void
    {
        Lead::factory()->count($won)->assignedTo($rep)->create(['status' => LeadStatus::Won]);
        Lead::factory()->count($lost)->assignedTo($rep)->create(['status' => LeadStatus::Lost]);
    }

    private function assign(Lead $lead): ?User
    {
        return app(LeadAssignmentService::class)->assign($lead);
    }

    public function test_only_active_sales_reps_under_capacity_are_candidates(): void
    {
        User::factory()->admin()->create();
        User::factory()->manager()->create();
        $this->rep(['is_active' => false]);
        $full = $this->rep(['max_open_leads' => 2]);
        $this->giveOpenLeads($full, 2);
        $available = $this->rep(['regions' => ['99'], 'project_types' => []]);

        $this->assertTrue($available->is($this->assign($this->newLead())));
    }

    public function test_closed_leads_do_not_count_toward_capacity(): void
    {
        $rep = $this->rep(['max_open_leads' => 1]);
        $this->giveHistory($rep, 3, 3);

        $this->assertTrue($rep->is($this->assign($this->newLead())));
    }

    public function test_prefers_region_and_type_match_over_partial_matches(): void
    {
        $regionOnly = $this->rep(['project_types' => ['roofing']]);
        $typeOnly = $this->rep(['regions' => ['16']]);
        $both = $this->rep();
        $this->giveOpenLeads($both, 5); // busier, but still the best match

        $lead = $this->newLead();
        $this->assertTrue($both->is($this->assign($lead)));
        $this->assertSame('both', $lead->activities()->where('type', 'assignment')->sole()->meta['match']);
    }

    public function test_region_match_beats_type_match_and_falls_back_to_anyone(): void
    {
        $typeOnly = $this->rep(['regions' => ['16']]);
        $regionOnly = $this->rep(['project_types' => ['roofing']]);
        $this->assertTrue($regionOnly->is($this->assign($this->newLead())));

        $noMatchLead = $this->newLead(['postcode' => '03046', 'project_type' => 'interior']);
        $this->assertNotNull($this->assign($noMatchLead));
        $this->assertSame('none', $noMatchLead->activities()->where('type', 'assignment')->sole()->meta['match']);
    }

    public function test_hot_lead_goes_to_best_win_rate(): void
    {
        $closer = $this->rep();
        $this->giveHistory($closer, 4, 1);   // 80 %
        $this->giveOpenLeads($closer, 6);    // and busy
        $idle = $this->rep();
        $this->giveHistory($idle, 1, 3);     // 25 %

        $lead = $this->newLead(['temperature' => LeadTemperature::Hot, 'score' => 90]);

        $this->assertTrue($closer->is($this->assign($lead)));
        $this->assertStringContainsString('best win rate (80%)', $lead->activities()->where('type', 'assignment')->sole()->body);
    }

    public function test_warm_and_cold_leads_go_to_least_busy(): void
    {
        $closer = $this->rep();
        $this->giveHistory($closer, 4, 1);
        $this->giveOpenLeads($closer, 6);
        $idle = $this->rep();
        $this->giveOpenLeads($idle, 1);

        $this->assertTrue($idle->is($this->assign($this->newLead(['temperature' => LeadTemperature::Warm]))));
        $this->assertTrue($idle->is($this->assign($this->newLead(['temperature' => LeadTemperature::Cold]))));
    }

    public function test_assignment_persists_and_logs(): void
    {
        $rep = $this->rep();
        $lead = $this->newLead();

        $this->assign($lead);

        $lead->refresh();
        $this->assertSame($rep->id, $lead->assigned_to);
        $this->assertNotNull($lead->assigned_at);
        $this->assertSame('Assigned to '.$rep->name, $lead->activities()->where('type', 'assignment')->sole()->title);
    }

    public function test_returns_null_when_everyone_is_at_capacity(): void
    {
        $rep = $this->rep(['max_open_leads' => 1]);
        $this->giveOpenLeads($rep, 1);
        $lead = $this->newLead();

        $this->assertNull($this->assign($lead));
        $this->assertNull($lead->fresh()->assigned_to);
        $this->assertSame('no_capacity', $lead->activities()->where('type', 'assignment')->sole()->meta['reason']);
    }

    public function test_already_assigned_leads_are_kept_unless_forced(): void
    {
        $owner = $this->rep();
        $this->giveOpenLeads($owner, 5);
        $other = $this->rep();
        $lead = $this->newLead(['assigned_to' => $owner->id]);

        $this->assertTrue($owner->is(app(LeadAssignmentService::class)->assign($lead)));
        $this->assertTrue($other->is(app(LeadAssignmentService::class)->assign($lead, force: true)));
    }
}
