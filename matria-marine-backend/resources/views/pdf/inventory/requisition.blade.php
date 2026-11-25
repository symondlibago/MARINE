<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px 96px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .navy { color: #28364b; }
        .doc-title { font-size: 23px; font-weight: bold; letter-spacing: 1px; color: #28364b; }
        .bar { background: #28364b; color: #fff; padding: 4px 8px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .val { padding: 4px 8px; font-size: 11px; }
        table { border-collapse: collapse; }
        table.items { width: 100%; margin-top: 14px; }
        table.items thead th { background: #28364b; color: #fff; padding: 6px 8px; font-size: 10px; text-transform: uppercase; }
        table.items tbody td { padding: 4px 8px; font-size: 10.5px; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; }
        .sub { color: #6b7280; font-size: 9.5px; }
        .tot td { padding: 3px 8px; font-size: 11px; }
        .page-footer { position: fixed; bottom: -78px; left: 34px; right: 34px; text-align: center; font-size: 9px; color: #555; line-height: 1.5; }
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
                @if($logo)<img src="{{ $logo }}" style="height:62px;">@endif
            </td>
            <td style="width:35%; text-align:right; vertical-align:middle;">
                <div class="doc-title">PROVISION ORDER</div>
                <div class="sub" style="margin-top:3px;">Requisition</div>
            </td>
        </tr>
    </table>

    {{-- Vessel (left) + reference boxes (right) --}}
    <table style="width:100%; margin-top:14px;">
        <tr>
            <td style="width:52%; vertical-align:top; padding-right:18px;">
                <div class="bar">VESSEL</div>
                <div style="padding:6px 2px; line-height:1.55;">
                    <strong>{{ $req->vessel?->name ?: '—' }}</strong>
                    @if($req->vessel?->imo)<br><span class="sub">IMO {{ $req->vessel->imo }}</span>@endif
                    <br>Crew on board: {{ $req->vessel?->crew_size }}
                    &nbsp;·&nbsp; Supply period: {{ $req->vessel?->supply_period_days }} days
                </div>

                @if($req->supplier)
                    <div class="bar" style="margin-top:10px;">SUPPLIER</div>
                    <div style="padding:6px 2px; line-height:1.55;">
                        <strong>{{ $req->supplier->name }}</strong>
                        @if($req->supplier->port)<br>{{ $req->supplier->port }}@endif
                        @if($req->supplier->email)<br><span class="sub">{{ $req->supplier->email }}</span>@endif
                    </div>
                @endif
            </td>
            <td style="width:48%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:60%;">Requisition No.</td>
                        <td class="bar">Date</td>
                    </tr>
                    <tr>
                        <td class="val"><strong>{{ $req->req_number }}</strong></td>
                        <td class="val">{{ optional($req->req_date)->format('j/n/Y') }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:60%;">Required By</td>
                        <td class="bar">Currency</td>
                    </tr>
                    <tr>
                        <td class="val">{{ optional($req->required_by)->format('j/n/Y') ?: '—' }}</td>
                        <td class="val">{{ $req->currency }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($req->notes)
        <p style="margin-top:12px; font-size:10px; color:#444; line-height:1.5;"><strong class="navy">Notes:</strong> {{ $req->notes }}</p>
    @endif

    {{-- Ordered lines --}}
    <table class="items">
        <thead>
            <tr>
                <th style="text-align:left; width:70px;">Code</th>
                <th style="text-align:left;">Description</th>
                <th style="text-align:left; width:45px;">Unit</th>
                <th class="num" style="width:52px;">ROB</th>
                <th class="num" style="width:58px;">Target</th>
                <th class="num" style="width:58px;">Order</th>
                @if($money ?? true)
                    <th class="num" style="width:70px;">Unit Price</th>
                    <th class="num" style="width:80px;">Value</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @php $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.'); @endphp
            @forelse($req->items as $line)
                <tr>
                    <td>{{ $line->code }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="num">{{ $qty($line->rob_at_issue) }}</td>
                    <td class="num">{{ $qty($line->target_qty) }}</td>
                    <td class="num"><strong>{{ $qty($line->order_qty) }}</strong></td>
                    @if($money ?? true)
                        <td class="num">{{ $line->unit_price === null ? '—' : number_format((float) $line->unit_price, 2) }}</td>
                        <td class="num">{{ number_format((float) $line->line_total, 2) }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ ($money ?? true) ? 8 : 6 }}">Nothing to order — every item is at or above its target quantity.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totals --}}
    <table style="width:100%; margin-top:10px;">
        <tr>
            <td style="width:54%; vertical-align:bottom; padding-top:14px;">
                <span class="sub">
                    Order quantities are calculated from measured consumption over the last supply period,
                    the {{ $req->vessel?->supply_period_days }}-day supply window and a
                    {{ rtrim(rtrim(number_format((float) ($req->vessel?->safety_margin_pct ?? 0), 2), '0'), '.') }}% safety margin.
                </span>
            </td>
            <td style="width:46%; vertical-align:top;">
                <table class="tot" style="width:100%;">
                    <tr>
                        <td style="text-align:right; color:#444;">Lines ordered</td>
                        <td class="num" style="width:110px;">{{ $req->items->count() }}</td>
                    </tr>
                    @if($money ?? true)
                        @if($req->budget_at_issue !== null)
                        <tr>
                            <td style="text-align:right; color:#444;">Budget</td>
                            <td class="num">{{ number_format((float) $req->budget_at_issue, 2) }}</td>
                        </tr>
                        <tr>
                            <td style="text-align:right; color:#444;">Balance</td>
                            <td class="num">{{ number_format((float) $req->budget_at_issue - (float) $req->total_value, 2) }}</td>
                        </tr>
                        @endif
                        <tr style="font-weight:bold; font-size:14px;">
                            <td class="navy" style="text-align:right; border-top:2px solid #28364b; padding-top:7px;">ORDER VALUE ({{ $req->currency }})</td>
                            <td class="num navy" style="border-top:2px solid #28364b; padding-top:7px;">{{ number_format((float) $req->total_value, 2) }}</td>
                        </tr>
                    @else
                        <tr style="font-weight:bold; font-size:14px;">
                            <td class="navy" style="text-align:right; border-top:2px solid #28364b; padding-top:7px;">DAYS OF SUPPLY</td>
                            <td class="num navy" style="border-top:2px solid #28364b; padding-top:7px;">{{ $req->days_of_supply }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- Prepared by (staff signature, bottom-left) --}}
    @if(optional($req->creator)->name)
        <div style="margin-top:24px; font-size:11px; line-height:1.6;">
            <span class="navy" style="font-weight:bold;">Prepared by:</span><br>
            {{ $req->creator->name }}<br>
            @if($req->creator->email)<span style="color:#444;">{{ $req->creator->email }}</span>@endif
            @if($req->creator->phone)@if($req->creator->email) · @endif<span style="color:#444;">{{ $req->creator->phone }}</span>@endif
        </div>
    @endif

    {{-- Footer: company details — fixed to the bottom of every page --}}
    <div class="page-footer">
        <div style="border-top:1px solid #ddd; padding-top:6px;">
            <strong class="navy" style="font-size:10px;">{{ $company['name'] }}</strong><br>
            UEN No. {{ $company['uen'] }}<br>
            EMAIL: {{ $company['email'] ?? '' }} &nbsp;·&nbsp; {{ $company['website'] ?? '' }}
        </div>
    </div>

</body>
</html>
