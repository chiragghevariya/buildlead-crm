<?php

namespace Tests\Feature\Api;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_kpis_are_computed(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->sales()->create();

        Lead::factory()->hot()->assignedTo($rep)->create([
            'status' => LeadStatus::Contacted, 'estimated_value' => 200_000,
            'created_at' => now()->subHours(3), 'first_contacted_at' => now()->subHours(2), // 60 min
        ]);
        Lead::factory()->create([
            'temperature' => LeadTemperature::Warm, 'score' => 60, 'status' => LeadStatus::New, 'estimated_value' => 50_000,
            'created_at' => now()->subDays(40),
        ]);
        Lead::factory()->assignedTo($rep)->create(['status' => LeadStatus::Won, 'estimated_value' => 120_000]);
        Lead::factory()->assignedTo($rep)->create(['status' => LeadStatus::Lost]);
        Lead::factory()->assignedTo($rep)->create(['status' => LeadStatus::Lost]);

        $project = Project::factory()->create(['status' => 'in_progress']);
        Invoice::factory()->create(['project_id' => $project->id, 'status' => 'sent', 'amount' => 1000]);
        Invoice::factory()->create(['project_id' => $project->id, 'status' => 'overdue', 'amount' => 2000]);
        Invoice::factory()->create(['project_id' => $project->id, 'status' => 'paid', 'amount' => 5000]);

        $this->actingAs($manager, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('kpis.new_leads_30d.value', 4)
            ->assertJsonPath('kpis.new_leads_30d.previous', 1)
            ->assertJsonPath('kpis.open_hot_leads', 1)
            ->assertJsonPath('kpis.pipeline_value', 250000)
            ->assertJsonPath('kpis.win_rate.value', 33.3)
            ->assertJsonPath('kpis.avg_first_response_minutes.all', 60)
            ->assertJsonPath('kpis.outstanding_invoices', ['count' => 2, 'total' => 3570, 'overdue_count' => 1, 'overdue_total' => 2380])
            ->assertJsonPath('kpis.active_projects', 1)
            ->assertJsonCount(12, 'leads_per_week')
            ->assertJsonPath('leads_per_week.11.total', 4)
            ->assertJsonPath('top_hot_leads.0.estimated_value', 200000)
            ->assertJsonPath('leaderboard.0.user.id', $rep->id)
            ->assertJsonPath('leaderboard.0.won', 1)
            ->assertJsonPath('leaderboard.0.win_rate', 33.3);
    }

    public function test_sales_get_own_numbers_and_no_company_finance(): void
    {
        $rep = User::factory()->sales()->create();
        Lead::factory()->hot()->assignedTo($rep)->create(['status' => LeadStatus::New]);
        Lead::factory()->hot()->count(3)->create(['status' => LeadStatus::New]);

        $this->actingAs($rep, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('kpis.open_hot_leads', 1)
            ->assertJsonPath('kpis.outstanding_invoices', null)
            ->assertJsonPath('kpis.active_projects', null)
            ->assertJsonPath('leaderboard.0.is_me', true);
    }

    public function test_full_dashboard_on_demo_data(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::where('email', 'admin@buildlead.test')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/dashboard')->assertOk();

        $response->assertJsonStructure([
            'kpis' => ['new_leads_30d' => ['value', 'previous', 'change_pct'], 'open_hot_leads', 'pipeline_value',
                'win_rate' => ['value', 'won', 'closed'], 'avg_first_response_minutes' => ['all', 'hot'],
                'outstanding_invoices' => ['count', 'total', 'overdue_count', 'overdue_total'], 'active_projects'],
            'leads_per_week' => [['week_start', 'label', 'hot', 'warm', 'cold', 'total']],
            'by_source' => [['source', 'label', 'count', 'avg_score', 'won', 'conversion_pct']],
            'funnel' => [['stage', 'label', 'count', 'pct_of_total', 'pct_of_previous']],
            'top_hot_leads' => [['id', 'reference', 'score']],
            'leaderboard' => [['rank', 'user' => ['id', 'name', 'initials'], 'open_leads', 'hot_open', 'won', 'won_value', 'win_rate', 'avg_first_response_minutes']],
        ]);

        $funnel = collect($response->json('funnel'))->pluck('count');
        $this->assertSame(60, $funnel->first());
        $this->assertSame(6, $funnel->last());
        $this->assertSame($funnel->sortDesc()->values()->all(), $funnel->all(), 'funnel must never widen');
        $this->assertSame(60, collect($response->json('leads_per_week'))->sum('total'));
        $this->assertCount(6, $response->json('leaderboard'));
        $this->assertSame(6, collect($response->json('leaderboard'))->sum('won'));
    }
}
