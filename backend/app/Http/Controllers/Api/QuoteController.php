<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\QuoteResource;
use App\Models\Lead;
use App\Models\Quote;
use App\Services\Erp\PdfRenderer;
use App\Services\Erp\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Quotes follow lead visibility: sales reps work on quotes for their own leads.
 */
class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $status = array_values(array_filter(array_map(
            fn ($s) => QuoteStatus::tryFrom(trim($s))?->value,
            explode(',', (string) $request->query('status'))
        )));
        $search = trim((string) $request->query('search'));

        $quotes = Quote::query()
            ->whereHas('lead', fn ($q) => $q->visibleTo($user))
            ->when($status, fn ($q) => $q->whereIn('status', $status))
            ->when($request->filled('lead_id'), fn ($q) => $q->where('lead_id', (int) $request->query('lead_id')))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(number) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereHas('lead', fn ($l) => $l->search($search))))
            ->with(['lead', 'creator', 'project'])
            ->withCount('items')
            ->latest('created_at')->latest('id')
            ->paginate(max(1, min(100, (int) $request->query('per_page', 25))))
            ->withQueryString();

        return QuoteResource::collection($quotes);
    }

    public function store(QuoteRequest $request): JsonResponse
    {
        $lead = Lead::findOrFail($request->validated('lead_id'));
        $this->authorize('update', $lead);

        $quote = $this->quotes->create($lead, $request->validated(), $request->user());

        return $this->resource($quote)->response()->setStatusCode(201);
    }

    public function show(Quote $quote): QuoteResource
    {
        $this->authorize('view', $quote->lead);

        return $this->resource($quote);
    }

    public function update(QuoteRequest $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote->lead);

        return $this->resource($this->quotes->update($quote, $request->validated()));
    }

    public function send(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote->lead);
        $data = $request->validate([
            'to' => ['nullable', 'email:rfc', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->resource($this->quotes->send($quote, $request->user(), $data['to'] ?? null, $data['message'] ?? null));
    }

    public function accept(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('update', $quote->lead);

        $project = $this->quotes->accept($quote, $request->user());

        return response()->json([
            'quote' => $this->resource($quote),
            'project' => new ProjectResource($project->load(['manager', 'lead', 'quote'])),
        ], 201);
    }

    public function decline(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote->lead);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->resource($this->quotes->decline($quote, $request->user(), $data['reason'] ?? null));
    }

    public function pdf(Request $request, Quote $quote, PdfRenderer $pdf): Response
    {
        $this->authorize('view', $quote->lead);

        return response($pdf->quote($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$pdf->filename($quote).'"',
        ]);
    }

    private function resource(Quote $quote): QuoteResource
    {
        return new QuoteResource($quote->fresh(['lead', 'creator', 'items', 'project']));
    }
}
