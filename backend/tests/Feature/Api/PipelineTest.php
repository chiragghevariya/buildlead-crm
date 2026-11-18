<?php

namespace Tests\Feature\Api;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_columns_in_pipeline_order_with_counts_and_values(): void
    {
        Lead::factory()->count(2)->create(['status' => LeadStatus::New, 'estimated_value' => 100_000]);
        Lead::factory()->create(['status' => LeadStatus::Negotiation, 'estimated_value' => 250_000]);
        Lead::factory()->create(['status' => LeadStatus::Won, 'estimated_value' => 80_000]);

        $response = $this->actingAs(User::factory()->manager()->create(), 'sanctum')->getJson('/api/pipeline');

        $response->assertOk()
            ->assertJsonCount(7, 'columns')
            ->assertJsonPath('columns.*.status', ['new', 'contacted', 'site_visit', 'quote_sent', 'negotiation', 'won', 'lost'])
            ->assertJsonPath('columns.0.count', 2)
            ->assertJsonPath('columns.0.value', 200000)
            ->assertJsonCount(2, 'columns.0.leads')
            ->assertJsonPath('columns.1.count', 0)
            ->assertJsonPath('columns.4.value', 250000)
            ->assertJsonPath('columns.5.is_closed', true)
            ->assertJsonPath('totals', ['open_count' => 3, 'open_value' => 450000])
            ->assertJsonStructure(['columns' => [['leads' => [['id', 'reference', 'contact_name', 'score', 'temperature', 'estimated_value', 'owner']]]]]);
    }

    public function test_sales_see_only_their_cards_and_filters_apply(): void
    {
        $rep = User::factory()->sales()->create();
        Lead::factory()->hot()->assignedTo($rep)->create(['status' => LeadStatus::Contacted, 'estimated_value' => 50_000]);
        Lead::factory()->cold()->assignedTo($rep)->create(['status' => LeadStatus::Contacted, 'estimated_value' => 10_000]);
        Lead::factory()->hot()->create(['status' => LeadStatus::Contacted, 'estimated_value' => 999_000]);

        $this->actingAs($rep, 'sanctum')->getJson('/api/pipeline')
            ->assertJsonPath('columns.1.count', 2)
            ->assertJsonPath('columns.1.value', 60000);

        $this->actingAs($rep, 'sanctum')->getJson('/api/pipeline?temperature=hot')
            ->assertJsonPath('columns.1.count', 1)
            ->assertJsonPath('columns.1.value', 50000);
    }

    public function test_cards_are_ordered_by_position_and_moving_reorders_columns(): void
    {
        $manager = User::factory()->manager()->create();
        [$a, $b, $c] = collect(range(0, 2))->map(fn ($i) => Lead::factory()->create(['status' => LeadStatus::Contacted, 'position' => $i]))->all();
        $moving = Lead::factory()->create(['status' => LeadStatus::New, 'position' => 0]);

        // Drop "moving" into Contacted at slot 1.
        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/leads/{$moving->id}/status", ['status' => 'contacted', 'position' => 1])
            ->assertOk();

        $this->actingAs($manager, 'sanctum')->getJson('/api/pipeline')
            ->assertJsonPath('columns.1.leads.*.id', [$a->id, $moving->id, $b->id, $c->id])
            ->assertJsonPath('columns.1.leads.*.position', [0, 1, 2, 3])
            ->assertJsonPath('columns.0.count', 0);

        // Reorder within the same column: no status_change activity.
        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/leads/{$c->id}/status", ['status' => 'contacted', 'position' => 0])
            ->assertOk();

        $this->actingAs($manager, 'sanctum')->getJson('/api/pipeline')
            ->assertJsonPath('columns.1.leads.*.id', [$c->id, $a->id, $moving->id, $b->id]);
        $this->assertSame(0, $c->activities()->where('type', 'status_change')->count());
    }

    public function test_status_change_without_position_goes_to_top(): void
    {
        $manager = User::factory()->manager()->create();
        $existing = Lead::factory()->create(['status' => LeadStatus::SiteVisit, 'position' => 0]);
        $lead = Lead::factory()->create(['status' => LeadStatus::Contacted]);

        $this->actingAs($manager, 'sanctum')->patchJson("/api/leads/{$lead->id}/status", ['status' => 'site_visit'])->assertOk();

        $this->assertSame(0, $lead->fresh()->position);
        $this->assertSame(1, $existing->fresh()->position);
    }

    public function test_limit_caps_cards_but_not_counts(): void
    {
        Lead::factory()->count(5)->create(['status' => LeadStatus::New]);

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')->getJson('/api/pipeline?limit=2')
            ->assertJsonCount(2, 'columns.0.leads')
            ->assertJsonPath('columns.0.count', 5);
    }
}
