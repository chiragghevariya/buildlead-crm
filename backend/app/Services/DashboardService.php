<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\InvoiceStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\UserRole;
use App\Http\Resources\LeadCardResource;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * All dashboard numbers. Lead metrics respect visibility (sales = own leads);
 * company finance (invoices, projects) is only returned to admins/managers.
 */
class DashboardService
{
    private User $user;

    private Carbon $now;

    public function build(User $user): array
    {
        $this->user = $user;
        $this->now = now();

        return [
            'kpis' => $this->kpis(),
            'leads_per_week' => $this->leadsPerWeek(),
            'by_source' => $this->bySource(),
            'funnel' => $this->funnel(),
            'top_hot_leads' => LeadCardResource::collection(
                $this->leads()->open()->hot()->with('assignee')->orderByDesc('score')->orderByDesc('created_at')->limit(5)->get()
            ),
            'leaderboard' => $this->leaderboard(),
            'recent_activity' => $this->recentActivity(),
            'generated_at' => $this->now->toIso8601String(),
        ];
    }

    private function leads(): Builder
    {
        return Lead::query()->visibleTo($this->user);
    }

    private function kpis(): array
    {
        $newNow = $this->leads()->where('created_at', '>=', $this->now->copy()->subDays(30))->count();
        $newPrev = $this->leads()->whereBetween('created_at', [$this->now->copy()->subDays(60), $this->now->copy()->subDays(30)])->count();

        $closed = $this->leads()
            ->whereIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->where('updated_at', '>=', $this->now->copy()->subDays(90))
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS won, COUNT(*) AS closed', [LeadStatus::Won->value])
            ->first();

        $responses = $this->leads()
            ->whereNotNull('first_contacted_at')
            ->where('created_at', '>=', $this->now->copy()->subDays(30))
            ->get(['created_at', 'first_contacted_at', 'temperature']);

        $kpis = [
            'new_leads_30d' => [
                'value' => $newNow,
                'previous' => $newPrev,
                'change_pct' => $newPrev > 0 ? round(($newNow - $newPrev) / $newPrev * 100, 1) : null,
            ],
            'open_hot_leads' => $this->leads()->open()->hot()->count(),
            'pipeline_value' => (float) $this->leads()->open()->sum('estimated_value'),
            'win_rate' => [
                'value' => (int) $closed->closed > 0 ? round($closed->won / $closed->closed * 100, 1) : null,
                'won' => (int) $closed->won,
                'closed' => (int) $closed->closed,
                'period_days' => 90,
            ],
            'avg_first_response_minutes' => [
                'all' => $this->avgMinutes($responses),
                'hot' => $this->avgMinutes($responses->where('temperature', LeadTemperature::Hot)),
                'period_days' => 30,
            ],
            'outstanding_invoices' => null,
            'active_projects' => null,
        ];

        $kpis['trends'] = $this->trends();

        if ($this->user->canSeeAllLeads()) {
            $unpaid = Invoice::query()->unpaid()->get(['status', 'total']);
            $overdue = $unpaid->where('status', InvoiceStatus::Overdue);

            $kpis['outstanding_invoices'] = [
                'count' => $unpaid->count(),
                'total' => round((float) $unpaid->sum('total'), 2),
                'overdue_count' => $overdue->count(),
                'overdue_total' => round((float) $overdue->sum('total'), 2),
            ];
            $kpis['active_projects'] = Project::query()->active()->count();
        }

        return $kpis;
    }

    /** Last 12 ISO weeks (Mon–Sun), oldest first, split by temperature. */
    private function leadsPerWeek(): array
    {
        $start = $this->now->copy()->startOfWeek()->subWeeks(11);
        $rows = $this->leads()->where('created_at', '>=', $start)->get(['created_at', 'temperature', 'estimated_value']);

        $weeks = [];
        for ($i = 0; $i < 12; $i++) {
            $weekStart = $start->copy()->addWeeks($i);
            $inWeek = $rows->filter(fn ($l) => $l->created_at->betweenIncluded($weekStart, $weekStart->copy()->endOfWeek()));

            $weeks[] = [
                'week_start' => $weekStart->toDateString(),
                'label' => $weekStart->format('j M'),
                'hot' => $inWeek->where('temperature', LeadTemperature::Hot)->count(),
                'warm' => $inWeek->where('temperature', LeadTemperature::Warm)->count(),
                'cold' => $inWeek->where('temperature', LeadTemperature::Cold)->count(),
                'unscored' => $inWeek->whereNull('temperature')->count(),
                'total' => $inWeek->count(),
                'value' => round((float) $inWeek->sum('estimated_value'), 2),
            ];
        }

        return $weeks;
    }

