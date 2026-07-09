<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px; }
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
        .sub { color: #28364b; font-size: 10px; }
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
                <div class="doc-title">PURCHASE ORDER</div>
            </td>
        </tr>
    </table>

    {{-- Supplier (left) + reference-number boxes (right) --}}
    <table style="width:100%; margin-top:14px;">
        <tr>
            <td style="width:52%; vertical-align:top; padding-right:18px;">
                <div class="bar">SUPPLIER</div>
                <div style="padding:6px 2px; line-height:1.5;">
                    <strong>{{ $po->vendor->name }}</strong><br>
                    @if($po->vendor->contact_name){{ $po->vendor->contact_name }}<br>@endif
                    @if($po->vendor->email){{ $po->vendor->email }}<br>@endif
                    @if($po->vendor->phone){{ $po->vendor->phone }}<br>@endif
                    @if($po->vendor->address){!! nl2br(e($po->vendor->address)) !!}@endif
                </div>
                @if($po->delivery_address)
                    <div class="bar" style="margin-top:6px;">DELIVER TO</div>
                    <div style="padding:6px 2px; line-height:1.5;">{!! nl2br(e($po->delivery_address)) !!}</div>
                @endif
            </td>
            <td style="width:48%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:62%;">PO Number</td>
                        <td class="bar">Date</td>
                    </tr>
                    <tr>
                        <td class="val"><strong>{{ $po->po_number }}</strong></td>
                        <td class="val">{{ optional($po->issued_date)->format('j/n/Y') ?: $po->created_at->format('j/n/Y') }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:62%;">Currency</td>
                        <td class="bar">Expected</td>
                    </tr>
                    <tr>
                        <td class="val">{{ $po->currency }}</td>
                        <td class="val">{{ optional($po->expected_date)->format('j/n/Y') ?: '—' }}</td>
                    </tr>
                </table>
                @if($po->ship_name || $po->delivery_port)
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:62%;">Vessel</td>
                        <td class="bar">Delivery Port</td>
                    </tr>
                    <tr>
                        <td class="val">{{ $po->ship_name ?: '—' }}</td>
                        <td class="val">{{ $po->delivery_port ?: '—' }}</td>
                    </tr>
                </table>
                @endif
                @if(optional($po->creator)->name)
                    <div style="margin-top:6px; font-size:9px; color:#777; text-align:right;">
                        Prepared by {{ $po->creator->name }}@if($po->creator->phone) · {{ $po->creator->phone }}@endif
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Line items --}}
    <table class="items">
        <thead>
            <tr>
                <th style="text-align:left; width:30px;">#</th>
                <th style="text-align:left;">Description</th>
                <th style="text-align:left; width:55px;">Unit</th>
                <th class="num" style="width:55px;">Qty</th>
                <th class="num" style="width:90px;">Unit Cost</th>
                <th class="num" style="width:105px;">Amount ({{ $po->currency }})</th>
            </tr>
        </thead>
        <tbody>
            @forelse($po->items as $idx => $line)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>
                        {{ $line->description }}
                        @if($line->remarks)<br><span class="sub">{!! nl2br(e($line->remarks)) !!}</span>@endif
                    </td>
                    <td>{{ $line->unit ?: '—' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->qty, 3), '0'), '.') }}</td>
                    <td class="num">{{ number_format((float) $line->unit_cost, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No line items on this purchase order.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Total --}}
    <table style="width:100%; margin-top:10px;">
        <tr>
            <td style="width:52%; vertical-align:bottom; padding-top:12px;">
                <em class="navy">Please reply to confirm acceptance of this purchase order.</em>
            </td>
            <td style="width:48%; vertical-align:top;">
                <table class="tot" style="width:100%;">
                    <tr style="font-weight:bold; font-size:14px;">
                        <td class="navy" style="text-align:right;">TOTAL ({{ $po->currency }})</td>
                        <td class="num navy" style="width:120px;">{{ number_format((float) $po->subtotal, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($po->notes)
        <p style="margin-top:12px; font-size:10px; color:#444;"><strong>Notes:</strong> {{ $po->notes }}</p>
    @endif

    {{-- Prepared by (staff signature, bottom-left — same block as the quotation) --}}
    @if(optional($po->creator)->name)
        <div style="margin-top:26px; font-size:11px; line-height:1.6;">
            <span class="navy" style="font-weight:bold;">Prepared by:</span><br>
            {{ $po->creator->name }}<br>
            @if($po->creator->email)<span style="color:#444;">{{ $po->creator->email }}</span>@endif
            @if($po->creator->phone)@if($po->creator->email) · @endif<span style="color:#444;">{{ $po->creator->phone }}</span>@endif
        </div>
    @endif

    {{-- Footer --}}
    <div style="margin-top:24px; text-align:center;">
        @if($logo)<img src="{{ $logo }}" style="height:30px;"><br>@endif
        <span style="font-size:9px; color:#777;">UEN: {{ $company['uen'] }}</span>
    </div>

</body>
</html>
