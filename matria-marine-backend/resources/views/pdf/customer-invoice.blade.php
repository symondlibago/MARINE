<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        /* Fixed page footer — repeats at the bottom of every page. DomPDF measures
           a fixed element's `bottom` from the content box, so the negative offset
           pulls it down into the page margin, flush to the bottom edge. */
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
        .itemno { color: #28364b; font-weight: bold; }
        .tot td { padding: 3px 8px; font-size: 11px; }
        @include('pdf.partials.items-css')
        @include('pdf.partials.footer-css')
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
                <div class="doc-title">INVOICE</div>
            </td>
        </tr>
    </table>

    {{-- Bill-to (left) + reference-number boxes (right) --}}
    <table style="width:100%; margin-top:14px;">
        <tr>
            <td style="width:52%; vertical-align:top; padding-right:18px;">
                <div class="bar">BILL TO</div>
                <div style="padding:6px 2px; line-height:1.5;">
                    <strong>{{ $invoice->customer_name ?: '—' }}</strong><br>
                    {!! $invoice->customer_address ? nl2br(e($invoice->customer_address)) : '' !!}
                </div>
            </td>
            <td style="width:48%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:62%;">Invoice No.</td>
                        <td class="bar">Date</td>
                    </tr>
                    <tr>
                        <td class="val"><strong>{{ $invoice->invoice_number }}</strong></td>
                        <td class="val">{{ optional($invoice->issue_date)->format('j/n/Y') ?: $invoice->created_at->format('j/n/Y') }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:62%;">Order No.</td>
                        <td class="bar">Due</td>
                    </tr>
                    <tr>
                        <td class="val">{{ $invoice->customer_reference ?: '—' }}</td>
                        <td class="val">{{ optional($due ?? $invoice->due_date)->format('j/n/Y') ?: '—' }}</td>
                    </tr>
                </table>
                @if(optional($invoice->rfq)->reference)
                    <table style="width:100%; margin-top:8px;">
                        <tr><td class="bar">Quotation Ref.</td><td class="bar">Currency</td></tr>
                        <tr><td class="val">{{ $invoice->rfq->reference }}</td><td class="val">{{ $invoice->currency }}</td></tr>
                    </table>
                @endif
            </td>
        </tr>
    </table>

    {{-- Line items.

         With a second currency asked for, every money column gains a
         converted twin. The invoice is still payable in its own currency —
         the converted side is there because some customers reconcile in
         theirs, and the note under the totals says so outright. --}}
    @php
        $fx = $fx ?? null;
        $cols = $fx ? 7 : 6;
        $money = fn ($amount) => number_format((float) $amount, 2);
        $conv = fn ($amount) => number_format((float) $amount * ($fx['rate'] ?? 0), 2);
        // Counts ITEMS, not rows — a heading is a label over a group and takes
        // no number of its own, so the numbers the customer quotes back at us
        // mean the same lines we see.
        $itemNo = 0;
    @endphp
    <table class="items">
        <thead>
            <tr>
                <th style="text-align:left; width:34px;">Item</th>
                <th style="text-align:left;">Description</th>
                <th class="num" style="width:50px;">Qty</th>
                <th style="text-align:left; width:50px;">Unit</th>
                {{-- One unit price, two amounts — the shape the customer asked
                     for. Converting the unit price as well adds a column that
                     says nothing the line amount does not, and squeezes the
                     description, which is the part anyone reads. --}}
                <th class="num" style="width:{{ $fx ? 80 : 90 }}px;">Unit Price<br><span style="font-weight:normal;">{{ $invoice->currency }}</span></th>
                <th class="num" style="width:{{ $fx ? 95 : 105 }}px;">Amount<br><span style="font-weight:normal;">{{ $invoice->currency }}</span></th>
                @if($fx)
                    <th class="num" style="width:95px;">Amount<br><span style="font-weight:normal;">{{ $fx['code'] }}</span></th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($invoice->items as $line)
                @if($line->is_heading)
                    <tr><td colspan="{{ $cols }}" style="font-weight:bold; padding-top:9px; border-bottom:none;" class="navy">{{ $line->description }}</td></tr>
                @else
                    @php
                        $itemNo++;
                        // One row per block, so a long description can carry on
                        // over a page break instead of jumping the page whole.
                        $blocks = \App\Support\PdfBlocks::split($line->description) ?: [''];
                        $last = count($blocks) - 1;
                    @endphp
                    @foreach($blocks as $b => $block)
                        <tr class="{{ $b < $last ? 'block-open' : '' }} {{ $b > 0 ? 'block-cont' : '' }}">
                            <td class="itemno">{{ $b === 0 ? $itemNo : '' }}</td>
                            <td>
                                {!! nl2br(e($block)) !!}
                                @if($b === $last)
                                    @if($line->code)<br><span class="partno">Part-No.: {{ $line->code }}</span>@endif
                                    @if($line->remarks)<br><span class="sub">{!! nl2br(e($line->remarks)) !!}</span>@endif
                                @endif
                            </td>
                            <td class="num">{{ $b === 0 ? rtrim(rtrim(number_format((float) $line->qty, 3), '0'), '.') : '' }}</td>
                            <td>{{ $b === 0 ? $line->unit : '' }}</td>
                            <td class="num">{{ $b === 0 ? $money($line->unit_price) : '' }}</td>
                            <td class="num">{{ $b === 0 ? $money($line->line_total) : '' }}</td>
                            @if($fx)<td class="num">{{ $b === 0 ? $conv($line->line_total) : '' }}</td>@endif
                        </tr>
                    @endforeach
                @endif
            @empty
                <tr><td colspan="{{ $cols }}">No line items.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totals --}}
    @php
        $packing = (float) $invoice->packing_cost;
        $transport = (float) $invoice->transportation_cost;
        $tax = (float) $invoice->tax_amount;
        $taxRate = (float) $invoice->tax_rate;
    @endphp
    <table style="width:100%; margin-top:10px;">
        <tr>
            <td style="width:{{ $fx ? 38 : 52 }}%; vertical-align:bottom; padding-top:14px;">
                <em class="navy">Thank you for your business!</em>
            </td>
            <td style="width:{{ $fx ? 62 : 48 }}%; vertical-align:top;">
                <table class="tot" style="width:100%;">
                    @if($fx)
                    <tr style="font-size:10px; color:#666;">
                        <td></td>
                        <td class="num" style="width:95px;">{{ $invoice->currency }}</td>
                        <td class="num" style="width:95px;">{{ $fx['code'] }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="text-align:right; color:#444;">Subtotal</td>
                        <td class="num" style="width:{{ $fx ? 95 : 110 }}px;">{{ $money($invoice->subtotal) }}</td>
                        @if($fx)<td class="num">{{ $conv($invoice->subtotal) }}</td>@endif
                    </tr>
                    @if($packing > 0)
                    <tr>
                        <td style="text-align:right; color:#444;">Packing</td>
                        <td class="num">{{ $money($packing) }}</td>
                        @if($fx)<td class="num">{{ $conv($packing) }}</td>@endif
                    </tr>
                    @endif
                    @if($transport > 0)
                    <tr>
                        <td style="text-align:right; color:#444;">Transportation</td>
                        <td class="num">{{ $money($transport) }}</td>
                        @if($fx)<td class="num">{{ $conv($transport) }}</td>@endif
                    </tr>
                    @endif
                    @if($tax > 0)
                    <tr>
                        <td style="text-align:right; color:#444;">GST {{ (float) $taxRate }}%</td>
                        <td class="num">{{ $money($tax) }}</td>
                        @if($fx)<td class="num">{{ $conv($tax) }}</td>@endif
                    </tr>
                    @endif
                    <tr style="font-weight:bold; font-size:14px;">
                        <td class="navy" style="text-align:right; border-top:2px solid #28364b; padding-top:7px;">TOTAL</td>
                        <td class="num navy" style="border-top:2px solid #28364b; padding-top:7px;">{{ $money($invoice->grand_total) }}</td>
                        @if($fx)<td class="num navy" style="border-top:2px solid #28364b; padding-top:7px;">{{ $conv($invoice->grand_total) }}</td>@endif
                    </tr>
                </table>

                @if($fx)
                    {{-- Which number is owed has to be unmistakable: the second
                         column is a conversion for the customer's own books. --}}
                    <div style="margin-top:6px; font-size:9.5px; color:#666; text-align:right; line-height:1.5;">
                        {{-- Printed at the precision it was applied at, so
                             multiplying by this number reproduces the column. --}}
                        Rate: 1 {{ $invoice->currency }} = {{ rtrim(rtrim(number_format($fx['rate'], 6, '.', ''), '0'), '.') }} {{ $fx['code'] }}<br>
                        <span style="color:#888;">{{ $fx['code'] }} shown for reference only —
                        payable in {{ $invoice->currency }}.</span>
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Terms --}}
    @if($invoice->payment_terms || $invoice->delivery_terms || $invoice->origin_type)
        <div style="margin-top:14px; font-size:10px; color:#444; line-height:1.6;">
            @if($invoice->delivery_terms)<strong class="navy">Delivery:</strong> {{ $invoice->delivery_terms }}<br>@endif
            @if($invoice->payment_terms)<strong class="navy">Payment:</strong> {{ $invoice->payment_terms }}<br>@endif
            @if($invoice->origin_type)<strong class="navy">Origin:</strong> {{ $invoice->origin_type }}@endif
        </div>
    @endif

    @if($invoice->notes)
        <p style="margin-top:12px; font-size:10px; color:#444; line-height:1.5;"><strong>Notes:</strong> {{ $invoice->notes }}</p>
    @endif

    {{-- Footer: company + bank details — fixed to the bottom of every page --}}
    @include('pdf.partials.footer', ['bank' => true])

</body>
</html>