    private function bySource(): array
    {
        $rows = $this->leads()
            ->selectRaw('source, COUNT(*) AS lead_count, AVG(score) AS avg_score, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS won', [LeadStatus::Won->value])
            ->groupBy('source')
            ->get()
            ->keyBy(fn ($r) => $r->source instanceof LeadSource ? $r->source->value : $r->source);

        return collect(LeadSource::cases())
            ->map(function (LeadSource $source) use ($rows) {
                $row = $rows[$source->value] ?? null;
                $count = (int) ($row->lead_count ?? 0);

                return [
                    'source' => $source->value,
                    'label' => $source->label(),
                    'count' => $count,
                    'avg_score' => $row?->avg_score !== null ? round((float) $row->avg_score, 1) : null,
                    'won' => (int) ($row->won ?? 0),
                    'conversion_pct' => $count > 0 ? round(($row->won ?? 0) / $count * 100, 1) : null,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * How many leads reached each stage. Lost leads count for every stage they
     * passed before being lost (from the status_change history).
     */
    private function funnel(): array
    {
        $stages = array_values(array_filter(LeadStatus::pipeline(), fn ($s) => $s !== LeadStatus::Lost));
        $rank = array_flip(array_map(fn ($s) => $s->value, $stages));

        $leads = $this->leads()->get(['id', 'status']);
        $history = LeadActivity::query()
            ->whereIn('lead_id', $leads->pluck('id'))
            ->where('type', ActivityType::StatusChange->value)
            ->get(['lead_id', 'meta'])
            ->groupBy('lead_id');

        $reached = array_fill(0, count($stages), 0);
        foreach ($leads as $lead) {
            $max = $rank[$lead->status->value] ?? 0; // lost → 0 unless history says more
            foreach ($history[$lead->id] ?? [] as $change) {
                $max = max($max, $rank[$change->meta['to'] ?? ''] ?? 0, $rank[$change->meta['from'] ?? ''] ?? 0);
            }
            for ($i = 0; $i <= $max; $i++) {
                $reached[$i]++;
            }
        }

        $first = max(1, $reached[0]);

        return array_map(fn (LeadStatus $s, int $i) => [
            'stage' => $s->value,
            'label' => $s->label(),
            'count' => $reached[$i],
            'pct_of_total' => round($reached[$i] / $first * 100, 1),
            'pct_of_previous' => $i === 0 ? 100.0 : ($reached[$i - 1] > 0 ? round($reached[$i] / $reached[$i - 1] * 100, 1) : 0.0),
        ], $stages, array_keys($stages));
    }

    private function leaderboard(): array
    {
        $reps = User::query()->where('role', UserRole::Sales->value)->where('is_active', true)->orderBy('name')->get();
        $open = array_map(fn ($s) => $s->value, LeadStatus::open());

        $stats = Lead::query()
            ->whereIn('assigned_to', $reps->pluck('id'))
            ->selectRaw('assigned_to,
                SUM(CASE WHEN status IN ('.implode(',', array_fill(0, count($open), '?')).') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status IN ('.implode(',', array_fill(0, count($open), '?')).') AND temperature = ? THEN 1 ELSE 0 END) AS hot_open,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS won_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS lost_count,
                COALESCE(SUM(CASE WHEN status = ? THEN estimated_value ELSE 0 END), 0) AS won_value',
                [...$open, ...$open, LeadTemperature::Hot->value, LeadStatus::Won->value, LeadStatus::Lost->value, LeadStatus::Won->value])
            ->groupBy('assigned_to')
            ->get()
            ->keyBy('assigned_to');

        $responses = Lead::query()
            ->whereIn('assigned_to', $reps->pluck('id'))
            ->whereNotNull('first_contacted_at')
            ->where('created_at', '>=', $this->now->copy()->subDays(90))
            ->get(['assigned_to', 'created_at', 'first_contacted_at'])
            ->groupBy('assigned_to');

        return $reps->map(function (User $rep) use ($stats, $responses) {
            $s = $stats[$rep->id] ?? null;
            $won = (int) ($s->won_count ?? 0);
            $closed = $won + (int) ($s->lost_count ?? 0);

            return [
                'user' => ['id' => $rep->id, 'name' => $rep->name, 'initials' => $rep->initials()],
                'open_leads' => (int) ($s->open_count ?? 0),
                'hot_open' => (int) ($s->hot_open ?? 0),
                'won' => $won,
                'won_value' => (float) ($s->won_value ?? 0),
                'win_rate' => $closed > 0 ? round($won / $closed * 100, 1) : null,
                'avg_first_response_minutes' => $this->avgMinutes($responses[$rep->id] ?? collect()),
                'is_me' => $rep->is($this->user),
            ];
        })
            ->sortBy([['won_value', 'desc'], ['win_rate', 'desc']])
            ->values()
            ->map(fn ($row, $i) => ['rank' => $i + 1] + $row)
            ->all();
    }

    /**
     * Period-over-period change for the KPI trend arrows (percent, or
     * percentage points for the win rate). Null when there is no baseline.
     */
    private function trends(): array
    {
        $window = fn (int $daysAgoFrom, int $daysAgoTo) => [
            $this->now->copy()->subDays($daysAgoFrom), $this->now->copy()->subDays($daysAgoTo),
        ];
        $change = fn (float $now, float $prev) => $prev > 0 ? round(($now - $prev) / $prev * 100, 1) : null;

        [$a, $b] = $window(30, 0);
        [$c, $d] = $window(60, 30);
        $created = fn ($from, $to) => $this->leads()->whereBetween('created_at', [$from, $to]);

        $winRate = function ($from, $to) {
            $row = $this->leads()
                ->whereIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
                ->whereBetween('updated_at', [$from, $to])
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS won, COUNT(*) AS closed', [LeadStatus::Won->value])
                ->first();

            // Need a minimal sample, otherwise one deal swings the trend by 50+ points.
            return (int) $row->closed >= 3 ? $row->won / $row->closed * 100 : null;
        };
        [$e, $f] = $window(45, 0);
        [$g, $h] = $window(90, 45);
        $wrNow = $winRate($e, $f);
        $wrPrev = $winRate($g, $h);

        return [
            'new_leads_pct' => $change($created($a, $b)->count(), $created($c, $d)->count()),
            'hot_leads_pct' => $change($created($a, $b)->hot()->count(), $created($c, $d)->hot()->count()),
            'pipeline_value_pct' => $change((float) $created($a, $b)->sum('estimated_value'), (float) $created($c, $d)->sum('estimated_value')),
            'win_rate_pts' => $wrNow !== null && $wrPrev !== null ? round($wrNow - $wrPrev, 1) : null,
        ];
    }

    /** Latest human/system activity on visible leads (future tasks excluded). */
    private function recentActivity(): array
    {
        return LeadActivity::query()
            ->whereHas('lead', fn ($q) => $q->visibleTo($this->user))
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '<=', $this->now))
            ->where('type', '!=', ActivityType::Score->value)
            ->with(['lead:id,reference,contact_name,company,temperature', 'user:id,name'])
            ->latest('created_at')->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (LeadActivity $a) => [
                'id' => $a->id,
                'type' => $a->type->value,
                'title' => $a->title,
                'body' => $a->body,
                'created_at' => $a->created_at?->toIso8601String(),
                'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name, 'initials' => $a->user->initials()] : null,
                'lead' => [
                    'id' => $a->lead->id,
                    'reference' => $a->lead->reference,
                    'name' => $a->lead->company ?: $a->lead->contact_name,
                    'temperature' => $a->lead->temperature?->value,
                ],
            ])
            ->all();
    }

    private function avgMinutes(Collection $leads): ?int
    {
        if ($leads->isEmpty()) {
            return null;
        }

        return (int) round($leads->avg(fn ($l) => max(0, $l->created_at->diffInMinutes($l->first_contacted_at))));
    }
}
