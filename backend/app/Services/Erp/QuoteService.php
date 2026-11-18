<?php

namespace App\Services\Erp;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuoteStatus;
use App\Enums\UserRole;
use App\Mail\QuoteMail;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use App\Services\Leads\LeadPipelineService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quote lifecycle: draft → sent → accepted (→ project) | declined | expired.
 */
class QuoteService
{
    public function __construct(private readonly LeadPipelineService $pipeline) {}

    /**
     * @param  array{items: list<array>, vat_rate?: float, valid_until?: string, notes?: string}  $data
     */
    public function create(Lead $lead, array $data, User $by): Quote
    {
        return DB::transaction(function () use ($lead, $data, $by) {
            $quote = $lead->quotes()->create([
                'created_by' => $by->id,
                'status' => QuoteStatus::Draft,
                'vat_rate' => $data['vat_rate'] ?? config('company.vat_rate'),
                'valid_until' => $data['valid_until'] ?? now()->addDays(config('company.quote_validity_days'))->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceItems($quote, $data['items']);

            $lead->activities()->create([
                'user_id' => $by->id,
                'type' => ActivityType::Quote,
                'title' => "Quote {$quote->number} drafted",
                'body' => 'Net '.Money::cents($quote->subtotal).' · '.count($data['items']).' line items',
                'meta' => ['quote_id' => $quote->id, 'status' => 'draft'],
            ]);

            return $quote;
        });
    }

    public function update(Quote $quote, array $data): Quote
    {
        $this->ensureStatus($quote, [QuoteStatus::Draft], 'Only draft quotes can be edited.');

        return DB::transaction(function () use ($quote, $data) {
            $quote->fill(collect($data)->only(['vat_rate', 'valid_until', 'notes'])->all())->save();

            if (array_key_exists('items', $data)) {
                $this->replaceItems($quote, $data['items']);
            } else {
                $quote->recalculate();
            }

            return $quote;
        });
    }

    /** Mark sent, move the lead to "Quote sent" and email the PDF. */
    public function send(Quote $quote, User $by, ?string $to = null, ?string $message = null): Quote
    {
        $this->ensureStatus($quote, [QuoteStatus::Draft, QuoteStatus::Sent], 'This quote can no longer be sent.');

        $lead = $quote->lead;
        $recipient = $to ?: $lead->email;
        if (blank($recipient)) {
            throw ValidationException::withMessages(['to' => 'The lead has no email address. Add one or provide "to".']);
        }

        DB::transaction(function () use ($quote, $lead, $by, $recipient) {
            $quote->forceFill([
                'status' => QuoteStatus::Sent,
                'sent_at' => now(),
                'valid_until' => $quote->valid_until && $quote->valid_until->isFuture()
                    ? $quote->valid_until
                    : now()->addDays(config('company.quote_validity_days')),
            ])->save();

            $lead->forceFill(['estimated_value' => $quote->subtotal])->save();

            // Only move forward: a lead already negotiating stays there.
            if ($this->rank($lead->status) < $this->rank(LeadStatus::QuoteSent)) {
                $this->pipeline->move($lead, LeadStatus::QuoteSent, $by);
            }

            $lead->activities()->create([
                'user_id' => $by->id,
                'type' => ActivityType::Quote,
                'title' => "Quote {$quote->number} sent",
                'body' => 'Emailed to '.$recipient.' · total '.Money::cents($quote->total).' · valid until '.$quote->valid_until->format('j M Y'),
                'meta' => ['quote_id' => $quote->id, 'status' => 'sent', 'to' => $recipient],
            ]);
        });

        Mail::to($recipient, $lead->contact_name)->queue(new QuoteMail($quote->fresh(['lead', 'creator', 'items']), $message));

        return $quote;
    }

    /** Customer accepted: lead → won, project created from the quote. Idempotent per quote. */
    public function accept(Quote $quote, User $by): Project
    {
        $this->ensureStatus($quote, [QuoteStatus::Sent], 'Only sent quotes can be accepted.');

        if ($quote->valid_until && $quote->valid_until->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['quote' => "Quote {$quote->number} expired on {$quote->valid_until->format('j M Y')}. Send an updated quote first."]);
        }

        return DB::transaction(function () use ($quote, $by) {
            $lead = $quote->lead;

            $quote->forceFill(['status' => QuoteStatus::Accepted, 'accepted_at' => now()])->save();

            // Competing offers for the same lead are off the table.
            $lead->quotes()->whereKeyNot($quote->id)
                ->whereIn('status', [QuoteStatus::Draft->value, QuoteStatus::Sent->value])
                ->update(['status' => QuoteStatus::Declined->value, 'updated_at' => now()]);

            $lead->forceFill(['estimated_value' => $quote->subtotal])->save();
            if ($lead->status !== LeadStatus::Won) {
                $this->pipeline->move($lead, LeadStatus::Won, $by);
            }

            $project = Project::create([
                'lead_id' => $lead->id,
                'quote_id' => $quote->id,
                'manager_id' => $this->projectManagerFor($by)?->id,
                'name' => $this->projectName($lead),
                'site_address' => collect([$lead->street, trim($lead->postcode.' '.$lead->city)])->filter()->implode(', ') ?: null,
                'status' => ProjectStatus::Planning,
                'progress' => 0,
                'budget' => $quote->subtotal,
                'spent' => 0,
            ]);

            $lead->activities()->create([
                'user_id' => $by->id,
                'type' => ActivityType::Quote,
                'title' => "Quote {$quote->number} accepted 🎉",
                'body' => "Project {$project->code} created · budget ".Money::cents($project->budget),
                'meta' => ['quote_id' => $quote->id, 'status' => 'accepted', 'project_id' => $project->id],
            ]);

            return $project;
        });
    }

    public function decline(Quote $quote, User $by, ?string $reason = null): Quote
    {
        $this->ensureStatus($quote, [QuoteStatus::Draft, QuoteStatus::Sent], 'Only open quotes can be declined.');

        DB::transaction(function () use ($quote, $by, $reason) {
            $quote->forceFill(['status' => QuoteStatus::Declined])->save();

            $quote->lead->activities()->create([
                'user_id' => $by->id,
                'type' => ActivityType::Quote,
                'title' => "Quote {$quote->number} declined",
                'body' => $reason,
                'meta' => ['quote_id' => $quote->id, 'status' => 'declined'],
            ]);
        });

        return $quote;
    }

    /** @param list<array{description: string, quantity: float, unit: string, unit_price: float}> $items */
    private function replaceItems(Quote $quote, array $items): void
    {
        $quote->items()->delete();

        foreach (array_values($items) as $sort => $item) {
            // line_total is computed by QuoteItem::saving()
            $quote->items()->create([
                'description' => trim($item['description']),
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'sort' => $sort,
            ]);
        }

        $quote->recalculate();
    }

    private function ensureStatus(Quote $quote, array $allowed, string $message): void
    {
        if (! in_array($quote->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function rank(LeadStatus $status): int
    {
        return $status === LeadStatus::Lost ? -1 : array_search($status, LeadStatus::pipeline(), true);
    }

    private function projectManagerFor(User $by): ?User
    {
        if ($by->isManager() && $by->is_active) {
            return $by;
        }

        return User::query()->where('is_active', true)->where('role', UserRole::Manager->value)->orderBy('id')->first()
            ?? User::query()->where('is_active', true)->where('role', UserRole::Admin->value)->orderBy('id')->first();
    }

    private function projectName(Lead $lead): string
    {
        $who = $lead->company ?: Str::afterLast($lead->contact_name, ' ');

        return collect([$lead->project_type?->label() ?? 'Project', $who, $lead->city])->filter()->implode(' · ');
    }
}
