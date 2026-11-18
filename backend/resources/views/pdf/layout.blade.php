<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    @page { margin: 120px 48px 110px 48px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 9.5px; color: #1F2937; line-height: 1.45; }
    .muted { color: #6B7280; }
    .small { font-size: 8px; }
    .right { text-align: right; }
    .nowrap { white-space: nowrap; }
    .num { text-align: right; white-space: nowrap; }

    /* Header & footer repeat on every page */
    header { position: fixed; top: -92px; left: 0; right: 0; height: 70px; }
    footer { position: fixed; bottom: -86px; left: 0; right: 0; height: 64px; border-top: 1px solid #E5E7EB; padding-top: 8px; }
    .accent-bar { position: fixed; top: -120px; left: -48px; right: -48px; height: 6px; background: #F59E0B; }
    .logo-box { width: 34px; height: 34px; background: #0B1220; border-radius: 8px; text-align: center; }
    .logo-box span { display: block; color: #F59E0B; font-size: 19px; font-weight: bold; line-height: 20px; padding-top: 6px; }
    .brand { font-size: 15px; font-weight: bold; color: #0B1220; letter-spacing: -0.2px; }
    .brand-sub { font-size: 8px; color: #6B7280; }
    .company-lines { font-size: 8px; color: #6B7280; text-align: right; line-height: 1.5; }

    table { border-collapse: collapse; width: 100%; }
    .layout td { vertical-align: top; padding: 0; }

    .sender-line { font-size: 7px; color: #9CA3AF; border-bottom: 1px solid #E5E7EB; padding-bottom: 2px; margin-bottom: 6px; }
    .address { font-size: 10px; line-height: 1.5; }
    .meta-table td { padding: 2px 0; font-size: 9px; }
    .meta-table td.label { color: #6B7280; padding-right: 14px; }

    h1 { font-size: 20px; color: #0B1220; margin: 26px 0 2px; letter-spacing: -0.3px; }
    .subtitle { color: #6B7280; margin-bottom: 14px; }

    .items th { background: #0B1220; color: #fff; font-size: 8px; text-transform: uppercase; letter-spacing: 0.4px; padding: 7px 8px; text-align: left; }
    .items th.num { text-align: right; }
    .items td { padding: 7px 8px; border-bottom: 1px solid #E5E7EB; vertical-align: top; }
    .items tr:nth-child(even) td { background: #F9FAFB; }

    .totals { width: 44%; margin-left: 56%; margin-top: 12px; }
    .totals td { padding: 4px 8px; }
    .totals .grand td { background: #FEF3C7; border-top: 2px solid #F59E0B; font-weight: bold; font-size: 11px; color: #0B1220; padding: 8px; }

    .box { border: 1px solid #E5E7EB; border-radius: 8px; padding: 10px 12px; margin-top: 18px; background: #F9FAFB; }
    .box h3 { margin: 0 0 4px; font-size: 9.5px; color: #0B1220; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 8px; font-weight: bold; }
    .badge-amber { background: #FEF3C7; color: #92400E; }
    .badge-green { background: #D1FAE5; color: #065F46; }
    .badge-red { background: #FEE2E2; color: #991B1B; }

    .signatures { margin-top: 56px; }
    .signatures td { width: 46%; padding-top: 5px; border-top: 1px solid #9CA3AF; font-size: 8px; color: #6B7280; }
    .signatures td.gap { width: 8%; border: none; }
    .stamp { position: absolute; top: 332px; left: 40px; border: 3px solid #059669; color: #059669; font-size: 22px; font-weight: bold; padding: 4px 14px; border-radius: 6px; transform: rotate(-12deg); }
    .footer-cols td { font-size: 7px; color: #6B7280; vertical-align: top; width: 25%; line-height: 1.5; }
</style>
</head>
<body>
<div class="accent-bar"></div>
<header>
    <table class="layout">
        <tr>
            <td style="width: 44px;"><div class="logo-box"><span>B</span></div></td>
            <td style="padding-left: 10px;">
                <div class="brand">{{ $company['name'] }}</div>
                <div class="brand-sub">{{ $company['tagline'] }}</div>
            </td>
            <td class="company-lines">
                {{ $company['street'] }} · {{ $company['postcode'] }} {{ $company['city'] }}<br>
                {{ $company['phone'] }} · {{ $company['email'] }}<br>
                {{ $company['website'] }}
            </td>
        </tr>
    </table>
</header>

<footer>
    <table class="footer-cols">
        <tr>
            <td><strong>{{ $company['name'] }}</strong><br>{{ $company['street'] }}<br>{{ $company['postcode'] }} {{ $company['city'] }}</td>
            <td>Geschäftsführung: {{ $company['managing_director'] }}<br>{{ $company['register'] }}</td>
            <td>USt-IdNr.: {{ $company['vat_id'] }}<br>Steuer-Nr.: {{ $company['tax_number'] }}</td>
            <td>{{ $company['bank'] }}<br>IBAN {{ $company['iban'] }}<br>BIC {{ $company['bic'] }}</td>
        </tr>
    </table>
</footer>

<main>
    <table class="layout">
        <tr>
            <td style="width: 58%;">
                <div class="sender-line">{{ $company['name'] }} · {{ $company['street'] }} · {{ $company['postcode'] }} {{ $company['city'] }}</div>
                <div class="address">@yield('recipient')</div>
            </td>
            <td style="width: 42%; padding-left: 30px;">
                <table class="meta-table">@yield('meta')</table>
            </td>
        </tr>
    </table>

    @yield('content')
</main>
</body>
</html>
