<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProjectType;
use App\Enums\Timeline;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadActivityRequest;
use App\Models\ScoringSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/meta — option lists for SPA selects/filters (one request at boot).
 */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $options = fn (array $cases) => array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], $cases);

        return response()->json([
            'statuses' => $options(LeadStatus::pipeline()),
            'temperatures' => $options(LeadTemperature::cases()),
            'sources' => $options(LeadSource::cases()),
            'project_types' => $options(ProjectType::cases()),
            'timelines' => $options(Timeline::cases()),
            'activity_types' => $options(array_map(fn ($t) => ActivityType::from($t), StoreLeadActivityRequest::USER_TYPES)),
            'sales_reps' => User::query()
                ->where('role', UserRole::Sales->value)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'initials' => $u->initials()])
                ->values(),
            'score_bands' => ScoringSetting::config('bands'),
        ]);
    }
}
