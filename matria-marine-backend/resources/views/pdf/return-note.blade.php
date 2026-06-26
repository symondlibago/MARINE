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
                    <h1>Return Note — {{ $rn->rtn_number }}</h1>
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
                {{ $rn->vendor_name ?: optional($rn->vendor)->name }}<br>
                @if(optional($rn->vendor)->contact_name){{ $rn->vendor->contact_name }}<br>@endif
                @if(optional($rn->vendor)->email){{ $rn->vendor->email }}<br>@endif
                @if(optional($rn->vendor)->phone){{ $rn->vendor->phone }}<br>@endif
                @if(optional($rn->vendor)->address){{ $rn->vendor->address }}@endif
            </td>
            <td>
                <strong>Return note:</strong> {{ $rn->rtn_number }}<br>
                <strong>Against PO:</strong> {{ optional($rn->purchaseOrder)->po_number ?: '—' }}<br>
                <strong>Status:</strong> {{ ucfirst($rn->status) }}<br>
                <strong>Return date:</strong> {{ $rn->return_date ? $rn->return_date->format('d M Y') : '—' }}<br>
                @if(optional($rn->purchaseOrder)->ship_name)<strong>Vessel:</strong> {{ $rn->purchaseOrder->ship_name }}<br>@endif
                <strong>Currency:</strong> {{ $rn->currency }}
            </td>
        </tr>
    </table>

    <p class="muted" style="margin-top:12px;">
        The following goods are being returned. Please credit our account for the amount shown below.
    </p>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Description</th>
                <th>Reason</th>
                <th>Unit</th>
                <th class="num">Qty returned</th>
                <th class="num">Unit cost</th>
                <th class="num">Credit</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rn->items as $idx => $line)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->reason ?: '—' }}</td>
                    <td>{{ $line->unit ?: '—' }}</td>
                    <td class="num">{{ number_format($line->qty, 2) }}</td>
                    <td class="num">{{ number_format($line->unit_cost, 2) }}</td>
                    <td class="num">{{ number_format($line->line_total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No returned items.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6" class="num">Total credit ({{ $rn->currency }})</td>
                <td class="num">{{ number_format($rn->subtotal, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if($rn->notes)
        <p style="margin-top: 14px;"><strong>Notes:</strong> {{ $rn->notes }}</p>
    @endif

    <p class="muted" style="margin-top: 24px; font-size: 11px;">
        This return note is issued by Matria Marine Services and reduces the amount payable on
        purchase order {{ optional($rn->purchaseOrder)->po_number }}.
    </p>
</body>
</html>
