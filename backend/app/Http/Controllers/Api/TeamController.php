<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales team routing settings: regions (postcode prefixes), project types,
 * capacity and active flag — the inputs of LeadAssignmentService.
 * Read: admin + manager. Write: admin.
 */
class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => User::query()->orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END")->orderBy('name')->get()->map(fn (User $u) => $this->row($u))]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only admins can change team settings.');

        $data = $request->validate([
            'regions' => ['sometimes', 'array', 'max:20'],
            'regions.*' => ['string', 'regex:/^\d{2}$/'],
            'project_types' => ['sometimes', 'array'],
            'project_types.*' => [Rule::enum(ProjectType::class)],
            'max_open_leads' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ], ['regions.*.regex' => 'Regions are 2-digit postcode prefixes, e.g. 10 or 14.']);

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $user->is($request->user())) {
            abort(422, 'You cannot deactivate your own account.');
        }
        if (isset($data['regions'])) {
            $data['regions'] = array_values(array_unique($data['regions']));
            sort($data['regions']);
        }

        $user->update($data);

        if (($data['is_active'] ?? true) === false) {
            $user->tokens()->delete(); // sign out everywhere
        }

        return response()->json(['data' => $this->row($user->fresh())]);
    }

    private function row(User $u): array
    {
        $stats = Lead::query()->where('assigned_to', $u->id)
            ->selectRaw('SUM(CASE WHEN status IN (?,?,?,?,?) THEN 1 ELSE 0 END) AS open_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS won, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS lost, SUM(CASE WHEN status IN (?,?,?,?,?) AND temperature = ? THEN 1 ELSE 0 END) AS hot_open',
                [...array_map(fn ($s) => $s->value, LeadStatus::open()), LeadStatus::Won->value, LeadStatus::Lost->value, ...array_map(fn ($s) => $s->value, LeadStatus::open()), 'hot'])
            ->first();
        $closed = (int) $stats->won + (int) $stats->lost;

        return [
            'id' => $u->id,
            'name' => $u->name,
            'initials' => $u->initials(),
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role->value,
            'role_label' => $u->role->label(),
            'is_active' => $u->is_active,
            'regions' => $u->regions ?? [],
            'project_types' => $u->project_types ?? [],
            'max_open_leads' => $u->max_open_leads,
            'open_leads' => (int) $stats->open_count,
            'hot_open' => (int) $stats->hot_open,
            'won' => (int) $stats->won,
            'win_rate' => $closed > 0 ? round($stats->won / $closed * 100, 1) : null,
            'capacity_pct' => $u->role === UserRole::Sales && $u->max_open_leads > 0 ? (int) round($stats->open_count / $u->max_open_leads * 100) : null,
        ];
    }
}
