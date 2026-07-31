<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px 104px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .navy { color: #28364b; }
        .doc-title { font-size: 25px; font-weight: bold; letter-spacing: 1px; color: #28364b; }
        .bar { background: #28364b; color: #fff; padding: 4px 8px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .val { padding: 4px 8px; font-size: 11px; }
        table { border-collapse: collapse; }
        table.items { width: 100%; margin-top: 16px; }
        table.items thead th { background: #28364b; color: #fff; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
        table.items tbody td { padding: 5px 8px; font-size: 11px; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; }
        .sub { color: #6b7280; font-size: 10px; }
        .tot td { padding: 3px 8px; font-size: 11px; }
        .page-footer { position: fixed; bottom: -86px; left: 34px; right: 34px; text-align: center; font-size: 9px; color: #555; line-height: 1.5; }
    </style>
</head>
<body>

    {{-- Header: company / logo / title --}}
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
                <div class="doc-title">CREDIT MEMO</div>
            </td>
        </tr>
    </table>

    {{-- Credit-to (left) + reference boxes (right) --}}
    <table style="width:100%; margin-top:14px;">
        <tr>
            <td style="width:52%; vertical-align:top; padding-right:18px;">
                <div class="bar">CREDIT TO</div>
                <div style="padding:6px 2px; line-height:1.5;">
                    <strong>{{ $cm->customer_name ?: '—' }}</strong><br>
                    {!! $cm->customer_address ? nl2br(e($cm->customer_address)) : '' !!}
                </div>
            </td>
            <td style="width:48%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:62%;">Credit Memo No.</td>
                        <td class="bar">Date</td>
                    </tr>
                    <tr>
                        <td class="val"><strong>{{ $cm->cm_number }}</strong></td>
                        <td class="val">{{ optional($cm->memo_date)->format('j/n/Y') ?: $cm->created_at->format('j/n/Y') }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:62%;">Reference Invoice</td>
                        <td class="bar">Currency</td>
                    </tr>
                    <tr>
                        <td class="val"><strong>{{ $cm->invoice?->invoice_number ?: '—' }}</strong>@if($cm->invoice?->issue_date) <span class="sub">dated {{ $cm->invoice->issue_date->format('j/n/Y') }}</span>@endif</td>
                        <td class="val">{{ $cm->currency }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($cm->reason)
        <p style="margin-top:12px; font-size:10px; color:#444; line-height:1.5;"><strong class="navy">Reason:</strong> {{ $cm->reason }}</p>
    @endif

    {{-- Credited lines --}}
    <table class="items">
        <thead>
            <tr>
                <th style="text-align:left;">Description</th>
                <th class="num" style="width:55px;">Qty</th>
                <th style="text-align:left; width:55px;">Unit</th>
                <th class="num" style="width:90px;">Unit Price</th>
                <th class="num" style="width:115px;">Credit ({{ $cm->currency }})</th>
            </tr>
        </thead>
        <tbody>
            @forelse($cm->items as $line)
                <tr>
                    <td>
                        {!! nl2br(e($line->description)) !!}
                        @if($line->reason)<br><span class="sub">{{ $line->reason }}</span>@endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->qty, 3), '0'), '.') }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No credited lines.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totals --}}
    <table style="width:100%; margin-top:10px;">
        <tr>
            <td style="width:52%; vertical-align:bottom; padding-top:14px;">
                <em class="navy">This credit memo reduces the amount payable on the referenced invoice.</em>
            </td>
            <td style="width:48%; vertical-align:top;">
                <table class="tot" style="width:100%;">
                    <tr>
                        <td style="text-align:right; color:#444;">Subtotal credited</td>
                        <td class="num" style="width:110px;">{{ number_format((float) $cm->subtotal, 2) }}</td>
                    </tr>
                    @if((float) $cm->tax_amount > 0)
                    <tr>
                        <td style="text-align:right; color:#444;">GST {{ rtrim(rtrim(number_format((float) $cm->tax_rate, 2), '0'), '.') }}%</td>
                        <td class="num">{{ number_format((float) $cm->tax_amount, 2) }}</td>
                    </tr>
                    @endif
                    <tr style="font-weight:bold; font-size:14px;">
                        <td class="navy" style="text-align:right; border-top:2px solid #28364b; padding-top:7px;">TOTAL CREDIT ({{ $cm->currency }})</td>
                        <td class="num navy" style="border-top:2px solid #28364b; padding-top:7px;">{{ number_format((float) $cm->grand_total, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Prepared by (staff signature, bottom-left) --}}
    @if(optional($cm->creator)->name)
        <div style="margin-top:26px; font-size:11px; line-height:1.6;">
            <span class="navy" style="font-weight:bold;">Prepared by:</span><br>
            {{ $cm->creator->name }}<br>
            @if($cm->creator->email)<span style="color:#444;">{{ $cm->creator->email }}</span>@endif
            @if($cm->creator->phone)@if($cm->creator->email) · @endif<span style="color:#444;">{{ $cm->creator->phone }}</span>@endif
        </div>
    @endif

    {{-- Footer: company details — fixed to the bottom of every page --}}
    <div class="page-footer">
        <div style="border-top:1px solid #ddd; padding-top:6px;">
            <strong class="navy" style="font-size:10px;">{{ $company['name'] }}</strong><br>
            UEN No. {{ $company['uen'] }}<br>
            EMAIL: {{ $company['email'] ?? '' }} &nbsp;·&nbsp; {{ $company['website'] ?? '' }}
        </div>
    </div>

</body>
</html>
