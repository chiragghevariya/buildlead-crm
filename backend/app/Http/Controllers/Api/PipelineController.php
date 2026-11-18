<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadCardResource;
use App\Services\Leads\LeadFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/pipeline — Kanban columns (one per status) with cards and column value.
 * Accepts the lead list filters (except status) plus ?limit= cards per column.
 */
class PipelineController extends Controller
{
    public function __invoke(Request $request, LeadFilters $filters): JsonResponse
    {
        $user = $request->user();
        $limit = max(1, min(200, (int) $request->query('limit', 50)));

        $totals = $filters->query($user, except: ['status'])
            ->selectRaw('status, COUNT(*) AS lead_count, COALESCE(SUM(estimated_value), 0) AS total_value')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof LeadStatus ? $row->status->value : $row->status);

        $columns = [];
        foreach (LeadStatus::pipeline() as $status) {
            $cards = $filters->query($user, except: ['status'])
                ->where('status', $status->value)
                ->with('assignee')
                // Last status change = when the lead entered its current stage.
                ->withMax(['activities as stage_changed_at' => fn ($q) => $q->where('type', ActivityType::StatusChange->value)], 'created_at')
                ->orderBy('position')
                ->orderByDesc('score')
                ->orderBy('id')
                ->limit($limit)
                ->get();

            $total = $totals[$status->value] ?? null;
            $columns[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'is_closed' => $status->isClosed(),
                'count' => (int) ($total->lead_count ?? 0),
                'value' => (float) ($total->total_value ?? 0),
                'leads' => LeadCardResource::collection($cards),
            ];
        }

        $open = collect($columns)->reject(fn ($c) => $c['is_closed']);

        return response()->json([
            'columns' => $columns,
            'totals' => [
                'open_count' => $open->sum('count'),
                'open_value' => $open->sum('value'),
            ],
        ]);
    }
}
