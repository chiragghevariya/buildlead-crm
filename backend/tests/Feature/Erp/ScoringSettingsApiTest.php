<?php

namespace Tests\Feature\Erp;

use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScoringSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private array $weights = ['budget' => 35, 'project_type' => 20, 'location' => 15, 'urgency' => 15, 'source' => 10, 'completeness' => 5];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
    }

    public function test_admin_reads_effective_settings_and_defaults(): void
    {
        $this->getJson('/api/settings/scoring')
            ->assertOk()
            ->assertJsonPath('weights.budget', 30)
            ->assertJsonPath('bands', ['hot' => 75, 'warm' => 50])
            ->assertJsonPath('ai_weight', 0.6)
            ->assertJsonPath('ai_available', false)
            ->assertJsonPath('defaults.weights.budget', 30)
            ->assertJsonPath('overridden', []);
    }

    public function test_update_stores_overrides_and_changes_scoring(): void
    {
        $this->putJson('/api/settings/scoring', [
            'weights' => $this->weights,
            'bands' => ['hot' => 80, 'warm' => 55],
            'ai_weight' => 0.5,
        ])->assertOk()
            ->assertJsonPath('weights.budget', 35)
            ->assertJsonPath('bands.hot', 80)
            ->assertJsonPath('ai_weight', 0.5)
            ->assertJsonPath('defaults.bands.hot', 75)
            ->assertJsonPath('rescore_queued', null);

        $this->assertSame(35, ScoringSetting::config('weights.budget'));
        $this->assertSame(80, ScoringSetting::config('bands.hot'));
        $this->assertSame(0.5, ScoringSetting::config('ai_weight'));
        // Unchanged values are not stored as overrides.
        $this->assertEqualsCanonicalizing(
            ['weights.budget', 'weights.urgency', 'bands.hot', 'bands.warm', 'ai_weight'],
            ScoringSetting::pluck('key')->all()
        );
    }

    public function test_setting_a_value_back_to_default_removes_the_override(): void
    {
        $this->putJson('/api/settings/scoring', ['ai_weight' => 0.7])->assertOk();
        $this->putJson('/api/settings/scoring', ['ai_weight' => 0.6])->assertOk()->assertJsonPath('overridden', []);

        $this->assertSame(0, ScoringSetting::count());
    }

    public function test_validation(): void
    {
        $this->putJson('/api/settings/scoring', ['weights' => array_merge($this->weights, ['budget' => 40])])
            ->assertJsonValidationErrors(['weights' => 'add up to 100 (currently 105)']);

        $this->putJson('/api/settings/scoring', ['weights' => ['budget' => 100]])
            ->assertJsonValidationErrors(['weights.project_type', 'weights.location']);

        $this->putJson('/api/settings/scoring', ['weights' => array_merge($this->weights, ['magic' => 0])])
            ->assertJsonValidationErrors('weights');

        $this->putJson('/api/settings/scoring', ['bands' => ['hot' => 50, 'warm' => 60]])
            ->assertJsonValidationErrors(['bands.hot' => 'higher than the warm threshold']);

        $this->putJson('/api/settings/scoring', ['ai_weight' => 1.5])->assertJsonValidationErrors('ai_weight');

        $this->assertSame(0, ScoringSetting::count());
    }

    public function test_rescore_option_queues_only_open_leads(): void
    {
        Lead::factory()->count(3)->create(['status' => LeadStatus::Contacted]);
        Lead::factory()->create(['status' => LeadStatus::Won]);
        Lead::factory()->create(['status' => LeadStatus::Lost]);

        $this->putJson('/api/settings/scoring', ['bands' => ['hot' => 70, 'warm' => 45], 'rescore' => true])
            ->assertOk()
            ->assertJsonPath('rescore_queued', 3);

        Queue::assertPushed(ScoreLeadJob::class, 3);
        Queue::assertPushed(ScoreLeadJob::class, fn ($job) => ! $job->autoAssign);

        $this->postJson('/api/settings/scoring/rescore')->assertStatus(202)->assertJsonPath('rescore_queued', 3);
    }

    public function test_reset_restores_defaults(): void
    {
        $this->putJson('/api/settings/scoring', ['weights' => $this->weights, 'ai_enabled' => false])->assertOk()
            ->assertJsonPath('ai_enabled', false);

        $this->postJson('/api/settings/scoring/reset')
            ->assertOk()
            ->assertJsonPath('weights.budget', 30)
            ->assertJsonPath('ai_enabled', true)
            ->assertJsonPath('overridden', []);
    }

    public function test_only_admins(): void
    {
        foreach ([User::factory()->manager()->create(), User::factory()->sales()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'sanctum');

            $this->getJson('/api/settings/scoring')->assertForbidden();
            $this->putJson('/api/settings/scoring', ['ai_weight' => 0.5])->assertForbidden();
            $this->postJson('/api/settings/scoring/rescore')->assertForbidden();
        }
    }
}
