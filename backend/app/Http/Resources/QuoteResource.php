<?php

namespace App\Http\Resources;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quote */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'subtotal' => (float) $this->subtotal,
            'vat_rate' => (float) $this->vat_rate,
            'vat_amount' => (float) $this->vat_amount,
            'total' => (float) $this->total,
            'valid_until' => $this->valid_until?->toDateString(),
            'is_expired' => $this->valid_until !== null && $this->valid_until->endOfDay()->isPast() && $this->status->value === 'sent',
            'sent_at' => $this->sent_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'notes' => $this->notes,
            'lead' => $this->whenLoaded('lead', fn () => [
                'id' => $this->lead->id,
                'reference' => $this->lead->reference,
                'contact_name' => $this->lead->contact_name,
                'company' => $this->lead->company,
                'email' => $this->lead->email,
                'status' => $this->lead->status->value,
            ]),
            'creator' => $this->created_by ? new UserSummaryResource($this->whenLoaded('creator')) : null,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit->value,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'sort' => $item->sort,
            ])),
            'items_count' => $this->whenCounted('items'),
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'code' => $this->project->code] : null),
            'pdf_url' => route('quotes.pdf', $this->id),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
