<?php

namespace App\Http\Resources;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'amount' => (float) $this->amount,
            'vat_amount' => (float) $this->vat_amount,
            'total' => (float) $this->total,
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'days_overdue' => $this->status === InvoiceStatus::Overdue && $this->due_at
                ? (int) $this->due_at->diffInDays(now()->startOfDay()) : null,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
            'pdf_url' => route('invoices.pdf', $this->id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
