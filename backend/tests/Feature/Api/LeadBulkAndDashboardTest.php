<?php

namespace Tests\Feature\Api;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadBulkAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_manager_bulk_assigns_and_logs(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->sales()->create(['name' => 'Anna Weber']);
        $leads = Lead::factory()->count(3)->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/leads/bulk', ['ids' => $leads->pluck('id'), 'action' => 'assign', 'assigned_to' => $rep->id])
            ->assertOk()->assertJsonPath('updated', 3);

        $this->assertSame(3, Lead::where('assigned_to', $rep->id)->count());
        $this->assertSame('Reassigned to Anna Weber', $leads[0]->activities()->sole()->title);
    }

    public function test_sales_cannot_bulk_assign_and_cannot_touch_foreign_leads(): void
    {
        $rep = User::factory()->sales()->create();
        $mine = Lead::factory()->assignedTo($rep)->create(['status' => LeadStatus::New]);
        $foreign = Lead::factory()->assignedTo(User::factory()->sales()->create())->create(['status' => LeadStatus::New]);

        $this->actingAs($rep, 'sanctum')
            ->postJson('/api/leads/bulk', ['ids' => [$mine->id], 'action' => 'assign', 'assigned_to' => $rep->id])
            ->assertForbidden();

        $this->actingAs($rep, 'sanctum')
            ->postJson('/api/leads/bulk', ['ids' => [$mine->id, $foreign->id], 'action' => 'status', 'status' => 'contacted'])
            ->assertForbidden();
        $this->assertSame(LeadStatus::New, $mine->fresh()->status); // all-or-nothing

        $this->actingAs($rep, 'sanctum')
            ->postJson('/api/leads/bulk', ['ids' => [$mine->id], 'action' => 'status', 'status' => 'contacted'])
            ->assertOk();
        $this->assertSame(LeadStatus::Contacted, $mine->fresh()->status);
        $this->assertNotNull($mine->fresh()->first_contacted_at);
    }

    public function test_bulk_status_validation(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create();

        $this->actingAs($manager, 'sanctum')->postJson('/api/leads/bulk', ['ids' => [$lead->id], 'action' => 'status', 'status' => 'lost'])
            ->assertJsonValidationErrors('lost_reason');
        $this->actingAs($manager, 'sanctum')->postJson('/api/leads/bulk', ['ids' => [], 'action' => 'explode'])
            ->assertJsonValidationErrors(['ids', 'action']);
        $this->actingAs($manager, 'sanctum')->postJson('/api/leads/bulk', ['ids' => [$lead->id, 99999], 'action' => 'status', 'status' => 'contacted'])
            ->assertNotFound();
    }

    public function test_dashboard_has_trends_recent_activity_and_callable_hot_leads(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::where('email', 'admin@buildlead.test')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/dashboard')->assertOk()
            ->assertJsonStructure([
                'kpis' => ['trends' => ['new_leads_pct', 'hot_leads_pct', 'pipeline_value_pct', 'win_rate_pts']],
                'leads_per_week' => [['value']],
                'recent_activity' => [['id', 'type', 'title', 'created_at', 'lead' => ['id', 'reference', 'name']]],
                'top_hot_leads' => [['phone', 'ai_next_action']],
            ]);

        $this->assertCount(8, $response->json('recent_activity'));
        $this->assertNotContains('score', collect($response->json('recent_activity'))->pluck('type'));
        $this->assertNotNull($response->json('kpis.trends.new_leads_pct'));
    }
}
