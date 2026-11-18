<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full lead. List endpoints get the compact shape; call ->detailed() (show,
 * update, status change) to add score_breakdown, raw payload and relations.
 *
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(bool $detailed = true): static
    {
        $this->detailed = $detailed;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $min = $this->budget_min !== null ? (float) $this->budget_min : null;
        $max = $this->budget_max !== null ? (float) $this->budget_max : null;

        $data = [
            'id' => $this->id,
            'reference' => $this->reference,
            'contact_name' => $this->contact_name,
            'company' => $this->company,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),
            'project_type' => $this->project_type?->value,
            'project_type_label' => $this->project_type?->label(),
            'description' => $this->description,
            'budget' => [
                'min' => $min,
                'max' => $max,
                'midpoint' => $this->budgetMidpoint(),
            ],
            'timeline' => $this->timeline?->value,
            'timeline_label' => $this->timeline?->label(),
            'address' => [
                'street' => $this->street,
                'postcode' => $this->postcode,
                'city' => $this->city,
                'full' => collect([$this->street, trim($this->postcode.' '.$this->city)])->filter()->implode(', ') ?: null,
            ],
            'area_sqm' => $this->area_sqm,
            'score' => $this->score,
            'rule_score' => $this->rule_score,
            'ai_score' => $this->ai_score,
            'temperature' => $this->temperature?->value,
            'ai_summary' => $this->ai_summary,
            'ai_next_action' => $this->ai_next_action,
            'scored_at' => $this->scored_at?->toIso8601String(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'owner' => $this->assigned_to ? new UserSummaryResource($this->whenLoaded('assignee')) : null,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'first_contacted_at' => $this->first_contacted_at?->toIso8601String(),
            'estimated_value' => $this->estimated_value !== null ? (float) $this->estimated_value : null,
            'lost_reason' => $this->lost_reason,
            'position' => $this->position,
            'consent_given' => $this->consent_given,
            'consent_at' => $this->consent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->detailed) {
            $data['score_breakdown'] = $this->score_breakdown;
            $data['raw_payload'] = $this->raw_payload;
            $data['activities'] = LeadActivityResource::collection($this->whenLoaded('activities'));
            $data['quotes'] = QuoteSummaryResource::collection($this->whenLoaded('quotes'));
            $data['project'] = $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
                'status' => $this->project->status->value,
                'progress' => $this->project->progress,
            ] : null);
        }

        return $data;
    }
}
