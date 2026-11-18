@extends('pdf.layout')

@php
    $typeTitle = ['deposit' => 'Abschlagsrechnung / Deposit invoice', 'progress' => 'Teilrechnung / Progress invoice', 'final' => 'Schlussrechnung / Final invoice'][$invoice->type->value];
@endphp

@section('title', 'Rechnung '.$invoice->number)

@section('recipient')
    @if ($lead)
        @if ($lead->company)<strong>{{ $lead->company }}</strong><br>{{ $lead->contact_name }}<br>
        @else<strong>{{ $lead->contact_name }}</strong><br>@endif
        @if ($lead->street){{ $lead->street }}<br>@endif
        {{ trim($lead->postcode.' '.$lead->city) }}
    @else
        <strong>{{ $project->name }}</strong><br>{{ $project->site_address }}
    @endif
@endsection

@section('meta')
    <tr><td class="label">Invoice no.</td><td><strong>{{ $invoice->number }}</strong></td></tr>
    <tr><td class="label">Invoice date</td><td>{{ $date($invoice->issued_at) }}</td></tr>
    <tr><td class="label">Due date</td><td>{{ $date($invoice->due_at) }}</td></tr>
    <tr><td class="label">Project</td><td>{{ $project->code }}</td></tr>
    @if ($project->quote)<tr><td class="label">Quote</td><td>{{ $project->quote->number }}</td></tr>@endif
    @if ($lead)<tr><td class="label">Customer ref.</td><td>{{ $lead->reference }}</td></tr>@endif
@endsection

@section('content')
    @php
        $budget = (float) $project->budget;
        $share = $budget > 0 ? round((float) $invoice->amount / $budget * 100, 1) : null;
    @endphp

    @if ($invoice->status->value === 'paid')<div class="stamp">PAID {{ $invoice->paid_at?->format('j M Y') }}</div>@endif

    <h1>{{ $typeTitle }}</h1>
    <div class="subtitle">
        {{ $project->name }}@if ($project->site_address) · {{ $project->site_address }}@endif
        @if ($invoice->status->value === 'draft') &nbsp;<span class="badge badge-amber">DRAFT</span>@endif
        @if ($invoice->status->value === 'overdue') &nbsp;<span class="badge badge-red">OVERDUE</span>@endif
    </div>

    <table class="items">
        <thead>
            <tr><th style="width: 6%;">Pos.</th><th>Description</th><th class="num" style="width: 20%;">Amount (net)</th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="muted">1</td>
                <td>
                    {{ ['deposit' => 'Deposit', 'progress' => 'Progress payment', 'final' => 'Final payment'][$invoice->type->value] }}
                    for {{ $project->name }}@if ($share !== null) ({{ number_format($share, 1, ',', '.') }} % of contract value)@endif
                    @if ($project->quote)<br><span class="muted small">According to quote {{ $project->quote->number }} accepted on {{ $project->quote->accepted_at?->format('j M Y') ?? '—' }}</span>@endif
                </td>
                <td class="num">{{ $money($invoice->amount) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Net amount</td><td class="num">{{ $money($invoice->amount) }}</td></tr>
        <tr><td>VAT {{ \App\Models\Invoice::VAT_RATE }} %</td><td class="num">{{ $money($invoice->vat_amount) }}</td></tr>
        <tr class="grand"><td>Amount due</td><td class="num">{{ $money($invoice->total) }}</td></tr>
    </table>

    <div class="box">
        <h3>Contract overview (net)</h3>
        <table class="meta-table" style="width: 60%;">
            <tr><td class="label">Contract value</td><td class="num">{{ $money($budget) }}</td></tr>
            @foreach ($earlierInvoices as $earlier)
                <tr><td class="label">{{ $earlier->number }} ({{ $earlier->type->label() }}){{ $earlier->status->value === 'paid' ? ' · paid' : '' }}</td><td class="num">− {{ $money($earlier->amount) }}</td></tr>
            @endforeach
            <tr><td class="label">This invoice</td><td class="num">− {{ $money($invoice->amount) }}</td></tr>
            <tr><td class="label"><strong>Remaining contract value</strong></td><td class="num"><strong>{{ $money($budget - $previouslyInvoiced - (float) $invoice->amount) }}</strong></td></tr>
        </table>
    </div>

    <div class="box">
        <h3>Payment</h3>
        Please transfer <strong>{{ $money($invoice->total) }}</strong> by <strong>{{ $invoice->due_at?->format('j M Y') ?? '—' }}</strong>
        to {{ $company['bank'] }}, IBAN {{ $company['iban'] }}, BIC {{ $company['bic'] }}.
        Reference: <strong>{{ $invoice->number }}</strong>.<br>
        <span class="muted small">Leistungszeitraum gemäß Bautenstand. Bei Bauleistungen nach § 13b UStG gilt ggf. Steuerschuldnerschaft des Leistungsempfängers.</span>
    </div>

    <p style="margin-top: 18px;">Vielen Dank für Ihr Vertrauen.<br>{{ $company['name'] }}</p>
@endsection
