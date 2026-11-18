<?php

namespace App\Services\Assignment;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Auto-assignment rules (PROJECT_SPEC §5):
 *  1. Candidates = active sales reps under capacity (open leads < max_open_leads).
 *  2. Prefer reps matching region AND project type, then region OR type, then anyone.
 *  3. Hot leads → best win rate (tie: least busy). Others → least busy (tie: best win rate).
 */
class LeadAssignmentService
{
    public function assign(Lead $lead, bool $force = false): ?User
    {
        if ($lead->assigned_to && ! $force) {
            return $lead->assignee;
        }

        $candidates = $this->candidates($lead);
        if ($candidates->isEmpty()) {
            $lead->activities()->create([
                'user_id' => null,
                'type' => ActivityType::Assignment,
                'title' => 'Not assigned — all sales reps at capacity',
                'meta' => ['reason' => 'no_capacity'],
            ]);

            return null;
        }

        [$pool, $match] = $this->preferMatches($candidates, $lead);
        $hot = $lead->temperature === LeadTemperature::Hot;

        $rep = $hot
            ? $pool->sortBy([['win_rate', 'desc'], ['open_count', 'asc'], ['id', 'asc']])->first()
            : $pool->sortBy([['open_count', 'asc'], ['win_rate', 'desc'], ['id', 'asc']])->first();

        $lead->forceFill(['assigned_to' => $rep->id, 'assigned_at' => now()])->save();
        $lead->setRelation('assignee', $rep);

        $why = $hot
            ? 'Hot lead → best win rate ('.round($rep->win_rate * 100).'%)'
            : "Least busy rep ({$rep->open_count} open leads)";
        $matchLabel = match ($match) {
            'both' => 'region + project type match',
            'region' => 'region match',
            'type' => 'project type match',
            default => 'no region/type match available',
        };

        $lead->activities()->create([
            'user_id' => null,
            'type' => ActivityType::Assignment,
            'title' => 'Assigned to '.$rep->name,
            'body' => "{$why}; {$matchLabel}.",
            'meta' => [
                'user_id' => $rep->id,
                'match' => $match,
                'win_rate' => round($rep->win_rate, 3),
                'open_leads' => $rep->open_count,
            ],
        ]);

        return $rep;
    }

    /**
     * Active sales reps below capacity, annotated with open_count and win_rate.
     *
     * @return Collection<int, User>
     */
    public function candidates(?Lead $except = null): Collection
    {
        $open = array_map(fn (LeadStatus $s) => $s->value, LeadStatus::open());

        $reps = User::query()
            ->where('role', UserRole::Sales->value)
            ->where('is_active', true)
            ->withCount(['leads as open_count' => fn ($q) => $q->whereIn('status', $open)
                ->when($except?->exists, fn ($q) => $q->whereKeyNot($except->getKey()))])
            ->get()
            ->filter(fn (User $u) => $u->open_count < $u->max_open_leads)
            ->values();

        $winRates = $this->winRates($reps->pluck('id')->all());
        $reps->each(fn (User $u) => $u->win_rate = $winRates[$u->id] ?? 0.0);

        return $reps;
    }

    /**
     * won / (won + lost) per rep over the configured window.
     *
     * @param  list<int>  $userIds
     * @return array<int, float>
     */
    public function winRates(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $days = (int) ScoringSetting::config('assignment.win_rate_window_days', 180);

        return Lead::query()
            ->whereIn('assigned_to', $userIds)
            ->whereIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->where('updated_at', '>=', now()->subDays($days))
            ->selectRaw('assigned_to, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS won, COUNT(*) AS closed', [LeadStatus::Won->value])
            ->groupBy('assigned_to')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->assigned_to => $row->closed > 0 ? $row->won / $row->closed : 0.0])
            ->all();
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @return array{0: Collection<int, User>, 1: string}
     */
    private function preferMatches(Collection $candidates, Lead $lead): array
    {
        $type = $lead->project_type?->value;
        $region = fn (User $u) => $u->coversPostcode($lead->postcode);
        $handles = fn (User $u) => $type !== null && in_array($type, $u->project_types ?? [], true);

        $both = $candidates->filter(fn (User $u) => $region($u) && $handles($u));
        if ($both->isNotEmpty()) {
            return [$both, 'both'];
        }

        $byRegion = $candidates->filter($region);
        if ($byRegion->isNotEmpty()) {
            return [$byRegion, 'region'];
        }

        $byType = $candidates->filter($handles);
        if ($byType->isNotEmpty()) {
            return [$byType, 'type'];
        }

        return [$candidates, 'none'];
    }
}
