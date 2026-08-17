<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px 104px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .page-footer { position: fixed; bottom: -86px; left: 34px; right: 34px; text-align: center; font-size: 9px; color: #555; line-height: 1.5; }
        .navy { color: #28364b; }
        .doc-title { font-size: 24px; font-weight: bold; letter-spacing: 1px; color: #28364b; }
        .bar { background: #28364b; color: #fff; padding: 4px 8px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        table { border-collapse: collapse; }
        table.items { width: 100%; margin-top: 14px; }
        table.items thead th { background: #28364b; color: #fff; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
        table.items tbody td { padding: 5px 8px; font-size: 11px; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; }
        .muted { color: #6b7280; font-size: 10px; }
        .late { color: #b91c1c; font-weight: bold; }
        .totrow td { padding: 6px 8px; font-size: 12px; font-weight: bold; border-top: 2px solid #28364b; }
        .bank { border: 1px solid #ddd; padding: 8px 10px; font-size: 10px; line-height: 1.6; }
        .aging td { padding: 4px 8px; font-size: 10px; border: 1px solid #eee; text-align: center; }
        .aging .h { background: #f3f4f6; color: #374151; font-weight: bold; }
    </style>
</head>
<body>

    {{-- Header --}}
    <table style="width:100%;">
        <tr>
            <td style="width:42%; vertical-align:top;">
                <div style="font-size:15px; font-weight:bold;" class="navy">{{ $company['name'] }}</div>
                <div style="font-size:10px; color:#333; margin-top:5px; line-height:1.5;">
                    {!! nl2br(e($company['address'])) !!}<br>
                    Phone: {{ $company['phone'] }}
                </div>
            </td>
            <td style="width:23%; text-align:center; vertical-align:top;">
                @if($logo)<img src="{{ $logo }}" style="height:66px;">@endif
            </td>
            <td style="width:35%; text-align:right; vertical-align:middle;">
                <div class="doc-title">STATEMENT<br>OF ACCOUNTS</div>
            </td>
        </tr>
    </table>

    {{-- Addressee + statement meta --}}
    <table style="width:100%; margin-top:14px;">
        <tr>
            <td style="width:52%; vertical-align:top; padding-right:18px;">
                <div class="bar">{{ $isCustomer ? 'STATEMENT TO' : 'ACCOUNT WITH' }}</div>
                <div style="padding:6px 2px; line-height:1.5;">
                    <strong>{{ $party->name }}</strong>
                    @if($party->address)<br>{!! nl2br(e($party->address)) !!}@endif
                    @if($party->email)<br><span class="muted">{{ $party->email }}</span>@endif
                </div>
            </td>
            <td style="width:48%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:52%;">Date</td>
                        <td style="padding:4px 8px;">{{ $issuedOn }}</td>
                    </tr>
                    <tr>
                        <td class="bar">Period</td>
                        <td style="padding:4px 8px;">{{ $periodLabel }}</td>
                    </tr>
                    <tr>
                        <td class="bar">{{ $isCustomer ? 'Open invoices' : 'Open orders' }}</td>
                        <td style="padding:4px 8px;">{{ count($lines) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="margin-top:14px; font-size:11px; line-height:1.6;">
        Dear {{ $party->name }},<br>
        @if($isCustomer)
            Please find below your statement of accounts{{ $periodSentence }}. We would appreciate settlement of the
            outstanding amounts in due course to the bank account shown below.
        @else
            Please find below our statement of your account{{ $periodSentence }}, showing the orders still outstanding
            for payment.
        @endif
    </p>

    {{-- Outstanding documents --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:20%;">{{ $isCustomer ? 'Invoice No.' : 'Order No.' }}</th>
                <th style="width:22%;">{{ $isCustomer ? 'Order / Enquiry' : 'Enquiry' }}</th>
                <th style="width:12%;">Date</th>
                <th style="width:12%;">Due date</th>
                <th style="width:12%;" class="num">Amount</th>
                <th style="width:11%;" class="num">Paid</th>
                <th style="width:11%;" class="num">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lines as $l)
                <tr>
                    <td>
                        <strong>{{ $l['number'] ?: '—' }}</strong>
                        @if($l['currency'] !== $singleCurrency)
                            <div class="muted">{{ $l['currency'] }}</div>
                        @endif
                    </td>
                    <td>
                        {{ $l['reference'] ?: '—' }}
                        @if($l['vessel'])<div class="muted">{{ $l['vessel'] }}</div>@endif
                    </td>
                    <td>{{ $l['date'] ?: '—' }}</td>
                    <td>
                        {{ $l['due_date'] ?: '—' }}
                        @if($l['overdue_days'] > 0)
                            <div class="late">{{ $l['overdue_days'] }} days overdue</div>
                        @endif
                    </td>
                    <td class="num">{{ number_format($l['amount'], 2) }}</td>
                    <td class="num">{{ $l['settled'] > 0 ? number_format($l['settled'], 2) : '—' }}</td>
                    <td class="num"><strong>{{ number_format($l['outstanding'], 2) }}</strong></td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="padding:18px 8px; text-align:center; color:#6b7280;">
                        Nothing outstanding — this account is fully settled. Thank you.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if(count($lines) > 0)
            <tfoot>
                @foreach($totals as $t)
                    <tr class="totrow">
                        <td colspan="6" class="num">Total outstanding ({{ $t['currency'] }})</td>
                        <td class="num">{{ $t['currency'] }} {{ number_format($t['outstanding'], 2) }}</td>
                    </tr>
                @endforeach
            </tfoot>
        @endif
    </table>

    {{-- Ageing of what is still owed --}}
    @if($showAging)
        <table class="aging" style="width:100%; margin-top:14px;">
            <tr>
                <td class="h" style="width:22%;">Not yet due</td>
                <td class="h" style="width:19.5%;">1 – 30 days</td>
                <td class="h" style="width:19.5%;">31 – 60 days</td>
                <td class="h" style="width:19.5%;">Over 60 days</td>
                <td class="h" style="width:19.5%;">Total</td>
            </tr>
            <tr>
                <td>{{ number_format($aging['current'], 2) }}</td>
                <td>{{ number_format($aging['d30'], 2) }}</td>
                <td>{{ number_format($aging['d60'], 2) }}</td>
                <td class="late">{{ number_format($aging['d90'], 2) }}</td>
                <td><strong>{{ number_format($aging['current'] + $aging['d30'] + $aging['d60'] + $aging['d90'], 2) }}</strong></td>
            </tr>
        </table>
        @if($multiCurrency)
            <p class="muted" style="margin-top:4px;">
                Ageing is shown across all currencies; the totals above are stated per currency.
            </p>
        @endif
    @endif

    {{-- Payments already received in the period, so the figures reconcile --}}
    @if(count($payments) > 0)
        <div style="margin-top:16px;">
            <div class="bar" style="display:inline-block;">
                {{ $isCustomer ? 'Payments received' : 'Payments made' }}{{ $periodSentence ? ' '.trim($periodSentence) : '' }}
            </div>
            <table class="items" style="margin-top:6px;">
                <thead>
                    <tr>
                        <th style="width:22%;">Reference</th>
                        <th style="width:14%;">Date</th>
                        <th style="width:44%;">Applied to</th>
                        <th style="width:20%;" class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($payments as $p)
                        <tr>
                            <td>
                                {{ $p['reference'] ?: $p['number'] }}
                                @if($p['method'])<div class="muted">{{ $p['method'] }}</div>@endif
                            </td>
                            <td>{{ $p['date'] }}</td>
                            <td>
                                @if(count($p['applied_to']))
                                    @foreach($p['applied_to'] as $a)
                                        {{ $a['number'] }} ({{ number_format($a['amount'], 2) }}){{ ! $loop->last ? ', ' : '' }}
                                    @endforeach
                                @else
                                    <span class="muted">on account</span>
                                @endif
                            </td>
                            <td class="num">{{ $p['currency'] }} {{ number_format($p['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- How to pay us (customers only — a vendor is paid BY us) --}}
    @if($isCustomer && count($lines) > 0)
        <div style="margin-top:16px;">
            <div class="bank">
                <strong class="navy">Please remit to:</strong><br>
                {{ $company['name'] }}<br>
                Bank: {{ $company['bank_name'] ?? '' }}<br>
                Account number: {{ $company['bank_account'] ?? '' }}<br>
                Swift / BIC: {{ $company['bank_swift'] ?? '' }}
            </div>
        </div>
    @endif

    <p class="muted" style="margin-top:12px; line-height:1.5;">
        This statement covers documents issued up to {{ $issuedOn }}. If a payment has crossed with this statement,
        please disregard it for that item and accept our thanks.
    </p>

    <div class="page-footer">
        <div style="border-top:1px solid #ddd; padding-top:6px;">
            <strong class="navy" style="font-size:10px;">{{ $company['name'] }}</strong><br>
            UEN No. {{ $company['uen'] }}<br>
            EMAIL: {{ $company['email'] ?? '' }} &nbsp;·&nbsp; {{ $company['website'] ?? '' }}<br>
            Bank: {{ $company['bank_name'] ?? '' }} | Account number: {{ $company['bank_account'] ?? '' }} | Swift: {{ $company['bank_swift'] ?? '' }}
        </div>
    </div>

</body>
</html>
