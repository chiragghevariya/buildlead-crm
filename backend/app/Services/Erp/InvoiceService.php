<?php

namespace App\Services\Erp;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deposit / progress / final invoices against a project budget.
 */
class InvoiceService
{
    public const DEFAULT_DEPOSIT_SHARE = 0.30;

    /** Net amount already invoiced (all invoices incl. drafts — they are committed intents). */
    public function invoicedNet(Project $project, ?Invoice $except = null): float
    {
        return round((float) $project->invoices()
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->sum('amount'), 2);
    }

    /** How much more can be invoiced before hitting the budget cap (default 110 %). */
    public function remainingCapacity(Project $project): float
    {
        $cap = round((float) $project->budget * (float) config('company.invoice_budget_cap', 1.10), 2);

        return max(0.0, round($cap - $this->invoicedNet($project), 2));
    }

    /**
     * @param  array{type: string, amount?: ?float, percent?: ?float, send?: bool, issued_at?: ?string}  $data
     */
    public function create(Project $project, array $data): Invoice
    {
        return DB::transaction(function () use ($project, $data) {
            // Serialise invoice creation per project so two requests can't both pass the cap check.
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            $type = InvoiceType::from($data['type']);
            $budget = (float) $project->budget;
            $invoiced = $this->invoicedNet($project);

            if ($type === InvoiceType::Final && $project->invoices()->where('type', InvoiceType::Final->value)->exists()) {
                throw ValidationException::withMessages(['type' => 'This project already has a final invoice.']);
            }

            $amount = match (true) {
                isset($data['amount']) => (float) $data['amount'],
                isset($data['percent']) => $budget * (float) $data['percent'] / 100,
                $type === InvoiceType::Deposit => $budget * self::DEFAULT_DEPOSIT_SHARE,
                $type === InvoiceType::Final => $budget - $invoiced, // settle the remainder
                default => throw ValidationException::withMessages(['amount' => 'Provide an amount or percent for progress invoices.']),
            };
            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Nothing left to invoice: the budget is already fully invoiced.']);
            }

            $cap = round($budget * (float) config('company.invoice_budget_cap', 1.10), 2);
            if ($invoiced + $amount > $cap + 0.001) {
                $pct = (int) round(config('company.invoice_budget_cap', 1.10) * 100);

                throw ValidationException::withMessages(['amount' => sprintf(
                    'This invoice would bring the project to %s, above %d%% of its budget (%s). At most %s can still be invoiced.',
                    Money::cents($invoiced + $amount), $pct, Money::cents($budget), Money::cents(max(0, $cap - $invoiced)),
                )]);
            }

            $invoice = $project->invoices()->create([
                'type' => $type,
                'status' => InvoiceStatus::Draft,
                'amount' => $amount,
            ]);

            return ($data['send'] ?? false) ? $this->send($invoice, $data['issued_at'] ?? null) : $invoice;
        });
    }

    public function send(Invoice $invoice, ?string $issuedAt = null): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Only draft invoices can be issued.']);
        }

        $issued = $issuedAt ? Carbon::parse($issuedAt) : now();
        $invoice->forceFill([
            'status' => InvoiceStatus::Sent,
            'issued_at' => $issued->toDateString(),
            'due_at' => $issued->copy()->addDays(config('company.payment_terms_days'))->toDateString(),
        ])->save();

        // Issued with a due date already in the past (back-dated) → overdue right away.
        $this->markOverdue(Invoice::query()->whereKey($invoice->id));

        return $invoice->refresh();
    }

    public function markPaid(Invoice $invoice, ?string $paidAt = null): Invoice
    {
        if (! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true)) {
            throw ValidationException::withMessages(['status' => 'Only issued (sent or overdue) invoices can be marked as paid.']);
        }

        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => $paidAt ? Carbon::parse($paidAt) : now(),
        ])->save();

        return $invoice;
    }

    /** Sent invoices past their due date → overdue. Returns how many changed. */
    public function markOverdue($query = null): int
    {
        return ($query ?? Invoice::query())
            ->where('status', InvoiceStatus::Sent->value)
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<', now()->toDateString())
            ->update(['status' => InvoiceStatus::Overdue->value, 'updated_at' => now()]);
    }
}
