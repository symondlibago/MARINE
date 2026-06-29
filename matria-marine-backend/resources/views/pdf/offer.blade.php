<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 30px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .navy { color: #28364b; }
        a { color: inherit; }

        /* Right-hand details list (label : value) */
        table.details td { padding: 1px 0; font-size: 11px; vertical-align: top; }
        table.details td.lbl { font-weight: bold; color: #28364b; width: 95px; }

        /* Items */
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items thead th {
            border-top: 2px solid #28364b; border-bottom: 1px solid #28364b;
            padding: 6px 6px; font-size: 10px; color: #28364b; text-transform: none;
        }
        table.items tbody td { padding: 6px 6px; font-size: 11px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .num { text-align: right; }
        .partno { color: #6b7280; font-size: 10px; }
    </style>
</head>
<body>

    {{-- Header: company / logo / title --}}
    <table style="width:100%;">
        <tr>
            <td style="width:55%; vertical-align:top;">
                <div style="font-size:16px; font-weight:bold;" class="navy">{{ $company['name'] }}</div>
                <div style="font-size:10px; color:#444; margin-top:5px; line-height:1.5;">
                    {!! nl2br(e($company['address'])) !!}<br>
                    Phone: {{ $company['phone'] }}
                </div>
            </td>
            <td style="width:20%; text-align:center; vertical-align:top;">
                @if($logo)<img src="{{ $logo }}" style="height:62px;">@endif
            </td>
            <td style="width:25%; text-align:right; vertical-align:middle;">
                <div style="font-size:28px; font-weight:bold; letter-spacing:1px;" class="navy">QUOTATION</div>
            </td>
        </tr>
    </table>

    <div style="border-bottom:2px solid #28364b; margin-top:8px;"></div>

    {{-- Customer (left)  +  document details (right) --}}
    <table style="width:100%; margin-top:16px;">
        <tr>
            <td style="width:52%; vertical-align:top; padding-right:24px; line-height:1.55;">
                <div style="color:#6b7280; font-size:10px;">Customer:</div>
                <div style="font-weight:bold; font-size:12px; margin-top:2px;" class="navy">{{ $offer->customer_name ?: '—' }}</div>
                @if($offer->customer_address)
                    <div>{!! nl2br(e($offer->customer_address)) !!}</div>
                @endif
                @if(optional($offer->rfq)->ship_name)
                    <div style="margin-top:4px;"><strong>Principal / Vessel:</strong> {{ $offer->rfq->ship_name }}</div>
                @endif
                @if(optional($offer->rfq)->customer_reference)
                    <div><strong>Reference:</strong> {{ $offer->rfq->customer_reference }}</div>
                @endif
            </td>
            <td style="width:48%; vertical-align:top;">
                <table class="details" style="width:100%;">
                    <tr>
                        <td class="lbl">Our reference:</td>
                        <td>{{ $offer->offer_number }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Date:</td>
                        <td>{{ $offer->created_at->format('j F Y') }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Validity:</td>
                        <td>{{ optional($offer->valid_until)->format('j F Y') ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Currency:</td>
                        <td>{{ $offer->currency }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Delivery:</td>
                        <td>{{ $offer->delivery_terms ?: '—' }}</td>
                    </tr>
                    @if($offer->origin_type)
                    <tr>
                        <td class="lbl">Origin:</td>
                        <td>{{ $offer->origin_type }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="lbl">Payment:</td>
                        <td>{{ $offer->payment_terms ?: '—' }}</td>
                    </tr>
                    @if(optional($offer->creator)->name)
                    <tr>
                        <td class="lbl">Quoted by:</td>
                        <td>{{ $offer->creator->name }}@if($offer->creator->phone) · {{ $offer->creator->phone }}@endif</td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- Greeting --}}
    <p style="margin-top:20px; margin-bottom:4px;">Dear Sirs,</p>
    <p style="margin-top:0; line-height:1.5;">
        We have the pleasure in submitting our price and delivery terms for the items below.
        Please do not hesitate to contact us if you have any questions and when ordering,
        please reference the quotation number above.
    </p>

    {{-- Line items (customer-facing: no base price / markup shown) --}}
    <table class="items">
        <thead>
            <tr>
                <th style="text-align:left; width:46px;">No.</th>
                <th style="text-align:left;">Item/Article, Description, Part-No.</th>
                <th class="num" style="width:60px;">Quantity</th>
                <th style="text-align:left; width:54px;">Unit</th>
                <th class="num" style="width:78px;">Price</th>
                <th class="num" style="width:70px;">Disc.</th>
                <th class="num" style="width:90px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @php $lineNo = 0; @endphp
            @forelse($offer->items as $line)
                @if($line->is_heading)
                    <tr><td colspan="7" style="font-weight:bold; padding-top:9px;" class="navy">{{ $line->description }}</td></tr>
                @else
                    @php $lineNo += 10000; @endphp
                    <tr>
                        <td>{{ $lineNo }}</td>
                        <td>
                            {!! nl2br(e($line->description)) !!}
                            @if($line->code)<br><span class="partno">Part-No.: {{ $line->code }}</span>@endif
                            @if($line->remarks)<br><span style="color:#28364b; font-size:10px;">{!! nl2br(e($line->remarks)) !!}</span>@endif
                        </td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $line->qty, 3), '0'), '.') }}</td>
                        <td>{{ $line->unit }}</td>
                        <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                        <td class="num">{{ (float) $line->discount_amount > 0 ? number_format((float) $line->discount_amount * (float) $line->qty, 2) : '' }}</td>
                        <td class="num">{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="7">No line items.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totals (items subtotal + delivery charges + grand total) --}}
    @php
        $packing = (float) $offer->packing_cost;
        $transport = (float) $offer->transportation_cost;
        $hasDelivery = $packing > 0 || $transport > 0;
        $grand = $hasDelivery ? (float) $offer->grand_total : (float) $offer->subtotal;
    @endphp
    <table style="width:100%; margin-top:12px;">
        <tr>
            <td style="width:58%;"></td>
            <td style="width:42%;">
                <table style="width:100%; border-collapse:collapse;">
                    @if($hasDelivery)
                        <tr>
                            <td style="text-align:right; padding:2px 0; color:#444;">Subtotal:</td>
                            <td class="num" style="padding:2px 0; width:110px;">{{ number_format((float) $offer->subtotal, 2) }}</td>
                        </tr>
                        @if($packing > 0)
                        <tr>
                            <td style="text-align:right; padding:2px 0; color:#444;">Packing:</td>
                            <td class="num" style="padding:2px 0;">{{ number_format($packing, 2) }}</td>
                        </tr>
                        @endif
                        @if($transport > 0)
                        <tr>
                            <td style="text-align:right; padding:2px 0; color:#444;">Transportation:</td>
                            <td class="num" style="padding:2px 0;">{{ number_format($transport, 2) }}</td>
                        </tr>
                        @endif
                    @endif
                    <tr>
                        <td style="text-align:right; border-top:2px solid #28364b; padding-top:8px;">
                            <span style="font-size:14px; font-weight:bold;" class="navy">Total ({{ $offer->currency }}):</span>
                        </td>
                        <td class="num" style="border-top:2px solid #28364b; padding-top:8px;">
                            <span style="font-size:14px; font-weight:bold;" class="navy">{{ number_format($grand, 2) }}</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($offer->notes)
        <p style="margin-top:16px; font-size:10px; color:#444; line-height:1.5;"><strong>Notes:</strong> {{ $offer->notes }}</p>
    @endif

    <p style="margin-top:22px;"><em class="navy">Thank you for your enquiry — we look forward to your order.</em></p>

    {{-- Footer --}}
    <div style="margin-top:24px; text-align:center;">
        @if($logo)<img src="{{ $logo }}" style="height:30px;"><br>@endif
        <span style="font-size:9px; color:#777;">UEN: {{ $company['uen'] }}</span>
    </div>

</body>
</html>
