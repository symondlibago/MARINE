<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* Bottom margin leaves room for the fixed footer below. */
        @page { margin: 28px 34px 100px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        /* Fixed page footer — repeats at the bottom of every page. DomPDF measures
           a fixed element's `bottom` from the content box, so the negative offset
           pulls it down into the page margin, flush to the bottom edge. */
        .page-footer { position: fixed; bottom: -82px; left: 34px; right: 34px; text-align: center; font-size: 9px; color: #777; line-height: 1.45; }
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
                    <strong>{{ $pf['customer_name'] ?: '—' }}</strong><br>
                    {!! $pf['customer_address'] ? nl2br(e($pf['customer_address'])) : '' !!}
                </div>
                @if($pf['deliver_to'])
                <div class="bar" style="margin-top:6px;">DELIVER TO</div>
                <div style="padding:6px 2px; line-height:1.5;">
                    {!! nl2br(e($pf['deliver_to'])) !!}
                </div>
                @endif
            </td>
            <td style="width:45%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:55%;">Proforma No.</td>
                        <td class="bar">Date</td>
                    </tr>
                    <tr>
                        <td class="val">
                            <strong>{{ $pf['number'] }}</strong>
                            <br><span style="font-size:9px; color:#777;">{{ $pf['source_line'] }}</span>
                        </td>
                        <td class="val">{{ $pf['date'] }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:10px;">
                    <tr>
                        <td class="bar" style="width:55%;">Currency</td>
                        <td class="bar">{{ $pf['second_label'] }}</td>
                    </tr>
                    <tr>
                        <td class="val">{{ $pf['currency'] }}</td>
                        <td class="val">{{ $pf['second_value'] }}</td>
                    </tr>
                </table>
                @if($pf['customer_reference'])
                <table style="width:100%; margin-top:10px;">
                    <tr><td class="bar">Customer Reference</td></tr>
                    <tr><td class="val">{{ $pf['customer_reference'] }}</td></tr>
                </table>
                @endif
                @if($pf['prepared_by'])
                <table style="width:100%; margin-top:10px;">
                    <tr><td class="bar">Prepared By</td></tr>
                    <tr><td class="val">{{ $pf['prepared_by'] }}</td></tr>
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
                <th class="num" style="width:105px;">Amount ({{ $pf['currency'] }})</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pf['items'] as $line)
                <tr>
                    <td>
                        {!! nl2br(e($line['description'])) !!}
                        @if($line['remarks'])<br><span style="color:#28364b; font-size:10px;">{!! nl2br(e($line['remarks'])) !!}</span>@endif
                        {{-- Already taken off the amount at the right; spelled
                             out so the customer sees what they were given. --}}
                        @if(($line['discount_amount'] ?? 0) > 0)
                            <br><span style="color:#92400e; font-size:10px;">Less {{ rtrim(rtrim(number_format((float) $line['discount_pct'], 2), '0'), '.') }}% discount — {{ number_format((float) $line['discount_amount'], 2) }}</span>
                        @endif
                    </td>
                    <td>{{ $line['unit'] }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($line['qty'], 3), '0'), '.') }}</td>
                    <td class="num">{{ number_format($line['unit_price'], 2) }}</td>
                    <td class="num">{{ number_format($line['line_total'], 2) }}</td>
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
                <em class="navy">{{ $pf['standfirst'] }}</em>
            </td>
            <td style="width:45%; vertical-align:top;">
                <table class="totals" style="width:100%;">
                    @foreach($pf['extra_totals'] as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="num">{{ $pf['currency'] }}</td>
                            <td class="num">{{ number_format((float) $row['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                    <tr style="font-weight:bold; font-size:14px;">
                        <td class="navy">TOTAL</td>
                        <td class="num navy">{{ $pf['currency'] }}</td>
                        <td class="num navy">{{ number_format($pf['total'], 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($pf['notes'])
        <p style="margin-top:14px; font-size:10px; color:#444;"><strong>Notes:</strong> {{ $pf['notes'] }}</p>
    @endif

    {{-- The logo sits with the content; the company details repeat on every
         page below, the same as the quotation and the invoice. --}}
    @if($logo)
        <div style="margin-top:26px; text-align:center;">
            <img src="{{ $logo }}" style="height:34px;">
        </div>
    @endif

    <div class="page-footer">
        <div style="border-top:1px solid #ddd; padding-top:6px;">
            <strong class="navy" style="font-size:10px;">{{ $company['name'] }}</strong><br>
            {!! nl2br(e($company['address'])) !!}<br>
            email: {{ $company['email'] }} &nbsp;·&nbsp; UEN: {{ $company['uen'] }}
        </div>
    </div>

</body>
</html>
