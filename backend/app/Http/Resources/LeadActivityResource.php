<?php

namespace App\Http\Resources;

use App\Models\LeadActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeadActivity */
class LeadActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->title,
            'body' => $this->body,
            'meta' => $this->meta,
            'due_at' => $this->due_at?->toIso8601String(),
            'user' => $this->user_id ? new UserSummaryResource($this->whenLoaded('user')) : null, // null = system
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
