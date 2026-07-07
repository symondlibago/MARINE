<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px 100px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        /* Fixed page footer — repeats at the bottom of every page. DomPDF measures
           a fixed element's `bottom` from the content box, so the negative offset
           pulls it down into the page margin, flush to the bottom edge. */
        .page-footer { position: fixed; bottom: -82px; left: 34px; right: 34px; text-align: center; font-size: 9px; color: #777; line-height: 1.45; }
        .cover { margin-top: 14px; font-size: 11px; color: #333; line-height: 1.5; }
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
                <div class="doc-title">QUOTATION</div>
            </td>
        </tr>
    </table>

    {{-- Bill-to (left) + reference-number boxes (right) --}}
    <table style="width:100%; margin-top:14px;">
        <tr>
            <td style="width:52%; vertical-align:top; padding-right:18px;">
                <div class="bar">BILL TO</div>
                <div style="padding:6px 2px; line-height:1.5;">
                    <strong>{{ $offer->customer_name ?: '—' }}</strong><br>
                    {!! $offer->customer_address ? nl2br(e($offer->customer_address)) : '' !!}
                </div>
                @if(optional($offer->rfq)->ship_name)
                    <div style="padding:0 2px;"><strong class="navy">Vessel:</strong> {{ $offer->rfq->ship_name }}</div>
                @endif
            </td>
            <td style="width:48%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:62%;">Quotation Number</td>
                        <td class="bar">Date</td>
                    </tr>
                    <tr>
                        <td class="val"><strong>{{ $offer->offer_number }}</strong></td>
                        <td class="val">{{ $offer->created_at->format('j/n/Y') }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:62%;">Order No.</td>
                        <td class="bar">Validity</td>
                    </tr>
                    <tr>
                        <td class="val">{{ optional($offer->rfq)->customer_reference ?: '—' }}</td>
                        <td class="val">{{ optional($offer->valid_until)->format('j/n/Y') ?: '—' }}</td>
                    </tr>
                </table>
                @php
                    // Overall "Delivery" = the line-item lead time (usually one value
                    // applied to every line via "Set all lead time"). List distinct values.
                    $leadTimes = $offer->items->pluck('lead_time')->map(fn ($v) => trim((string) $v))->filter()->unique()->values();
                    $deliveryLead = $leadTimes->isNotEmpty() ? $leadTimes->implode(', ') : null;
                @endphp
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:62%;">Delivery Terms</td>
                        <td class="bar">Currency</td>
                    </tr>
                    <tr>
                        <td class="val">{{ $offer->delivery_terms ?: '—' }}</td>
                        <td class="val">{{ $offer->currency }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:62%;">Payment</td>
                        <td class="bar">Delivery</td>
                    </tr>
                    <tr>
                        <td class="val">{{ $offer->payment_terms ?: '—' }}</td>
                        <td class="val">{{ $deliveryLead ?: '—' }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:62%;">Origin</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td class="val">{{ $offer->origin_type ?: '—' }}</td>
                        <td></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Cover note (copied from the standard quotation letter) --}}
    <div class="cover">
        Dear Sirs,<br><br>
        We have the pleasure in submitting our price and delivery terms for the items below. Please do not hesitate to contact us if you have any questions, and when ordering, please reference the quotation number above.
    </div>

    {{-- Line items (customer-facing: no base price / markup shown) --}}
    <table class="items">
        <thead>
            <tr>
                <th style="text-align:left;">Description</th>
                <th class="num" style="width:55px;">Qty</th>
                <th style="text-align:left; width:55px;">Unit</th>
                <th class="num" style="width:90px;">Unit Price</th>
                <th class="num" style="width:105px;">Amount ({{ $offer->currency }})</th>
            </tr>
        </thead>
        <tbody>
            @forelse($offer->items as $line)
                @if($line->is_heading)
                    <tr><td colspan="5" style="font-weight:bold; padding-top:9px; border-bottom:none;" class="navy">{{ $line->description }}</td></tr>
                @else
                    @php
                        $qty = (float) $line->qty;
                        $eff = $qty > 0 ? (float) $line->line_total / $qty : (float) $line->unit_price; // effective unit price (net of any discount)
                    @endphp
                    <tr>
                        <td>
                            {!! nl2br(e($line->description)) !!}
                            @if($line->code)<br><span class="partno">Part-No.: {{ $line->code }}</span>@endif
                            @if($line->remarks)<br><span class="sub">{!! nl2br(e($line->remarks)) !!}</span>@endif
                        </td>
                        <td class="num">{{ rtrim(rtrim(number_format($qty, 3), '0'), '.') }}</td>
                        <td>{{ $line->unit }}</td>
                        <td class="num">{{ number_format($eff, 2) }}</td>
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
        $packing = (float) $offer->packing_cost;
        $transport = (float) $offer->transportation_cost;
        $tax = (float) $offer->tax_amount;
        $taxRate = (float) $offer->tax_rate;
        $grand = (float) $offer->grand_total ?: (float) $offer->subtotal;
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
                        <td class="num" style="width:110px;">{{ number_format((float) $offer->subtotal, 2) }}</td>
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
                    @if($tax > 0)
                    <tr>
                        <td style="text-align:right; color:#444;">GST {{ (float) $taxRate }}%</td>
                        <td class="num">{{ number_format($tax, 2) }}</td>
                    </tr>
                    @endif
                    <tr style="font-weight:bold; font-size:14px;">
                        <td class="navy" style="text-align:right; border-top:2px solid #28364b; padding-top:7px;">TOTAL ({{ $offer->currency }})</td>
                        <td class="num navy" style="border-top:2px solid #28364b; padding-top:7px;">{{ number_format($grand, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($offer->notes)
        <p style="margin-top:12px; font-size:10px; color:#444; line-height:1.5;"><strong>Notes:</strong> {{ $offer->notes }}</p>
    @endif

    {{-- Quoted by (staff signature, bottom-left) --}}
    @if(optional($offer->creator)->name)
        <div style="margin-top:26px; font-size:11px; line-height:1.6;">
            <span class="navy" style="font-weight:bold;">Quoted by:</span><br>
            {{ $offer->creator->name }}<br>
            @if($offer->creator->email)<span style="color:#444;">{{ $offer->creator->email }}</span>@endif
            @if($offer->creator->phone)@if($offer->creator->email) · @endif<span style="color:#444;">{{ $offer->creator->phone }}</span>@endif
        </div>
    @endif

    {{-- Footer: full company details — fixed to the bottom of every page --}}
    <div class="page-footer">
        <div style="border-top:1px solid #ddd; padding-top:6px;">
            <strong class="navy" style="font-size:10px;">{{ $company['name'] }}</strong><br>
            {!! nl2br(e($company['address'])) !!}<br>
            email: {{ $company['email'] }} &nbsp;·&nbsp; UEN: {{ $company['uen'] }}
        </div>
    </div>

</body>
</html>
