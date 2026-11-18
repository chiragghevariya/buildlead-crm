<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Project;
use App\Services\Erp\InvoiceService;
use App\Services\Erp\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Admin + manager only (route middleware). */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = array_values(array_filter(array_map(
            fn ($s) => InvoiceStatus::tryFrom(trim($s))?->value,
            explode(',', (string) $request->query('status'))
        )));

        $invoices = Invoice::query()
            ->when($status, fn ($q) => $q->whereIn('status', $status))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', (int) $request->query('project_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', InvoiceType::tryFrom((string) $request->query('type'))?->value))
            ->with('project')
            ->orderByRaw('CASE WHEN issued_at IS NULL THEN 0 ELSE 1 END') // drafts first
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate(max(1, min(100, (int) $request->query('per_page', 25))))
            ->withQueryString();

        $totals = Invoice::query()
            ->selectRaw('status, COUNT(*) AS c, COALESCE(SUM(total), 0) AS gross')
            ->groupBy('status')->get()
            ->mapWithKeys(fn ($r) => [($r->status instanceof InvoiceStatus ? $r->status->value : $r->status) => ['count' => (int) $r->c, 'total' => round((float) $r->gross, 2)]]);

        $paidThisMonth = Invoice::query()
            ->where('status', InvoiceStatus::Paid->value)
            ->where('paid_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(total), 0) AS gross')->first();

        return InvoiceResource::collection($invoices)->additional(['summary' => $totals->all() + [
            'paid_this_month' => ['count' => (int) $paidThisMonth->c, 'total' => round((float) $paidThisMonth->gross, 2)],
        ]]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(InvoiceType::class)],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:100000000', 'prohibits:percent'],
            'percent' => ['nullable', 'numeric', 'gt:0', 'max:110'],
            'send' => ['sometimes', 'boolean'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $invoice = $this->invoices->create($project, $data);

        return (new InvoiceResource($invoice->load('project')))->response()->setStatusCode(201);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load('project'));
    }

    public function send(Request $request, Invoice $invoice): InvoiceResource
    {
        $data = $request->validate(['issued_at' => ['nullable', 'date', 'before_or_equal:today']]);

        return new InvoiceResource($this->invoices->send($invoice, $data['issued_at'] ?? null)->load('project'));
    }

    public function markPaid(Request $request, Invoice $invoice): InvoiceResource
    {
        $data = $request->validate(['paid_at' => ['nullable', 'date', 'before_or_equal:now']]);

        return new InvoiceResource($this->invoices->markPaid($invoice, $data['paid_at'] ?? null)->load('project'));
    }

    public function pdf(Request $request, Invoice $invoice, PdfRenderer $pdf): Response
    {
        return response($pdf->invoice($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$pdf->filename($invoice).'"',
        ]);
    }
}
