<?php

namespace Tests\Feature\Api;

use App\Enums\LeadStatus;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Leads\LeadPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAndPipelineExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_list_with_capacity_and_win_rate(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->sales()->create(['max_open_leads' => 4, 'regions' => ['10']]);
        Lead::factory()->count(2)->assignedTo($rep)->create(['status' => LeadStatus::Contacted]);
        Lead::factory()->assignedTo($rep)->create(['status' => LeadStatus::Won]);
        Lead::factory()->assignedTo($rep)->create(['status' => LeadStatus::Lost]);

        $row = collect($this->actingAs($manager, 'sanctum')->getJson('/api/team')->assertOk()->json('data'))->firstWhere('id', $rep->id);

        $this->assertSame(2, $row['open_leads']);
        $this->assertSame(50, $row['capacity_pct']);
        $this->assertEquals(50, $row['win_rate']);
        $this->assertSame(['10'], $row['regions']);
    }

    public function test_only_admin_edits_team_and_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->sales()->create();

        $this->actingAs($manager, 'sanctum')->patchJson("/api/team/{$rep->id}", ['max_open_leads' => 30])->assertForbidden();
        $this->actingAs(User::factory()->sales()->create(), 'sanctum')->getJson('/api/team')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum')->patchJson("/api/team/{$rep->id}", ['regions' => ['14', '10', '10'], 'project_types' => ['roofing'], 'max_open_leads' => 30])
            ->assertOk()->assertJsonPath('data.regions', ['10', '14'])->assertJsonPath('data.max_open_leads', 30);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/team/{$rep->id}", ['regions' => ['Berlin'], 'project_types' => ['castle']])
            ->assertJsonValidationErrors(['regions.0', 'project_types.0']);
        $this->actingAs($admin, 'sanctum')->patchJson("/api/team/{$admin->id}", ['is_active' => false])->assertStatus(422);

        $rep->createToken('t');
        $this->actingAs($admin, 'sanctum')->patchJson("/api/team/{$rep->id}", ['is_active' => false])->assertOk();
        $this->assertSame(0, $rep->tokens()->count());
    }

    public function test_pipeline_cards_know_when_they_entered_their_stage(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create(['status' => LeadStatus::New, 'created_at' => now()->subDays(10)]);
        $this->travel(-3)->days();
        app(LeadPipelineService::class)->move($lead, LeadStatus::Contacted, $manager);
        $this->travelBack();

        $card = $this->actingAs($manager, 'sanctum')->getJson('/api/pipeline')->json('columns.1.leads.0');

        $this->assertSame($lead->id, $card['id']);
        $this->assertEqualsWithDelta(now()->subDays(3)->timestamp, strtotime($card['stage_entered_at']), 5);
    }

    public function test_invoice_summary_includes_paid_this_month(): void
    {
        $this->actingAs(User::factory()->manager()->create(), 'sanctum');
        $project = Project::factory()->create();
        Invoice::factory()->create(['project_id' => $project->id, 'status' => 'paid', 'amount' => 1000, 'paid_at' => now()]);
        Invoice::factory()->create(['project_id' => $project->id, 'status' => 'paid', 'amount' => 5000, 'paid_at' => now()->subMonths(2)]);

        $this->getJson('/api/invoices')->assertJsonPath('summary.paid_this_month', ['count' => 1, 'total' => 1190]);
    }
}
