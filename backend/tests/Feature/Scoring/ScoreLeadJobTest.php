<?php

namespace Tests\Feature\Scoring;

use App\Events\HotLeadDetected;
use App\Jobs\ScoreLeadJob;
use App\Listeners\NotifyHotLead;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\HotLeadNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScoreLeadJobTest extends TestCase
{
    use RefreshDatabase;

    private function hotLead(): Lead
    {
        return Lead::factory()->unscored()->create([
            'contact_name' => 'Petra Marx', 'email' => 'petra@example.com', 'phone' => '+49 30 1112223',
            'source' => 'referral', 'project_type' => 'new_build', 'budget_min' => 600_000, 'budget_max' => 900_000,
            'timeline' => 'asap', 'postcode' => '10115', 'area_sqm' => 400,
            'description' => str_repeat('A detailed description of the project. ', 2),
        ]);
    }

    public function test_job_configuration(): void
    {
        $job = new ScoreLeadJob($lead = Lead::factory()->create());

        $this->assertSame('scoring', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60], $job->backoff());
        $this->assertSame((string) $lead->id, $job->uniqueId());
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
    }

    public function test_job_is_unique_per_lead(): void
    {
        Queue::fake();
        $lead = Lead::factory()->create();

        ScoreLeadJob::dispatch($lead);
        ScoreLeadJob::dispatch($lead);
        ScoreLeadJob::dispatch(Lead::factory()->create());

        Queue::assertPushedOn('scoring', ScoreLeadJob::class);
        Queue::assertPushed(ScoreLeadJob::class, 2);
    }

    public function test_job_scores_then_assigns(): void
    {
        Event::fake([HotLeadDetected::class]);
        $rep = User::factory()->sales()->create(['regions' => ['10'], 'project_types' => ['new_build']]);
        $lead = $this->hotLead();

        ScoreLeadJob::dispatchSync($lead);

        $lead->refresh();
        $this->assertSame(100, $lead->score);
        $this->assertSame($rep->id, $lead->assigned_to);
        $this->assertSame(['score', 'assignment'], $lead->activities()->reorder('id')->pluck('type')->map->value->all());
    }

    public function test_hot_lead_alert_reaches_assignee_and_managers_after_assignment(): void
    {
        Notification::fake();
        $rep = User::factory()->sales()->create(['regions' => ['10'], 'project_types' => ['new_build']]);
        $otherRep = User::factory()->sales()->create(['regions' => ['16'], 'project_types' => ['repair']]);
        $manager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();
        User::factory()->manager()->inactive()->create();
        $lead = $this->hotLead();

        ScoreLeadJob::dispatchSync($lead);

        Notification::assertSentTo([$rep, $manager, $admin], HotLeadNotification::class,
            fn (HotLeadNotification $n, array $channels) => $n->lead->is($lead)
                && $channels === ['mail', 'database']
                && $n->lead->assignee?->is($rep)); // listener ran after the assignment was committed
        Notification::assertNotSentTo($otherRep, HotLeadNotification::class);
        Notification::assertCount(3);

        $this->assertTrue($lead->activities()->where('title', '🔥 Hot lead alert sent')->exists());
    }

    public function test_notification_payloads(): void
    {
        $rep = User::factory()->sales()->create(['regions' => ['10'], 'project_types' => ['new_build']]);
        $lead = $this->hotLead();
        ScoreLeadJob::dispatchSync($lead);
        $lead->refresh()->load('assignee');

        $notification = new HotLeadNotification($lead);
        $mail = $notification->toMail($rep);
        $data = $notification->toArray($rep);

        $this->assertStringContainsString('Hot lead', $mail->subject);
        $this->assertStringContainsString('score 100', $mail->subject);
        $this->assertSame('http://localhost:5190/leads/'.$lead->id, $mail->actionUrl);
        $this->assertSame($lead->reference, $data['reference']);
        $this->assertSame('hot_lead', $data['kind']);

        // Stored as a database notification for the in-app bell.
        $this->assertSame(1, $rep->notifications()->count());
    }

    public function test_slack_webhook_is_called_without_contact_details(): void
    {
        config(['scoring.alerts.slack_webhook_url' => 'https://hooks.slack.test/T000/B000/xyz']);
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);
        Notification::fake();
        User::factory()->sales()->create(['regions' => ['10'], 'project_types' => ['new_build']]);
        $lead = $this->hotLead();

        ScoreLeadJob::dispatchSync($lead);

        Http::assertSent(function (Request $request) use ($lead) {
            return str_starts_with($request->url(), 'https://hooks.slack.test/')
                && str_contains($request['text'], $lead->reference)
                && ! str_contains($request->body(), 'petra@example.com')
                && ! str_contains($request->body(), '1112223');
        });
        $this->assertContains('slack', $lead->activities()->where('title', '🔥 Hot lead alert sent')->sole()->meta['channels']);
    }

    public function test_slack_failure_does_not_break_alerts(): void
    {
        config(['scoring.alerts.slack_webhook_url' => 'https://hooks.slack.test/x']);
        Http::fake(['hooks.slack.test/*' => Http::response('nope', 500)]);
        Notification::fake();
        $manager = User::factory()->manager()->create();

        ScoreLeadJob::dispatchSync($this->hotLead());

        Notification::assertSentTo($manager, HotLeadNotification::class);
    }

    public function test_listener_is_queued_on_notifications_queue(): void
    {
        $this->assertSame('notifications', (new NotifyHotLead)->viaQueue());
        Event::fake();
        Event::assertListening(HotLeadDetected::class, NotifyHotLead::class);
    }
}
