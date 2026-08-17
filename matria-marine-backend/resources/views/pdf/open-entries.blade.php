<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 26px 30px 92px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        .page-footer { position: fixed; bottom: -74px; left: 30px; right: 30px; text-align: center; font-size: 8px; color: #555; line-height: 1.5; }
        .navy { color: #28364b; }
        .doc-title { font-size: 20px; font-weight: bold; letter-spacing: 1px; color: #28364b; }
        table { border-collapse: collapse; }
        .party { margin-top: 12px; }
        .party-name { background: #eef1f5; padding: 5px 8px; font-size: 11px; font-weight: bold; color: #28364b; border-left: 3px solid #28364b; }
        table.rows { width: 100%; }
        table.rows thead th { background: #28364b; color: #fff; padding: 4px 7px; font-size: 8.5px; text-transform: uppercase; text-align: left; }
        table.rows tbody td { padding: 4px 7px; font-size: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        .num { text-align: right; }
        .muted { color: #6b7280; font-size: 9px; }
        .late { color: #b91c1c; font-weight: bold; }
        .credit { color: #047857; }
        .sub td { padding: 4px 7px; font-size: 10px; font-weight: bold; background: #f8fafc; border-top: 1px solid #cbd5e1; }
        .grand td { padding: 6px 8px; font-size: 11px; font-weight: bold; color: #fff; background: #28364b; }
        .brk { page-break-after: always; }
    </style>
</head>
<body>

    {{-- Header --}}
    <table style="width:100%;">
        <tr>
            <td style="width:46%; vertical-align:top;">
                <div style="font-size:14px; font-weight:bold;" class="navy">{{ $company['name'] }}</div>
                <div class="muted" style="margin-top:4px; line-height:1.5;">{!! nl2br(e($company['address'])) !!}</div>
            </td>
            <td style="width:20%; text-align:center; vertical-align:top;">
                @if($logo)<img src="{{ $logo }}" style="height:52px;">@endif
            </td>
            <td style="width:34%; text-align:right; vertical-align:top;">
                <div class="doc-title">{{ $isCustomer ? 'CUSTOMER' : 'VENDOR' }}<br>OPEN ENTRIES</div>
                <div class="muted" style="margin-top:4px;">
                    Balance on {{ \Illuminate\Support\Carbon::parse($report['as_of'])->format('d.m.y') }}<br>
                    {{ $report['party_count'] }} {{ $isCustomer ? 'customer' : 'vendor' }}{{ $report['party_count'] === 1 ? '' : 's' }}
                    · {{ $report['entry_count'] }} entr{{ $report['entry_count'] === 1 ? 'y' : 'ies' }}
                    {{-- Keep directives off the end of a word: Blade only sees
                         @directive when it does not follow a word character. --}}
                    @unless($report['include_unapplied'])
                        <br>Unapplied entries excluded
                    @endunless
                </div>
            </td>
        </tr>
    </table>

    @forelse($report['parties'] as $p)
        <div class="party @if($newPagePerParty && ! $loop->last) brk @endif">
            <div class="party-name">
                {{ $p['name'] }}
                @if($p['email'])<span class="muted" style="font-weight:normal;"> · {{ $p['email'] }}</span>@endif
            </div>

            <table class="rows">
                <thead>
                    <tr>
                        <th style="width:17%;">Document</th>
                        <th style="width:19%;">{{ $isCustomer ? 'Order / Enquiry' : 'Enquiry' }}</th>
                        <th style="width:11%;">Date</th>
                        <th style="width:13%;">Due date</th>
                        <th style="width:6%;">Cry</th>
                        <th style="width:11%;" class="num">Amount</th>
                        <th style="width:11%;" class="num">Settled</th>
                        <th style="width:12%;" class="num">Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($p['entries'] as $e)
                        <tr>
                            <td><strong>{{ $e['number'] ?: '—' }}</strong></td>
                            <td>
                                {{ $e['reference'] ?: '—' }}
                                @if($e['vessel'])<div class="muted">{{ $e['vessel'] }}</div>@endif
                            </td>
                            <td>{{ $e['date'] ?: '—' }}</td>
                            <td>
                                {{ $e['due_date'] ?: '—' }}
                                @if($e['overdue_days'] > 0)<div class="late">{{ $e['overdue_days'] }}d late</div>@endif
                            </td>
                            <td>{{ $e['currency'] }}</td>
                            <td class="num">{{ number_format($e['amount'], 2) }}</td>
                            <td class="num">{{ $e['settled'] > 0 ? number_format($e['settled'], 2) : '—' }}</td>
                            <td class="num"><strong>{{ number_format($e['outstanding'], 2) }}</strong></td>
                        </tr>
                    @endforeach

                    {{-- Money banked but not sitting against any document on this date --}}
                    @foreach($p['unapplied'] as $u)
                        <tr>
                            <td><strong>{{ $u['reference'] ?: $u['number'] }}</strong></td>
                            <td class="credit">
                                {{ $isCustomer ? 'Payment received' : 'Payment made' }} — on account
                                @if($u['method'])<div class="muted">{{ $u['method'] }}</div>@endif
                            </td>
                            <td>{{ $u['date'] }}</td>
                            <td>—</td>
                            <td>{{ $u['currency'] }}</td>
                            <td class="num credit">−{{ number_format($u['amount'], 2) }}</td>
                            <td class="num">—</td>
                            <td class="num credit"><strong>−{{ number_format($u['amount'], 2) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    @foreach($p['totals'] as $t)
                        <tr class="sub">
                            <td colspan="7" class="num">
                                Balance — {{ $t['currency'] }}
                                @if($t['unapplied'] > 0)
                                    <span class="muted">({{ number_format($t['outstanding'], 2) }} owed less {{ number_format($t['unapplied'], 2) }} on account)</span>
                                @endif
                            </td>
                            <td class="num @if($t['balance'] < 0) credit @endif">{{ number_format($t['balance'], 2) }}</td>
                        </tr>
                    @endforeach
                </tfoot>
            </table>
        </div>
    @empty
        <p style="margin-top:24px; text-align:center; color:#6b7280;">
            Nothing outstanding on {{ \Illuminate\Support\Carbon::parse($report['as_of'])->format('d.m.Y') }}.
        </p>
    @endforelse

    {{-- Grand totals across every party --}}
    @if(count($report['grand_totals']) > 0)
        <table style="width:100%; margin-top:16px;">
            @foreach($report['grand_totals'] as $g)
                <tr class="grand">
                    <td style="width:66%;">
                        Total {{ $isCustomer ? 'receivable' : 'payable' }} — {{ $g['currency'] }}
                        <span style="font-weight:normal; font-size:9px;">
                            · {{ $g['parties'] }} {{ $isCustomer ? 'customer' : 'vendor' }}{{ $g['parties'] === 1 ? '' : 's' }}
                        </span>
                    </td>
                    <td style="width:17%;" class="num" style="font-weight:normal;">
                        @if($g['unapplied'] > 0)
                            <span style="font-size:9px; font-weight:normal;">less {{ number_format($g['unapplied'], 2) }} on account</span>
                        @endif
                    </td>
                    <td style="width:17%;" class="num">{{ $g['currency'] }} {{ number_format($g['balance'], 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="muted" style="margin-top:10px; line-height:1.5;">
        Figures are stated as at {{ \Illuminate\Support\Carbon::parse($report['as_of'])->format('d.m.Y') }}: documents raised after
        that date are excluded, and payments banked after it are not deducted. Balances are kept per currency and never combined.
    </p>

    <div class="page-footer">
        <div style="border-top:1px solid #ddd; padding-top:5px;">
            <strong class="navy">{{ $company['name'] }}</strong> · UEN No. {{ $company['uen'] }} ·
            {{ $company['email'] ?? '' }} · {{ $company['website'] ?? '' }}
        </div>
    </div>

</body>
</html>
