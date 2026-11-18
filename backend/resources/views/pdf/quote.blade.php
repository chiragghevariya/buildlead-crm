@extends('pdf.layout')

@section('title', 'Angebot '.$quote->number)

@section('recipient')
    @if ($lead->company)<strong>{{ $lead->company }}</strong><br>{{ $lead->contact_name }}<br>
    @else<strong>{{ $lead->contact_name }}</strong><br>@endif
    @if ($lead->street){{ $lead->street }}<br>@endif
    {{ trim($lead->postcode.' '.$lead->city) }}
@endsection

@section('meta')
    <tr><td class="label">Quote no.</td><td><strong>{{ $quote->number }}</strong></td></tr>
    <tr><td class="label">Date</td><td>{{ $date($quote->sent_at ?? $quote->created_at) }}</td></tr>
    <tr><td class="label">Valid until</td><td>{{ $date($quote->valid_until) }}</td></tr>
    <tr><td class="label">Your enquiry</td><td>{{ $lead->reference }}</td></tr>
    @if ($quote->creator)
        <tr><td class="label">Contact</td><td>{{ $quote->creator->name }}</td></tr>
        @if ($quote->creator->phone)<tr><td class="label">Phone</td><td>{{ $quote->creator->phone }}</td></tr>@endif
    @endif
@endsection

@section('content')
    @php
        $vat = rtrim(rtrim(number_format((float) $quote->vat_rate, 2, ',', '.'), '0'), ',');
    @endphp

    <h1>Angebot / Quote {{ $quote->number }}</h1>
    <div class="subtitle">
        {{ $lead->project_type?->label() ?? 'Construction works' }}@if ($lead->city) · {{ trim($lead->street ? $lead->street.', '.$lead->city : $lead->city) }}@endif
        @if ($quote->status->value === 'accepted') &nbsp;<span class="badge badge-green">ACCEPTED</span>@endif
        @if ($quote->status->value === 'draft') &nbsp;<span class="badge badge-amber">DRAFT</span>@endif
    </div>

    <p>Guten Tag {{ $lead->contact_name }},<br>
    vielen Dank für Ihre Anfrage. Gerne bieten wir Ihnen die folgenden Leistungen an:</p>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">Pos.</th>
                <th>Description</th>
                <th class="num" style="width: 11%;">Qty</th>
                <th style="width: 7%;">Unit</th>
                <th class="num" style="width: 15%;">Unit price</th>
                <th class="num" style="width: 16%;">Total</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($quote->items as $i => $item)
            <tr>
                <td class="muted">{{ $i + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td class="num">{{ $qty($item->quantity) }}</td>
                <td>{{ $item->unit->value }}</td>
                <td class="num">{{ $money($item->unit_price) }}</td>
                <td class="num">{{ $money($item->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal (net)</td><td class="num">{{ $money($quote->subtotal) }}</td></tr>
        <tr><td>VAT {{ $vat }} %</td><td class="num">{{ $money($quote->vat_amount) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="num">{{ $money($quote->total) }}</td></tr>
    </table>

    <div class="box">
        <h3>Validity &amp; terms</h3>
        This quote is valid until <strong>{{ $quote->valid_until?->format('j M Y') }}</strong>.
        Payment plan: 30 % deposit on order, progress invoices by construction stage, final invoice on handover.
        Payable within {{ $company['payment_terms_days'] }} days without deduction.
        @if ($quote->notes)<br><br>{!! nl2br(e($quote->notes)) !!}@endif
    </div>

    <p style="margin-top: 18px;">Wir freuen uns auf Ihren Auftrag. Bitte senden Sie uns das Angebot unterschrieben zurück.</p>

    <table class="signatures">
        <tr>
            <td>Place, date · {{ $company['name'] }}<br>{{ $quote->creator?->name }}</td>
            <td class="gap"></td>
            <td>Place, date · Signature of client (order confirmation)<br>{{ $lead->contact_name }}</td>
        </tr>
    </table>
@endsection
