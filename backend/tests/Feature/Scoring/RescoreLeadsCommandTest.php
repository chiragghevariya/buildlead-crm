<?php

namespace Tests\Feature\Scoring;

use App\Events\HotLeadDetected;
use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RescoreLeadsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_queues_only_unscored_leads(): void
    {
        Queue::fake();
        Lead::factory()->count(2)->create();
        $unscored = Lead::factory()->unscored()->create();

        $this->artisan('leads:rescore')->assertSuccessful();

        Queue::assertPushed(ScoreLeadJob::class, 1);
        Queue::assertPushed(ScoreLeadJob::class, fn ($job) => $job->lead->is($unscored));
    }

    public function test_all_option_queues_every_lead(): void
    {
        Queue::fake();
        Lead::factory()->count(3)->create();

        $this->artisan('leads:rescore --all')->assertSuccessful();

        Queue::assertPushed(ScoreLeadJob::class, 3);
    }

    public function test_lead_option_accepts_id_or_reference(): void
    {
        Queue::fake();
        [$a, $b, $c] = Lead::factory()->count(3)->create();

        $this->artisan('leads:rescore', ['--lead' => [$a->reference, (string) $c->id]])->assertSuccessful();

        Queue::assertPushed(ScoreLeadJob::class, 2);
        Queue::assertNotPushed(ScoreLeadJob::class, fn ($job) => $job->lead->is($b));
    }

    public function test_unknown_lead_fails(): void
    {
        $this->artisan('leads:rescore --lead=BL-1999-0001')->assertFailed();
    }

    public function test_sync_scores_immediately_and_prints_summary(): void
    {
        Event::fake([HotLeadDetected::class]);
        $lead = Lead::factory()->unscored()->create();

        $this->artisan('leads:rescore --sync --no-assign')
            ->expectsOutputToContain('Scoring 1 lead')
            ->assertSuccessful();

        $this->assertNotNull($lead->fresh()->scored_at);
        $this->assertNull($lead->fresh()->assigned_to);
    }
}
