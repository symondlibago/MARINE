<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #28364b; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #777; }
        .header { border-bottom: 2px solid #28364b; padding-bottom: 8px; margin-bottom: 12px; }
        .meta td { padding: 2px 0; }
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
                    <h1>Purchase Award — {{ $rfq->reference }}</h1>
                    <div class="muted">Matria Marine Services</div>
                </td>
                <td style="text-align:right; vertical-align:middle; width:90px;">
                    @if($logo)<img src="{{ $logo }}" style="height:54px;">@endif
                </td>
            </tr>
        </table>
    </div>

    <table class="meta">
        <tr>
            <td><strong>Vendor:</strong> {{ $vendor->name }}</td>
            <td><strong>Vessel:</strong> {{ $rfq->ship_name ?: '—' }}</td>
        </tr>
        <tr>
            <td><strong>Delivery port:</strong> {{ $rfq->delivery_port ?: '—' }}</td>
            <td><strong>Currency:</strong> {{ $currency }}</td>
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
            @forelse($lines as $idx => $line)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>{{ $line['description'] }}@if(!empty($line['remarks']))<br><span style="color:#28364b; font-size:10px;">{!! nl2br(e($line['remarks'])) !!}</span>@endif</td>
                    <td>{{ $line['unit'] ?: '—' }}</td>
                    <td class="num">{{ number_format($line['qty'], 2) }}</td>
                    <td class="num">{{ number_format($line['unit_cost'], 2) }}</td>
                    <td class="num">{{ number_format($line['line_total'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No items awarded to this vendor.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="num">Grand total ({{ $currency }})</td>
                <td class="num">{{ number_format($grandTotal, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
