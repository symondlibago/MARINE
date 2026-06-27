<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #28364b; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #777; }
        .header { border-bottom: 2px solid #28364b; padding-bottom: 8px; margin-bottom: 12px; }
        table.cols { width: 100%; margin-bottom: 4px; }
        table.cols td { vertical-align: top; width: 50%; padding: 2px 0; line-height: 1.5; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.items th, table.items td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        table.items th { background: #28364b; color: #fff; }
        .num { text-align: right; }
        table.totals { width: 45%; margin-left: 55%; margin-top: 10px; border-collapse: collapse; }
        table.totals td { padding: 4px 8px; }
        table.totals tr.grand td { border-top: 2px solid #28364b; font-weight: bold; font-size: 14px; }
        .credit { color: #b45309; }
    </style>
</head>
<body>
    <div class="header">
        <table style="width:100%;">
            <tr>
                <td style="vertical-align:middle;">
                    <h1>Final Invoice</h1>
                    <div class="muted">{{ $company['name'] }} · for {{ $po->po_number }}</div>
                </td>
                <td style="text-align:right; vertical-align:middle; width:90px;">
                    @if($logo)<img src="{{ $logo }}" style="height:54px;">@endif
                </td>
            </tr>
        </table>
    </div>

    <table class="cols">
        <tr>
            <td>
                <strong>Supplier</strong><br>
                {{ $po->vendor->name }}<br>
                @if($po->vendor->contact_name){{ $po->vendor->contact_name }}<br>@endif
                @if($po->vendor->email){{ $po->vendor->email }}<br>@endif
                @if($po->vendor->phone){{ $po->vendor->phone }}<br>@endif
                @if($po->vendor->address){{ $po->vendor->address }}@endif
            </td>
            <td>
                <strong>Invoice for PO:</strong> {{ $po->po_number }}<br>
                <strong>Date:</strong> {{ now()->format('d M Y') }}<br>
                <strong>Status:</strong> {{ ucfirst($po->status) }}<br>
                @if($po->ship_name)<strong>Vessel:</strong> {{ $po->ship_name }}<br>@endif
                @if($po->delivery_address)<strong>Deliver to:</strong> {{ $po->delivery_address }}<br>@endif
                <strong>Currency:</strong> {{ $po->currency }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Description</th>
                <th>Unit</th>
                <th class="num">Qty</th>
                <th class="num">Unit cost</th>
                <th class="num">Line total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($po->items as $idx => $line)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->unit ?: '—' }}</td>
                    <td class="num">{{ number_format($line->qty, 2) }}</td>
                    <td class="num">{{ number_format($line->unit_cost, 2) }}</td>
                    <td class="num">{{ number_format($line->line_total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No line items on this purchase order.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($returnsTotal > 0 && $po->returnNote)
        <p style="margin-top:14px;"><strong>Returns / rejected items</strong>
            @if($po->returnNote->rtn_number) ({{ $po->returnNote->rtn_number }})@endif:</p>
        <table class="items" style="margin-top:4px;">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Reason</th>
                    <th class="num">Qty returned</th>
                    <th class="num">Unit cost</th>
                    <th class="num">Credit</th>
                </tr>
            </thead>
            <tbody>
                @foreach($po->returnNote->items as $r)
                    <tr>
                        <td>{{ $r->description }}</td>
                        <td>{{ $r->reason ?: '—' }}</td>
                        <td class="num">{{ number_format($r->qty, 2) }}</td>
                        <td class="num">{{ number_format($r->unit_cost, 2) }}</td>
                        <td class="num credit">− {{ number_format($r->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr>
            <td>Order total</td>
            <td class="num">{{ number_format($po->subtotal, 2) }} {{ $po->currency }}</td>
        </tr>
        @if($returnsTotal > 0)
            <tr>
                <td class="credit">Less returns</td>
                <td class="num credit">− {{ number_format($returnsTotal, 2) }} {{ $po->currency }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>Net payable</td>
            <td class="num">{{ number_format($netPayable, 2) }} {{ $po->currency }}</td>
        </tr>
    </table>

    @if($po->notes)
        <p style="margin-top: 18px;"><strong>Notes:</strong> {{ $po->notes }}</p>
    @endif

    <p class="muted" style="margin-top: 24px; font-size: 11px;">
        This final invoice reflects the amount payable to the supplier for {{ $po->po_number }}{{ $returnsTotal > 0 ? ' after deducting returned / rejected items' : '' }}.
    </p>
</body>
</html>
