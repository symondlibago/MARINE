<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #28364b; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #777; }
        .header { border-bottom: 2px solid #28364b; padding-bottom: 8px; margin-bottom: 12px; }
        .meta { width: 100%; }
        .meta td { padding: 2px 0; width: 50%; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.items th, table.items td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        table.items th { background: #28364b; color: #fff; }
        .num { text-align: right; }
        .small { font-size: 10px; }
        .notquoted { color: #999; font-style: italic; }
        tfoot td { font-weight: bold; }
        .foot { margin-top: 10px; font-size: 10px; color: #777; }
    </style>
</head>
<body>
    <div class="header">
        <table style="width:100%;">
            <tr>
                <td style="vertical-align:middle;">
                    <h1>Vendor Quotation — {{ $rfq->reference }}</h1>
                    <div class="muted">{{ config('procurement.company.name') }}</div>
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
            <td><strong>Quotation no.:</strong> {{ $quote->quotation_number ?: '—' }}</td>
            <td><strong>Delivery port:</strong> {{ $rfq->delivery_port ?: '—' }}</td>
        </tr>
        <tr>
            <td><strong>Currency:</strong> {{ $currency }}</td>
            <td><strong>Lines quoted:</strong> {{ $quotedCount }} of {{ count($lines) }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th>Unit</th>
                <th class="num">Qty</th>
                <th class="num">Unit price</th>
                <th class="num">Amount ({{ $currency }})</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lines as $idx => $line)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>{!! nl2br(e($line['description'])) !!}</td>
                    <td>{{ $line['unit'] ?: '—' }}</td>
                    <td class="num">{{ number_format($line['qty'], 2) }}</td>
                    @if($line['quoted'])
                        <td class="num">{{ number_format($line['unit_cost'], 2) }}</td>
                        <td class="num">{{ number_format($line['line_total'], 2) }}</td>
                    @else
                        <td class="num notquoted" colspan="2">not quoted</td>
                    @endif
                    <td class="small">{!! $line['remarks'] ? nl2br(e($line['remarks'])) : '—' !!}</td>
                </tr>
            @empty
                <tr><td colspan="7">This vendor was not sent any line items.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="num">Total ({{ $currency }})</td>
                <td class="num">{{ number_format($grandTotal, 2) }}</td>
                <td></td>
            </tr>
            @if($currency !== $baseCurrency)
                <tr>
                    <td colspan="5" class="num" style="font-weight:normal;">&asymp; in {{ $baseCurrency }} (rate &times; {{ rtrim(rtrim(number_format((float) $quote->exchange_rate, 6, '.', ''), '0'), '.') }})</td>
                    <td class="num">{{ number_format($grandTotalBase, 2) }}</td>
                    <td></td>
                </tr>
            @endif
        </tfoot>
    </table>

    <p class="foot">Prices as submitted by the vendor.</p>
</body>
</html>
