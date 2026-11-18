<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LeadRequest;
use App\Http\Requests\StoreLeadActivityRequest;
use App\Http\Requests\UpdateLeadStatusRequest;
use App\Http\Resources\LeadActivityResource;
use App\Http\Resources\LeadResource;
use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadFilters;
use App\Services\Leads\LeadPipelineService;
use App\Services\Scoring\LeadScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function index(Request $request, LeadFilters $filters): AnonymousResourceCollection
    {
        $user = $request->user();

        $leads = $filters->sort($filters->query($user))
            ->with('assignee')
            ->paginate($filters->perPage())
            ->withQueryString();

        // Counts per temperature for the filter chips (ignoring the temperature filter itself).
        $counts = $filters->query($user, except: ['temperature'])
            ->reorder()
            ->selectRaw('temperature, COUNT(*) AS c')
            ->groupBy('temperature')
            ->pluck('c', 'temperature');

        return LeadResource::collection($leads)->additional([
            'summary' => [
                'hot' => (int) ($counts[LeadTemperature::Hot->value] ?? 0),
                'warm' => (int) ($counts[LeadTemperature::Warm->value] ?? 0),
                'cold' => (int) ($counts[LeadTemperature::Cold->value] ?? 0),
                'unscored' => (int) ($counts[''] ?? 0),
            ],
        ]);
    }

    public function store(LeadRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $lead = DB::transaction(function () use ($data, $user) {
            if ($user->isSales()) {
                $data['assigned_to'] = $user->id; // reps own the leads they enter
            }
            $consent = (bool) ($data['consent_given'] ?? false);

            $lead = Lead::create(array_merge($data, [
                'status' => LeadStatus::New,
                'consent_given' => $consent,
                'consent_at' => $consent ? now() : null,
                'assigned_at' => isset($data['assigned_to']) ? now() : null,
                'estimated_value' => $data['estimated_value'] ?? $data['budget_max'] ?? $data['budget_min'] ?? null,
            ]));

            $lead->activities()->create([
                'user_id' => $user->id,
                'type' => ActivityType::Created,
                'title' => 'Lead created by '.$user->name,
                'meta' => ['source' => $lead->source->value],
            ]);

            ScoreLeadJob::dispatch($lead, autoAssign: ! $lead->assigned_to)->afterCommit();

            return $lead;
        });

        return $this->detail($lead)->response()->setStatusCode(201);
    }

    public function show(Lead $lead): LeadResource
    {
        $this->authorize('view', $lead);

        return $this->detail($lead);
    }

    public function update(LeadRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);
        $user = $request->user();

        $rescoring = DB::transaction(function () use ($request, $lead, $user) {
            $lead->fill($request->validated());

            if ($lead->isDirty('consent_given') && $lead->consent_given) {
                $lead->consent_at = now();
            }

            $reassigned = $lead->isDirty('assigned_to');
            if ($reassigned) {
                $lead->assigned_at = $lead->assigned_to ? now() : null;
            }

            $rescore = $lead->isDirty(LeadScoringService::INPUT_FIELDS);
            $lead->save();

            if ($reassigned) {
                $owner = $lead->assigned_to ? User::find($lead->assigned_to) : null;
                $lead->activities()->create([
                    'user_id' => $user->id,
                    'type' => ActivityType::Assignment,
                    'title' => $owner ? 'Reassigned to '.$owner->name : 'Unassigned',
                    'body' => 'Changed manually by '.$user->name.'.',
                    'meta' => ['user_id' => $owner?->id, 'manual' => true],
                ]);
            }

            if ($rescore) {
                ScoreLeadJob::dispatch($lead, autoAssign: false)->afterCommit();
            }

            return $rescore;
        });

        return $this->detail($lead)->additional(['meta' => ['rescoring' => $rescoring]])->response();
    }

    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead, LeadPipelineService $pipeline): LeadResource
    {
        $this->authorize('update', $lead);

        $pipeline->move(
            $lead,
            LeadStatus::from($request->validated('status')),
            $request->user(),
            $request->validated('position'),
            $request->validated('lost_reason'),
        );

        return $this->detail($lead);
    }

    public function storeActivity(StoreLeadActivityRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);
        $data = $request->validated();

        $activity = DB::transaction(function () use ($data, $lead, $request) {
            $activity = $lead->activities()->create(array_merge($data, ['user_id' => $request->user()->id]));

            // A logged call/email/meeting/visit (not a future task) is the first response.
            $isTask = isset($data['due_at']) && now()->lt($data['due_at']);
            if (! $isTask && in_array($data['type'], LeadPipelineService::CONTACT_TYPES, true) && ! $lead->first_contacted_at) {
                $lead->forceFill(['first_contacted_at' => now()])->save();
            }

            return $activity;
        });

        return (new LeadActivityResource($activity->load('user')))->response()->setStatusCode(201);
    }

    /**
     * POST /leads/bulk {ids[], action: assign|status, assigned_to?, status?, lost_reason?}
     * All-or-nothing: every lead must be visible/updatable by the user.
     */
    public function bulk(Request $request, LeadPipelineService $pipeline): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(['assign', 'status'])],
            'assigned_to' => ['required_if:action,assign', 'nullable', Rule::exists('users', 'id')->where('role', UserRole::Sales->value)->where('is_active', true)],
            'status' => ['required_if:action,status', 'nullable', Rule::enum(LeadStatus::class)],
            'lost_reason' => ['nullable', 'required_if:status,lost', 'string', 'max:255'],
        ], [
            'assigned_to.exists' => 'Leads can only be assigned to active sales reps.',
            'lost_reason.required_if' => 'Please give a reason when marking leads as lost.',
        ]);

        $leads = Lead::query()->whereIn('id', $data['ids'])->get();
        if ($leads->count() !== count($data['ids'])) {
            abort(404, 'Some of the selected leads no longer exist.');
        }
        foreach ($leads as $lead) {
            $this->authorize($data['action'] === 'assign' ? 'reassign' : 'update', $lead);
        }

        DB::transaction(function () use ($leads, $data, $user, $pipeline) {
            if ($data['action'] === 'assign') {
                $owner = User::findOrFail($data['assigned_to']);
                foreach ($leads->where('assigned_to', '!=', $owner->id) as $lead) {
                    $lead->forceFill(['assigned_to' => $owner->id, 'assigned_at' => now()])->save();
                    $lead->activities()->create([
                        'user_id' => $user->id,
                        'type' => ActivityType::Assignment,
                        'title' => 'Reassigned to '.$owner->name,
                        'body' => 'Bulk action by '.$user->name.'.',
                        'meta' => ['user_id' => $owner->id, 'manual' => true, 'bulk' => true],
                    ]);
                }

                return;
            }

            $status = LeadStatus::from($data['status']);
            foreach ($leads as $lead) {
                $pipeline->move($lead, $status, $user, null, $data['lost_reason'] ?? null);
            }
        });

        return response()->json(['updated' => $leads->count()]);
    }

    public function rescore(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        ScoreLeadJob::dispatch($lead, autoAssign: ! $lead->assigned_to);

        return response()->json(['message' => 'Re-scoring queued.', 'lead_id' => $lead->id], 202);
    }

    public function destroy(Lead $lead): Response
    {
        $this->authorize('delete', $lead);

        $lead->delete();

        return response()->noContent();
    }

    private function detail(Lead $lead): LeadResource
    {
        $lead->load([
            'assignee',
            'activities' => fn ($q) => $q->with('user')->reorder()->latest('created_at')->latest('id'),
            'quotes' => fn ($q) => $q->latest(),
            'project',
        ]);

        return (new LeadResource($lead))->detailed();
    }
}
