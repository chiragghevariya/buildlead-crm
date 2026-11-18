<?php

namespace App\Services\Leads;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProjectType;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Query-string filters shared by the lead list and the pipeline board.
 *
 *  ?temperature=hot,warm  ?status=new,contacted  ?source=referral  ?project_type=roofing
 *  ?owner=me|unassigned|{id}  ?search=…  ?created_from=2026-09-01  ?created_to=2026-10-01
 * Lists accept comma-separated strings or arrays (?status[]=new).
 */
class LeadFilters
{
    public const SORTABLE = [
        'reference', 'score', 'created_at', 'updated_at', 'budget_max', 'estimated_value',
        'contact_name', 'company', 'status', 'first_contacted_at',
    ];

    public function __construct(private readonly Request $request) {}

    public function query(User $user, array $except = []): Builder
    {
        $query = Lead::query()->visibleTo($user);

        if (! in_array('temperature', $except, true) && $values = $this->list('temperature', LeadTemperature::class)) {
            $query->whereIn('temperature', $values);
        }
        if (! in_array('status', $except, true) && $values = $this->list('status', LeadStatus::class)) {
            $query->whereIn('status', $values);
        }
        if ($values = $this->list('source', LeadSource::class)) {
            $query->whereIn('source', $values);
        }
        if ($values = $this->list('project_type', ProjectType::class)) {
            $query->whereIn('project_type', $values);
        }

        $owner = $this->request->query('owner');
        match (true) {
            $owner === 'me' => $query->where('assigned_to', $user->id),
            $owner === 'unassigned' => $query->whereNull('assigned_to'),
            is_numeric($owner) => $query->where('assigned_to', (int) $owner),
            default => null,
        };

        if ($from = $this->date('created_from')) {
            $query->where('created_at', '>=', $from->startOfDay());
        }
        if ($to = $this->date('created_to')) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        return $query->search($this->request->query('search', $this->request->query('q')));
    }

    /** ?sort=-score (desc) | created_at (asc). Nulls always last. */
    public function sort(Builder $query): Builder
    {
        $sort = (string) $this->request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, self::SORTABLE, true)) {
            [$column, $direction] = ['created_at', 'desc'];
        }

        return $query
            ->orderByRaw("CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END")
            ->orderBy($column, $direction)
            ->orderBy('id', $direction);
    }

    public function perPage(int $default = 25): int
    {
        return max(1, min(100, (int) $this->request->query('per_page', $default)));
    }

    /** @param class-string<\BackedEnum> $enum */
    private function list(string $key, string $enum): array
    {
        $raw = $this->request->query($key);
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(
            fn ($v) => $enum::tryFrom(trim((string) $v))?->value,
            $values
        )));
    }

    private function date(string $key): ?Carbon
    {
        try {
            return filled($this->request->query($key)) ? Carbon::parse($this->request->query($key)) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
