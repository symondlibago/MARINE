<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #28364b; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #777; }
        .header { border-bottom: 2px solid #28364b; padding-bottom: 8px; margin-bottom: 12px; }
        table.meta { width: 100%; margin-bottom: 4px; }
        table.meta td { padding: 2px 0; vertical-align: top; width: 50%; }
        .req { margin-top: 10px; }
        .req .chip { display: inline-block; background: #eef1f5; border-radius: 10px; padding: 2px 8px; margin: 0 4px 4px 0; font-size: 11px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.items th, table.items td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        table.items th { background: #28364b; color: #fff; }
        .num { text-align: right; }
        .note { margin-top: 14px; font-size: 11px; color: #555; }
        .note .label { font-weight: bold; color: #28364b; }
    </style>
</head>
<body>
    <div class="header">
        <table style="width:100%;">
            <tr>
                <td style="vertical-align:middle;">
                    <h1>Request for Quotation — {{ $rfq->reference }}</h1>
                    <div class="muted">Matria Marine Services</div>
                </td>
                <td style="text-align:right; vertical-align:middle; width:90px;">
                    @if($logo)<img src="{{ $logo }}" style="height:54px;">@endif
                </td>
            </tr>
        </table>
    </div>

    <table class="meta">
        @isset($vendor)
        <tr>
            <td colspan="2" style="width:100%;"><strong>To (Vendor):</strong> {{ $vendor->name }}</td>
        </tr>
        @endisset
        <tr>
            <td><strong>Vessel:</strong> {{ $rfq->ship_name ?: '—' }}</td>
            <td><strong>Delivery port:</strong> {{ $rfq->delivery_port ?: '—' }}</td>
        </tr>
        <tr>
            <td><strong>Priority:</strong> {{ $rfq->priority ? ucfirst($rfq->priority) : '—' }}</td>
            <td><strong>Base currency:</strong> {{ $rfq->base_currency ?: '—' }}</td>
        </tr>
    </table>

    @if(is_array($rfq->requirements) && count($rfq->requirements))
        <div class="req">
            <strong>Requirements:</strong>
            @foreach($rfq->requirements as $req)
                <span class="chip">{{ $req }}</span>
            @endforeach
        </div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th style="width:32px;">#</th>
                <th>Description</th>
                <th class="num" style="width:70px;">Qty</th>
                <th style="width:90px;">Unit</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $idx => $item)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->qty, 2), '0'), '.') }}</td>
                    <td>{{ $item->unit ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No line items on this enquiry.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($rfq->notes)
        <div class="note"><span class="label">Notes:</span> {{ $rfq->notes }}</div>
    @endif
</body>
</html>
