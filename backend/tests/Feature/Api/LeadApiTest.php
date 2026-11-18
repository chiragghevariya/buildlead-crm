<?php

namespace Tests\Feature\Api;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $rep;

    private User $otherRep;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->manager()->create();
        $this->rep = User::factory()->sales()->create(['name' => 'Anna Weber']);
        $this->otherRep = User::factory()->sales()->create(['name' => 'Jonas Fischer']);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    // ---- permissions ---------------------------------------------------------

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/leads')->assertUnauthorized();
        $this->getJson('/api/pipeline')->assertUnauthorized();
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_deactivated_users_lose_access(): void
    {
        $this->as(User::factory()->sales()->inactive()->create())->getJson('/api/leads')->assertForbidden();
    }

    public function test_sales_only_see_their_own_leads(): void
    {
        $mine = Lead::factory()->count(2)->assignedTo($this->rep)->create();
        $theirs = Lead::factory()->assignedTo($this->otherRep)->create();
        Lead::factory()->create(); // unassigned

        $this->as($this->rep)->getJson('/api/leads')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->as($this->rep)->getJson("/api/leads/{$mine[0]->id}")->assertOk();
        $this->as($this->rep)->getJson("/api/leads/{$theirs->id}")->assertForbidden();
        $this->as($this->rep)->patchJson("/api/leads/{$theirs->id}", ['company' => 'X'])->assertForbidden();
        $this->as($this->rep)->patchJson("/api/leads/{$theirs->id}/status", ['status' => 'contacted'])->assertForbidden();
        $this->as($this->rep)->postJson("/api/leads/{$theirs->id}/activities", ['type' => 'note', 'title' => 'x'])->assertForbidden();
        $this->as($this->rep)->postJson("/api/leads/{$theirs->id}/rescore")->assertForbidden();

        // owner filter cannot widen the scope
        $this->as($this->rep)->getJson("/api/leads?owner={$this->otherRep->id}")->assertJsonCount(0, 'data');
    }

    public function test_managers_and_admins_see_everything(): void
    {
        Lead::factory()->assignedTo($this->rep)->create();
        Lead::factory()->assignedTo($this->otherRep)->create();
        Lead::factory()->create();

        $this->as($this->manager)->getJson('/api/leads')->assertJsonPath('meta.total', 3);
        $this->as($this->admin)->getJson('/api/leads')->assertJsonPath('meta.total', 3);
    }

    public function test_only_managers_can_delete(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        $this->as($this->rep)->deleteJson("/api/leads/{$lead->id}")->assertForbidden();
        $this->as($this->manager)->deleteJson("/api/leads/{$lead->id}")->assertNoContent();

        $this->assertSoftDeleted($lead);
        $this->as($this->manager)->getJson("/api/leads/{$lead->id}")->assertNotFound();
    }

    // ---- index ----------------------------------------------------------------

    public function test_index_filters_search_sort_and_paginate(): void
    {
        $hot = Lead::factory()->hot()->assignedTo($this->rep)->create(['company' => 'Spreeblick Immobilien GmbH', 'source' => 'referral', 'score' => 91]);
        Lead::factory()->hot()->assignedTo($this->otherRep)->create(['score' => 80, 'source' => 'portal']);
        Lead::factory()->cold()->create(['status' => LeadStatus::Contacted, 'source' => 'portal']);
        Lead::factory()->count(3)->create(['temperature' => LeadTemperature::Warm, 'score' => 60, 'source' => 'email']);

        $this->as($this->admin);

        $this->getJson('/api/leads?temperature=hot&sort=-score')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $hot->id)
            ->assertJsonPath('summary', ['hot' => 2, 'warm' => 3, 'cold' => 1, 'unscored' => 0]);

        $this->getJson('/api/leads?temperature=hot,cold&source=portal')->assertJsonCount(2, 'data');
        $this->getJson('/api/leads?status=contacted')->assertJsonCount(1, 'data');
        $this->getJson("/api/leads?owner={$this->rep->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/leads?owner=unassigned')->assertJsonCount(4, 'data');
        $this->getJson('/api/leads?search=spreeblick')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $hot->id);
        $this->getJson('/api/leads?sort=score')->assertJsonPath('data.0.score', 30);

        $this->getJson('/api/leads?per_page=2&page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 6)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_invalid_sort_falls_back_safely(): void
    {
        Lead::factory()->create();

        $this->as($this->admin)->getJson('/api/leads?sort=-password;drop table leads')->assertOk();
    }

    public function test_resource_shape(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create(['budget_min' => 80000, 'budget_max' => 120000, 'street' => 'Kastanienallee 7', 'postcode' => '10437', 'city' => 'Berlin']);
        Quote::factory()->create(['lead_id' => $lead->id]);
        $lead->logActivity('note', 'Hello');

        $this->as($this->admin)->getJson('/api/leads')
            ->assertJsonPath('data.0.owner.name', 'Anna Weber')
            ->assertJsonPath('data.0.budget', ['min' => 80000, 'max' => 120000, 'midpoint' => 100000])
            ->assertJsonPath('data.0.address.full', 'Kastanienallee 7, 10437 Berlin')
            ->assertJsonMissingPath('data.0.score_breakdown');

        $this->as($this->admin)->getJson("/api/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['score_breakdown', 'activities' => [['id', 'type', 'title', 'user']], 'quotes' => [['number', 'total']]]])
            ->assertJsonPath('data.activities.0.title', 'Hello');
    }

    // ---- store / update --------------------------------------------------------

    public function test_store_creates_lead_and_queues_scoring(): void
    {
        $response = $this->as($this->manager)->postJson('/api/leads', [
            'contact_name' => 'Clara Neumann',
            'email' => 'clara@example.com',
            'source' => 'trade_fair',
            'project_type' => 'commercial_fit_out',
            'budget_min' => 300000,
            'budget_max' => 450000,
            'postcode' => '10117',
            'consent_given' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'new')->assertJsonPath('data.estimated_value', 450000);
        $lead = Lead::sole();
        $this->assertNotNull($lead->consent_at);
        $this->assertSame('created', $lead->activities()->sole()->type->value);
        Queue::assertPushed(ScoreLeadJob::class, fn ($job) => $job->lead->is($lead) && $job->autoAssign);
    }

    public function test_sales_own_the_leads_they_create_and_cannot_assign(): void
    {
        $this->as($this->rep)->postJson('/api/leads', ['contact_name' => 'A', 'phone' => '030 1234567', 'source' => 'phone_call'])
            ->assertCreated()
            ->assertJsonPath('data.owner.id', $this->rep->id);
        Queue::assertPushed(ScoreLeadJob::class, fn ($job) => ! $job->autoAssign);

        $this->as($this->rep)->postJson('/api/leads', ['contact_name' => 'B', 'phone' => '030 1234567', 'source' => 'phone_call', 'assigned_to' => $this->otherRep->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
    }

    public function test_store_validation(): void
    {
        $this->as($this->manager)->postJson('/api/leads', ['source' => 'carrier_pigeon', 'postcode' => 'abc', 'budget_min' => 50000, 'budget_max' => 10000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact_name', 'email', 'phone', 'source', 'postcode', 'budget_max']);
    }

    public function test_update_rescores_only_when_scoring_fields_change(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create(['budget_max' => 100000, 'budget_min' => 50000]);

        $this->as($this->rep)->patchJson("/api/leads/{$lead->id}", ['street' => 'Neue Straße 1'])
            ->assertOk()->assertJsonPath('meta.rescoring', false);
        Queue::assertNothingPushed();

        $this->as($this->rep)->patchJson("/api/leads/{$lead->id}", ['budget_max' => 250000])
            ->assertOk()->assertJsonPath('meta.rescoring', true);
        Queue::assertPushed(ScoreLeadJob::class, fn ($job) => $job->lead->is($lead) && ! $job->autoAssign);

        // PATCH budget validation uses the stored minimum
        $this->as($this->rep)->patchJson("/api/leads/{$lead->id}", ['budget_max' => 1000])->assertJsonValidationErrors('budget_max');
    }

    public function test_managers_can_reassign_and_it_is_logged(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        $this->as($this->rep)->patchJson("/api/leads/{$lead->id}", ['assigned_to' => $this->otherRep->id])->assertUnprocessable();

        $this->as($this->manager)->patchJson("/api/leads/{$lead->id}", ['assigned_to' => $this->otherRep->id])
            ->assertOk()
            ->assertJsonPath('data.owner.id', $this->otherRep->id);
        $this->assertSame('Reassigned to Jonas Fischer', $lead->activities()->where('type', 'assignment')->sole()->title);

        $this->as($this->manager)->patchJson("/api/leads/{$lead->id}", ['assigned_to' => $this->admin->id])
            ->assertJsonValidationErrors(['assigned_to' => 'active sales reps']);
    }

    // ---- status / activities / rescore -----------------------------------------------

    public function test_status_change_logs_activity_and_sets_first_contact(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create(['status' => LeadStatus::New, 'first_contacted_at' => null]);

        $this->as($this->rep)->patchJson("/api/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted')
            ->assertJsonPath('data.activities.0.title', 'New → Contacted');

        $lead->refresh();
        $this->assertNotNull($first = $lead->first_contacted_at);
        $change = $lead->activities()->where('type', 'status_change')->sole();
        $this->assertSame($this->rep->id, $change->user_id);
        $this->assertSame(['from' => 'new', 'to' => 'contacted'], $change->meta);

        $this->travel(2)->hours();
        $this->as($this->rep)->patchJson("/api/leads/{$lead->id}/status", ['status' => 'site_visit'])->assertOk();
        $this->assertEquals($first, $lead->fresh()->first_contacted_at); // unchanged
    }

    public function test_lost_requires_reason(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        $this->as($this->rep)->patchJson("/api/leads/{$lead->id}/status", ['status' => 'lost'])
            ->assertJsonValidationErrors('lost_reason');

        $this->as($this->rep)->patchJson("/api/leads/{$lead->id}/status", ['status' => 'lost', 'lost_reason' => 'Went with competitor'])
            ->assertOk()
            ->assertJsonPath('data.lost_reason', 'Went with competitor');

        $this->as($this->rep)->patchJson("/api/leads/{$lead->id}/status", ['status' => 'negotiation'])
            ->assertJsonPath('data.lost_reason', null);
    }

    public function test_add_activity_and_first_contact(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create(['first_contacted_at' => null]);

        // A future task doesn't count as a response.
        $this->as($this->rep)->postJson("/api/leads/{$lead->id}/activities", ['type' => 'call', 'title' => 'Call back', 'due_at' => now()->addDay()->toIso8601String()])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $this->rep->id);
        $this->assertNull($lead->fresh()->first_contacted_at);

        $this->as($this->rep)->postJson("/api/leads/{$lead->id}/activities", ['type' => 'call', 'title' => 'Intro call', 'body' => 'Went well'])
            ->assertCreated();
        $this->assertNotNull($lead->fresh()->first_contacted_at);

        // System types can't be forged.
        $this->as($this->rep)->postJson("/api/leads/{$lead->id}/activities", ['type' => 'status_change', 'title' => 'x'])
            ->assertJsonValidationErrors('type');
    }

    public function test_rescore_queues_job(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        $this->as($this->rep)->postJson("/api/leads/{$lead->id}/rescore")->assertStatus(202);

        Queue::assertPushedOn('scoring', ScoreLeadJob::class, fn ($job) => $job->lead->is($lead));
    }

    public function test_meta_lists_options(): void
    {
        $this->as($this->rep)->getJson('/api/meta')
            ->assertOk()
            ->assertJsonCount(7, 'statuses')
            ->assertJsonPath('statuses.2', ['value' => 'site_visit', 'label' => 'Site visit'])
            ->assertJsonCount(2, 'sales_reps')
            ->assertJsonPath('score_bands', ['hot' => 75, 'warm' => 50]);
    }
}
