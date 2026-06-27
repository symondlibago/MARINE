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
        tfoot td { font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <table style="width:100%;">
            <tr>
                <td style="vertical-align:middle;">
                    <h1>Purchase Order — {{ $po->po_number }}</h1>
                    <div class="muted">Matria Marine Services</div>
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
                <strong>PO number:</strong> {{ $po->po_number }}<br>
                <strong>Status:</strong> {{ ucfirst($po->status) }}<br>
                <strong>Issued:</strong> {{ $po->issued_date ? $po->issued_date->format('d M Y') : '—' }}<br>
                <strong>Expected:</strong> {{ $po->expected_date ? $po->expected_date->format('d M Y') : '—' }}<br>
                <strong>Vessel:</strong> {{ $po->ship_name ?: '—' }}<br>
                <strong>Delivery port:</strong> {{ $po->delivery_port ?: '—' }}<br>
                @if($po->delivery_address)<strong>Deliver to:</strong> {{ $po->delivery_address }}<br>@endif
                <strong>Currency:</strong> {{ $po->currency }}<br>
                @if(optional($po->creator)->name)<strong>Prepared by:</strong> {{ $po->creator->name }}@if($po->creator->phone) · {{ $po->creator->phone }}@endif@endif
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
        <tfoot>
            <tr>
                <td colspan="5" class="num">Total ({{ $po->currency }})</td>
                <td class="num">{{ number_format($po->subtotal, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if($po->notes)
        <p style="margin-top: 14px;"><strong>Notes:</strong> {{ $po->notes }}</p>
    @endif

    <p class="muted" style="margin-top: 24px; font-size: 11px;">
        This purchase order is issued by Matria Marine Services. Please reply to confirm acceptance.
    </p>
</body>
</html>
