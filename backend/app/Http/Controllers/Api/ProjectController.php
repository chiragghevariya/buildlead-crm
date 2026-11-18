<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Admin + manager only (route middleware). */
class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = array_values(array_filter(array_map(
            fn ($s) => ProjectStatus::tryFrom(trim($s))?->value,
            explode(',', (string) $request->query('status'))
        )));
        $search = mb_strtolower(trim((string) $request->query('search')));

        $projects = $this->withTotals(Project::query())
            ->when($status, fn ($q) => $q->whereIn('status', $status))
            ->when($request->filled('manager_id'), fn ($q) => $q->where('manager_id', (int) $request->query('manager_id')))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(site_address) LIKE ?', ["%{$search}%"])))
            ->with(['manager', 'lead', 'quote'])
            ->latest('created_at')->latest('id')
            ->paginate(max(1, min(100, (int) $request->query('per_page', 25))))
            ->withQueryString();

        return ProjectResource::collection($projects);
    }

    public function show(int $project): ProjectResource
    {
        $model = $this->withTotals(Project::query())
            ->with(['manager', 'lead', 'quote', 'invoices' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($project);

        return new ProjectResource($model);
    }

    public function update(Request $request, Project $project): ProjectResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'site_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'spent' => ['sometimes', 'numeric', 'min:0', 'max:100000000'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'manager_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')
                ->whereIn('role', [UserRole::Manager->value, UserRole::Admin->value])->where('is_active', true)],
        ]);

        // Completing a project implies 100 % unless told otherwise.
        if (($data['status'] ?? null) === ProjectStatus::Completed->value && ! array_key_exists('progress', $data)) {
            $data['progress'] = 100;
        }

        $project->update($data);

        return $this->show($project->id);
    }

    /** Invoice aggregates as subselects: net invoiced (all), net paid, gross outstanding. */
    private function withTotals(Builder $query): Builder
    {
        return $query->withSum('invoices as invoiced_net', 'amount')
            ->withSum(['invoices as paid_net' => fn ($q) => $q->where('status', InvoiceStatus::Paid->value)], 'amount')
            ->withSum(['invoices as outstanding_gross' => fn ($q) => $q->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Overdue->value])], 'total');
    }
}
