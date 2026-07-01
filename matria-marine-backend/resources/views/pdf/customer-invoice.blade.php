<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .navy { color: #28364b; }
        .doc-title { font-size: 27px; font-weight: bold; letter-spacing: 1px; color: #28364b; }
        .bar { background: #28364b; color: #fff; padding: 4px 8px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .val { padding: 4px 8px; font-size: 11px; }
        table { border-collapse: collapse; }
        table.items { width: 100%; margin-top: 16px; }
        table.items thead th { background: #28364b; color: #fff; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
        table.items tbody td { padding: 5px 8px; font-size: 11px; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; }
        .sub { color: #28364b; font-size: 10px; }
        .partno { color: #6b7280; font-size: 10px; }
        .tot td { padding: 3px 8px; font-size: 11px; }
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
                <div class="doc-title">INVOICE</div>
            </td>
        </tr>
    </table>

    {{-- Bill-to (left) + reference-number boxes (right) --}}
    <table style="width:100%; margin-top:14px;">
        <tr>
            <td style="width:52%; vertical-align:top; padding-right:18px;">
                <div class="bar">BILL TO</div>
                <div style="padding:6px 2px; line-height:1.5;">
                    <strong>{{ $invoice->customer_name ?: '—' }}</strong><br>
                    {!! $invoice->customer_address ? nl2br(e($invoice->customer_address)) : '' !!}
                </div>
            </td>
            <td style="width:48%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:62%;">Invoice No.</td>
                        <td class="bar">Date</td>
                    </tr>
                    <tr>
                        <td class="val"><strong>{{ $invoice->invoice_number }}</strong></td>
                        <td class="val">{{ optional($invoice->issue_date)->format('j/n/Y') ?: $invoice->created_at->format('j/n/Y') }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:62%;">Order No.</td>
                        <td class="bar">Due</td>
                    </tr>
                    <tr>
                        <td class="val">{{ $invoice->customer_reference ?: '—' }}</td>
                        <td class="val">{{ optional($invoice->due_date)->format('j/n/Y') ?: '—' }}</td>
                    </tr>
                </table>
                @if(optional($invoice->rfq)->reference)
                    <table style="width:100%; margin-top:8px;">
                        <tr><td class="bar">Quotation Ref.</td><td class="bar">Currency</td></tr>
                        <tr><td class="val">{{ $invoice->rfq->reference }}</td><td class="val">{{ $invoice->currency }}</td></tr>
                    </table>
                @endif
            </td>
        </tr>
    </table>

    {{-- Line items --}}
    <table class="items">
        <thead>
            <tr>
                <th style="text-align:left;">Description</th>
                <th class="num" style="width:55px;">Qty</th>
                <th style="text-align:left; width:55px;">Unit</th>
                <th class="num" style="width:90px;">Unit Price</th>
                <th class="num" style="width:105px;">Amount ({{ $invoice->currency }})</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoice->items as $line)
                @if($line->is_heading)
                    <tr><td colspan="5" style="font-weight:bold; padding-top:9px; border-bottom:none;" class="navy">{{ $line->description }}</td></tr>
                @else
                    <tr>
                        <td>
                            {!! nl2br(e($line->description)) !!}
                            @if($line->code)<br><span class="partno">Part-No.: {{ $line->code }}</span>@endif
                            @if($line->remarks)<br><span class="sub">{!! nl2br(e($line->remarks)) !!}</span>@endif
                        </td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $line->qty, 3), '0'), '.') }}</td>
                        <td>{{ $line->unit }}</td>
                        <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                        <td class="num">{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="5">No line items.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totals --}}
    @php
        $packing = (float) $invoice->packing_cost;
        $transport = (float) $invoice->transportation_cost;
        $tax = (float) $invoice->tax_amount;
    @endphp
    <table style="width:100%; margin-top:10px;">
        <tr>
            <td style="width:52%; vertical-align:bottom; padding-top:14px;">
                <em class="navy">Thank you for your business!</em>
            </td>
            <td style="width:48%; vertical-align:top;">
                <table class="tot" style="width:100%;">
                    <tr>
                        <td style="text-align:right; color:#444;">Subtotal</td>
                        <td class="num" style="width:110px;">{{ number_format((float) $invoice->subtotal, 2) }}</td>
                    </tr>
                    @if($packing > 0)
                    <tr>
                        <td style="text-align:right; color:#444;">Packing</td>
                        <td class="num">{{ number_format($packing, 2) }}</td>
                    </tr>
                    @endif
                    @if($transport > 0)
                    <tr>
                        <td style="text-align:right; color:#444;">Transportation</td>
                        <td class="num">{{ number_format($transport, 2) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="text-align:right; color:#444;">GST Amount</td>
                        <td class="num">{{ $tax > 0 ? number_format($tax, 2) : '—' }}</td>
                    </tr>
                    <tr style="font-weight:bold; font-size:14px;">
                        <td class="navy" style="text-align:right; border-top:2px solid #28364b; padding-top:7px;">TOTAL ({{ $invoice->currency }})</td>
                        <td class="num navy" style="border-top:2px solid #28364b; padding-top:7px;">{{ number_format((float) $invoice->grand_total, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Terms --}}
    @if($invoice->payment_terms || $invoice->delivery_terms || $invoice->origin_type)
        <div style="margin-top:14px; font-size:10px; color:#444; line-height:1.6;">
            @if($invoice->delivery_terms)<strong class="navy">Delivery:</strong> {{ $invoice->delivery_terms }}<br>@endif
            @if($invoice->payment_terms)<strong class="navy">Payment:</strong> {{ $invoice->payment_terms }}<br>@endif
            @if($invoice->origin_type)<strong class="navy">Origin:</strong> {{ $invoice->origin_type }}@endif
        </div>
    @endif

    @if($invoice->notes)
        <p style="margin-top:12px; font-size:10px; color:#444; line-height:1.5;"><strong>Notes:</strong> {{ $invoice->notes }}</p>
    @endif

    {{-- Footer --}}
    <div style="margin-top:24px; text-align:center;">
        @if($logo)<img src="{{ $logo }}" style="height:30px;"><br>@endif
        <span style="font-size:9px; color:#777;">UEN: {{ $company['uen'] }}</span>
    </div>

</body>
</html>
