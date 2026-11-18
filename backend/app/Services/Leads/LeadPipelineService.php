<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Status changes and Kanban ordering.
 */
class LeadPipelineService
{
    /** Activity types that count as having contacted the customer. */
    public const CONTACT_TYPES = ['call', 'email', 'meeting', 'site_visit'];

    public function move(Lead $lead, LeadStatus $to, User $by, ?int $position = null, ?string $lostReason = null): Lead
    {
        return DB::transaction(function () use ($lead, $to, $by, $position, $lostReason) {
            $from = $lead->status;

            if ($from !== $to) {
                $lead->status = $to;
                $lead->lost_reason = $to === LeadStatus::Lost ? $lostReason : null;

                // Any move out of "new" means someone has engaged with the customer.
                if ($to !== LeadStatus::New && ! $lead->first_contacted_at) {
                    $lead->first_contacted_at = now();
                }

                $lead->save();

                $lead->activities()->create([
                    'user_id' => $by->id,
                    'type' => ActivityType::StatusChange,
                    'title' => $from->label().' → '.$to->label(),
                    'body' => $to === LeadStatus::Lost ? 'Reason: '.$lostReason : null,
                    'meta' => ['from' => $from->value, 'to' => $to->value],
                ]);
            } elseif ($to === LeadStatus::Lost && $lostReason !== null && $lostReason !== $lead->lost_reason) {
                $lead->forceFill(['lost_reason' => $lostReason])->save();
            }

            // Status changed without an explicit slot → top of the new column.
            if ($position !== null || $from !== $to) {
                $this->place($lead, $position ?? 0);
            }

            return $lead;
        });
    }

    /** Put $lead at $position in its column and renumber the column 0..n. */
    public function place(Lead $lead, int $position): void
    {
        $ids = Lead::query()
            ->where('status', $lead->status->value)
            ->whereKeyNot($lead->getKey())
            ->orderBy('position')
            ->orderByDesc('score')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        array_splice($ids, max(0, min($position, count($ids))), 0, [$lead->getKey()]);

        $current = Lead::query()->whereIn('id', $ids)->pluck('position', 'id');
        foreach ($ids as $index => $id) {
            if ((int) ($current[$id] ?? -1) !== $index) {
                Lead::query()->whereKey($id)->update(['position' => $index]); // no timestamps / events
            }
        }

        $lead->position = array_search($lead->getKey(), $ids, true);
    }
}
