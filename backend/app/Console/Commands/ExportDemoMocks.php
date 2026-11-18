<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\ScoringSettingsController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\LeadResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\QuoteResource;
use App\Http\Resources\UserResource;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Snapshot the seeded demo company as real API responses for the SPA's demo mode
 * (VITE_DEMO_MODE=true). The SPA shifts all dates by (now − generated_at) at load.
 *
 *   php artisan migrate:fresh --seed && php artisan demo:export-mocks
 */
class ExportDemoMocks extends Command
{
    protected $signature = 'demo:export-mocks {--path= : Output file (default: ../frontend/src/mocks/data/demo.json)}';

    protected $description = 'Export seeded demo data as API-shaped JSON fixtures for the frontend demo mode';

    public function handle(DashboardService $dashboard): int
    {
        $admin = User::where('email', 'admin@buildlead.test')->firstOrFail();
        Auth::setUser($admin);
        $request = Request::create('/api');
        $request->setUserResolver(fn () => $admin);
        app()->instance('request', $request);

        $json = fn ($resource) => json_decode(json_encode($resource), true);

        $data = [
            'generated_at' => now()->toIso8601String(),
            'users' => $json(UserResource::collection(User::orderBy('id')->get())),
            'meta' => app(MetaController::class)()->getData(true),
            'leads' => Lead::query()
                ->with(['assignee', 'activities.user', 'quotes', 'project'])
                ->orderBy('id')->get()
                ->map(fn (Lead $lead) => $json((new LeadResource($lead))->detailed()))
                ->all(),
            'quotes' => $json(QuoteResource::collection(
                Quote::with(['lead', 'creator', 'items', 'project'])->withCount('items')->orderBy('id')->get()
            )),
            'projects' => $json(ProjectResource::collection(
                Project::query()
                    ->withSum('invoices as invoiced_net', 'amount')
                    ->withSum(['invoices as paid_net' => fn ($q) => $q->where('status', 'paid')], 'amount')
                    ->withSum(['invoices as outstanding_gross' => fn ($q) => $q->whereIn('status', ['sent', 'overdue'])], 'total')
                    ->with(['manager', 'lead', 'quote', 'invoices'])
                    ->orderBy('id')->get()
            )),
            'invoices' => $json(InvoiceResource::collection(Invoice::with('project')->orderBy('id')->get())),
            'dashboard' => $json($dashboard->build($admin)),
            'scoring_settings' => app(ScoringSettingsController::class)->show()->getData(true),
            'team' => app(TeamController::class)->index()->getData(true)['data'],
        ];

        $path = $this->option('path') ?: base_path('../frontend/src/mocks/data/demo.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf('Wrote %s (%s KB): %d users, %d leads, %d quotes, %d projects, %d invoices.',
            realpath($path), number_format(filesize($path) / 1024, 0), count($data['users']), count($data['leads']),
            count($data['quotes']), count($data['projects']), count($data['invoices'])));

        return self::SUCCESS;
    }
}
