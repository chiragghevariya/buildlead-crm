<?php

namespace App\Listeners;

use App\Enums\ActivityType;
use App\Enums\UserRole;
use App\Events\HotLeadDetected;
use App\Models\ScoringSetting;
use App\Models\User;
use App\Notifications\HotLeadNotification;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Hot lead alert → assigned rep + managers/admins (mail + in-app), optional Slack.
 */
class NotifyHotLead implements ShouldQueue
{
    public int $tries = 3;

    public function viaQueue(): string
    {
        return config('scoring.queues.notifications', 'notifications');
    }

    public function handle(HotLeadDetected $event): void
    {
        $lead = $event->lead->fresh(['assignee']);
        if (! $lead || ! $lead->isHot()) {
            return; // deleted or cooled down before the alert went out
        }

        $recipients = User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->whereIn('role', [UserRole::Admin->value, UserRole::Manager->value])
                ->when($lead->assigned_to, fn ($q) => $q->orWhere('id', $lead->assigned_to)))
            ->get();

        Notification::send($recipients, new HotLeadNotification($lead));

        $channels = ['mail', 'database'];
        if ($this->sendToSlack($lead)) {
            $channels[] = 'slack';
        }

        $lead->activities()->create([
            'user_id' => null,
            'type' => ActivityType::Note,
            'title' => '🔥 Hot lead alert sent',
            'body' => 'Notified '.$recipients->pluck('name')->implode(', ').'.',
            'meta' => ['channels' => $channels, 'recipients' => $recipients->pluck('id')->all()],
        ]);
    }

    private function sendToSlack($lead): bool
    {
        $url = ScoringSetting::config('alerts.slack_webhook_url');
        if (blank($url)) {
            return false;
        }

        $link = rtrim((string) config('app.frontend_url'), '/').'/leads/'.$lead->id;
        $details = collect([
            $lead->project_type?->label(),
            $lead->city,
            $lead->budget_max ? 'up to '.Money::format($lead->budget_max) : null,
        ])->filter()->implode(' · ');

        try {
            // No email/phone in Slack either — just enough to act on.
            $response = Http::timeout(5)->post($url, [
                'text' => "🔥 Hot lead {$lead->reference} (score {$lead->score}): ".($lead->company ?: $lead->contact_name),
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*🔥 Hot lead <{$link}|{$lead->reference}> — score {$lead->score}*\n".($lead->company ?: $lead->contact_name)." · {$details}"]],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => 'Assigned to '.($lead->assignee?->name ?? '_nobody yet_').($lead->ai_next_action ? ' · '.$lead->ai_next_action : '')]]],
                ],
            ]);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Slack hot lead alert failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
