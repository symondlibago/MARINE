<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .navy { color: #28364b; }
        .bar { background: #28364b; color: #fff; padding: 4px 8px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .val { padding: 4px 8px; font-size: 11px; }
        table { border-collapse: collapse; }
        table.items { width: 100%; margin-top: 16px; }
        table.items thead th { background: #28364b; color: #fff; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
        table.items tbody td { padding: 4px 8px; font-size: 11px; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; }
        .totals td { padding: 3px 8px; font-size: 11px; }
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
                @if($logo)<img src="{{ $logo }}" style="height:70px;">@endif
            </td>
            <td style="width:35%; text-align:right; vertical-align:middle;">
                <div style="font-size:24px; font-weight:bold; letter-spacing:1px;" class="navy">PROFORMA INVOICE</div>
            </td>
        </tr>
    </table>

    {{-- Customer / numbers --}}
    <table style="width:100%; margin-top:14px;">
        <tr>
            <td style="width:55%; vertical-align:top; padding-right:18px;">
                <div class="bar">CUSTOMER</div>
                <div style="padding:6px 2px; line-height:1.5;">
                    <strong>{{ $do->customer_name ?: '—' }}</strong><br>
                    {!! $do->customer_address ? nl2br(e($do->customer_address)) : '' !!}
                </div>
                <div class="bar" style="margin-top:6px;">DELIVER TO</div>
                <div style="padding:6px 2px; line-height:1.5;">
                    {!! $do->delivery_address ? nl2br(e($do->delivery_address)) : '—' !!}
                </div>
            </td>
            <td style="width:45%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:55%;">Proforma for DO</td>
                        <td class="bar">Date</td>
                    </tr>
                    <tr>
                        <td class="val"><strong>{{ $do->do_number }}</strong></td>
                        <td class="val">{{ optional($do->order_date)->format('n/j/Y') ?: $do->created_at->format('n/j/Y') }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:10px;">
                    <tr>
                        <td class="bar" style="width:55%;">Currency</td>
                        <td class="bar">Readiness</td>
                    </tr>
                    <tr>
                        <td class="val">{{ $do->currency }}</td>
                        <td class="val">{{ optional($do->readiness_date)->format('n/j/Y') ?: '—' }}</td>
                    </tr>
                </table>
                @if($do->customer_reference)
                <table style="width:100%; margin-top:10px;">
                    <tr><td class="bar">Customer Reference</td></tr>
                    <tr><td class="val">{{ $do->customer_reference }}</td></tr>
                </table>
                @endif
                @if(optional($do->creator)->name)
                <table style="width:100%; margin-top:10px;">
                    <tr><td class="bar">Prepared By</td></tr>
                    <tr><td class="val">{{ $do->creator->name }}@if($do->creator->phone) · {{ $do->creator->phone }}@endif</td></tr>
                </table>
                @endif
            </td>
        </tr>
    </table>

    {{-- Line items (priced) --}}
    <table class="items">
        <thead>
            <tr>
                <th style="text-align:left;">Description</th>
                <th style="text-align:left; width:55px;">Unit</th>
                <th class="num" style="width:50px;">Qty</th>
                <th class="num" style="width:90px;">Unit Price</th>
                <th class="num" style="width:105px;">Amount ({{ $do->currency }})</th>
            </tr>
        </thead>
        <tbody>
            @forelse($do->items as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->qty, 3), '0'), '.') }}</td>
                    <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No line items.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Total --}}
    <table style="width:100%; margin-top:8px;">
        <tr>
            <td style="width:55%; vertical-align:top; padding-top:12px;">
                <em class="navy">This is a proforma invoice for the above delivery order.</em>
            </td>
            <td style="width:45%; vertical-align:top;">
                <table class="totals" style="width:100%;">
                    <tr style="font-weight:bold; font-size:14px;">
                        <td class="navy">TOTAL</td>
                        <td class="num navy">{{ $do->currency }}</td>
                        <td class="num navy">{{ number_format((float) $do->subtotal, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($do->notes)
        <p style="margin-top:14px; font-size:10px; color:#444;"><strong>Notes:</strong> {{ $do->notes }}</p>
    @endif

    {{-- Footer --}}
    <div style="margin-top:26px; text-align:center;">
        @if($logo)<img src="{{ $logo }}" style="height:34px;"><br>@endif
        <span style="font-size:9px; color:#777;">UEN: {{ $company['uen'] }}</span>
    </div>

</body>
</html>
