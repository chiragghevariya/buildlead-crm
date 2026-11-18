<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects invoiced_net / paid_net / outstanding_gross aggregates (see ProjectController::withTotals).
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $budget = (float) $this->budget;
        $invoiced = round((float) ($this->invoiced_net ?? 0), 2);
        $cap = round($budget * (float) config('company.invoice_budget_cap', 1.10), 2);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'site_address' => $this->site_address,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'progress' => $this->progress,
            'budget' => $budget,
            'spent' => (float) $this->spent,
            'budget_used_pct' => $this->budgetUsedPercent(),
            'invoiced' => $invoiced,
            'invoiced_pct' => $budget > 0 ? round($invoiced / $budget * 100, 1) : 0.0,
            'paid' => round((float) ($this->paid_net ?? 0), 2),
            'outstanding' => round((float) ($this->outstanding_gross ?? 0), 2), // gross, sent + overdue
            'invoiceable_remaining' => max(0.0, round($cap - $invoiced, 2)),
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'is_late' => $this->due_date !== null && $this->due_date->isPast() && $this->status->value !== 'completed',
            'manager' => $this->manager_id ? new UserSummaryResource($this->whenLoaded('manager')) : null,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id' => $this->lead->id,
                'reference' => $this->lead->reference,
                'contact_name' => $this->lead->contact_name,
                'company' => $this->lead->company,
            ] : null),
            'quote' => $this->whenLoaded('quote', fn () => $this->quote ? [
                'id' => $this->quote->id,
                'number' => $this->quote->number,
                'total' => (float) $this->quote->total,
                'accepted_at' => $this->quote->accepted_at?->toIso8601String(),
            ] : null),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
