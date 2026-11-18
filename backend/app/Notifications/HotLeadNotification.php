<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HotLeadNotification extends Notification
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;
        $title = $lead->company ?: $lead->contact_name;
        $isOwner = $lead->assigned_to === $notifiable->getKey();

        $mail = (new MailMessage)
            ->subject("🔥 Hot lead: {$title} — score {$lead->score}")
            ->greeting($isOwner ? "Hi {$notifiable->name}, this one's yours." : "Hi {$notifiable->name},")
            ->line("**{$lead->reference}** · {$lead->contact_name}".($lead->company ? " ({$lead->company})" : ''))
            ->line(collect([
                $lead->project_type?->label(),
                trim($lead->postcode.' '.$lead->city),
                $lead->budget_max ? 'up to '.Money::format($lead->budget_max) : null,
                $lead->timeline?->label(),
            ])->filter()->implode(' · '));

        if ($lead->ai_summary) {
            $mail->line($lead->ai_summary);
        }
        if ($lead->ai_next_action) {
            $mail->line('**Next step:** '.$lead->ai_next_action);
        }
        if (! $isOwner) {
            $mail->line('Assigned to: '.($lead->assignee?->name ?? 'nobody yet'));
        }

        return $mail->action('Open lead', $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'hot_lead',
            'lead_id' => $this->lead->id,
            'reference' => $this->lead->reference,
            'title' => 'Hot lead: '.($this->lead->company ?: $this->lead->contact_name),
            'score' => $this->lead->score,
            'summary' => $this->lead->ai_summary,
            'assigned_to' => $this->lead->assignee?->name,
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/leads/'.$this->lead->id;
    }
}
