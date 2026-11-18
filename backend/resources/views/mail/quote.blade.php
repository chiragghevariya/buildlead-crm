@php($money = fn ($v) => \App\Support\Money::cents($v))
<x-mail::message>
# Guten Tag {{ $lead->contact_name }},

vielen Dank für Ihre Anfrage und das angenehme Gespräch. Anbei erhalten Sie unser Angebot **{{ $quote->number }}** für Ihr Vorhaben{{ $lead->city ? ' in '.$lead->city : '' }}.

@if ($personalMessage)
{{ $personalMessage }}
@endif

<x-mail::table>
| | |
|:--|--:|
| Nettobetrag | {{ $money($quote->subtotal) }} |
| zzgl. {{ rtrim(rtrim(number_format((float) $quote->vat_rate, 2, ',', '.'), '0'), ',') }} % MwSt. | {{ $money($quote->vat_amount) }} |
| **Gesamtbetrag** | **{{ $money($quote->total) }}** |
</x-mail::table>

Das Angebot ist gültig bis **{{ $quote->valid_until?->format('j M Y') }}**. Bei Fragen erreichen Sie mich jederzeit{{ $sender?->phone ? ' unter '.$sender->phone : '' }}.

Mit freundlichen Grüßen<br>
{{ $sender?->name ?? config('company.name') }}<br>
{{ config('company.name') }}
</x-mail::message>
