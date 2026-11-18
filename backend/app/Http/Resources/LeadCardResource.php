<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Compact lead for pipeline cards and dashboard lists.
 *
 * @mixin Lead
 */
class LeadCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'contact_name' => $this->contact_name,
            'company' => $this->company,
            'project_type' => $this->project_type?->value,
            'project_type_label' => $this->project_type?->label(),
            'city' => $this->city,
            'score' => $this->score,
            'temperature' => $this->temperature?->value,
            'status' => $this->status?->value,
            'estimated_value' => $this->estimated_value !== null ? (float) $this->estimated_value : null,
            'ai_next_action' => $this->ai_next_action,
            'phone' => $this->phone,
            'email' => $this->email,
            'owner' => $this->assigned_to ? new UserSummaryResource($this->whenLoaded('assignee')) : null,
            'position' => $this->position,
            'stage_entered_at' => $this->when(
                array_key_exists('stage_changed_at', $this->resource->getAttributes()),
                fn () => ($this->stage_changed_at ? Carbon::parse($this->stage_changed_at) : $this->created_at)?->toIso8601String(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
