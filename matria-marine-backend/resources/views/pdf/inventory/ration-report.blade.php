<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .navy { color: #28364b; }
        .doc-title { font-size: 23px; font-weight: bold; letter-spacing: 1px; color: #28364b; }
        .bar { background: #28364b; color: #fff; padding: 4px 8px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .sub { color: #6b7280; font-size: 9.5px; }
        table { border-collapse: collapse; }
        .num { text-align: right; }

        /* The invoice body: few rows, read closely. */
        table.charges { width: 100%; margin-top: 8px; }
        table.charges thead th { background: #28364b; color: #fff; padding: 5px 8px; font-size: 10px; text-transform: uppercase; }
        table.charges tbody td { padding: 5px 8px; font-size: 11px; border-bottom: 1px solid #eee; }
        table.charges tfoot td { padding: 7px 8px; font-size: 12px; font-weight: bold; border-top: 2px solid #28364b; }

        /* The daily sheet: a month of rows, read across. */
        table.sheet { width: 100%; margin-top: 8px; }
        table.sheet th { background: #28364b; color: #fff; padding: 4px 3px; font-size: 8px; font-weight: bold; }
        table.sheet td { padding: 3px; font-size: 8.5px; border-bottom: 1px solid #eee; }
        table.sheet tbody tr:nth-child(even) td { background: #f8fafc; }
        table.sheet tfoot td { padding: 5px 3px; font-size: 8.5px; font-weight: bold; border-top: 2px solid #28364b; background: #fff; }
        .group { border-left: 1px solid #94a3b8; }
        .muted { color: #9ca3af; }

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
                @if($logo)<img src="{{ $logo }}" style="height:62px;">@endif
            </td>
            <td style="width:35%; text-align:right; vertical-align:middle;">
                <div class="doc-title">RATION REPORT</div>
                <div class="sub" style="margin-top:3px;">Catering account for the period</div>
            </td>
        </tr>
    </table>

    {{-- Vessel and period --}}
    <table style="width:100%; margin-top:14px;">
        <tr>
            <td style="width:52%; vertical-align:top; padding-right:18px;">
                <div class="bar">VESSEL</div>
                <div style="padding:6px 2px; line-height:1.55;">
                    <strong>{{ $vessel->name }}</strong>
                    @if($vessel->imo)<br><span class="sub">IMO {{ $vessel->imo }}</span>@endif
                </div>
            </td>
            <td style="width:48%; vertical-align:top;">
                <table style="width:100%;">
                    <tr>
                        <td class="bar" style="width:50%;">Period</td>
                        <td class="bar">Days fed</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 8px;">{{ $from->format('d.m.Y') }} &ndash; {{ $to->format('d.m.Y') }}</td>
                        <td style="padding:4px 8px;">{{ $totals['days_recorded'] }} of {{ $from->diffInDays($to) + 1 }}</td>
                    </tr>
                </table>
                <table style="width:100%; margin-top:8px;">
                    <tr>
                        <td class="bar" style="width:50%;">Total rations</td>
                        <td class="bar">Average a day</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 8px;">{{ number_format($totals['total_rations'], 2) }}</td>
                        <td style="padding:4px 8px;">{{ number_format($totals['average_rations_per_day'], 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- What is being charged, and on what basis --}}
    <p style="margin-top:16px; font-size:11px;">
        Charged as per catering agreement for the period
        <strong>{{ $from->format('d.m.Y') }} &ndash; {{ $to->format('d.m.Y') }}</strong>.
    </p>

    <table class="charges">
        <thead>
            <tr>
                <th style="text-align:left;">Consumption for</th>
                <th class="num" style="width:90px;">Quantity</th>
                <th style="text-align:left; width:70px;">Unit</th>
                <th class="num" style="width:90px;">Rate</th>
                <th class="num" style="width:110px;">Amount ({{ $currency }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach($totals['charges'] as $line)
                <tr>
                    <td>{{ $line['label'] }}</td>
                    <td class="num">{{ number_format($line['quantity'], 2) }}</td>
                    <td>{{ $line['unit'] }}</td>
                    <td class="num">{{ number_format($line['rate'], 4) }}</td>
                    <td class="num">{{ number_format($line['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="navy">TOTAL</td>
                <td class="num navy">{{ number_format($totals['total_rations'], 2) }}</td>
                <td class="navy">Rations</td>
                <td></td>
                <td class="num navy">{{ number_format($totals['amount'], 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="sub" style="margin-top:8px; line-height:1.6;">
        Crew and passengers are charged by the ration — three meals feed one man for one day. Visitors are charged by
        the meal. A quantity of rations and a quantity of meals are therefore not the same measure, and the total
        rations above is the sum of all four lines expressed in rations.
    </p>

    {{--
        Who the figures are for.
        Only printed when the roster has been kept — a heading over an empty
        list reads as an omission, and a report with no names at all is still
        valid, just less easy to check.
    --}}
    @if(isset($crew) && count($crew))
        <div class="bar" style="margin-top:14px;">ON BOARD &nbsp;·&nbsp; {{ count($crew) }}</div>

        <table class="sheet" style="margin-top:0;">
            <thead>
                <tr>
                    <th style="width:6%;">#</th>
                    <th style="width:28%;">Rank</th>
                    <th>Name</th>
                    <th style="width:22%;">Charged as</th>
                </tr>
            </thead>
            <tbody>
                @foreach($crew as $i => $member)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>{{ $member->rank ?: '—' }}</td>
                        <td>{{ $member->name }}</td>
                        <td>{{ $member->categoryLabel() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- The daily sheet it was all added up from --}}
    <div style="page-break-before: always;"></div>

    <div class="bar" style="margin-top:4px;">DAILY RECORD &nbsp;·&nbsp; {{ $vessel->name }} &nbsp;·&nbsp; {{ $from->format('d.m.Y') }} &ndash; {{ $to->format('d.m.Y') }}</div>

    <table class="sheet">
        <thead>
            <tr>
                <th rowspan="2" style="width:26px;">Day</th>
                <th rowspan="2" style="width:34px;">At</th>
                <th rowspan="2" style="width:90px; text-align:left;">Port</th>
                <th rowspan="2" style="width:34px;">Crew</th>
                <th rowspan="2" style="width:40px;">Pass.</th>
                <th colspan="3" class="group">Visitors — owner / crew</th>
                <th colspan="3" class="group">Visitors — charterer</th>
                <th rowspan="2" class="group" style="width:108px; text-align:left;">Additional persons</th>
                <th rowspan="2" class="group" style="width:46px;">Rations</th>
                <th rowspan="2" style="width:58px;">Amount</th>
            </tr>
            <tr>
                <th class="group" style="width:26px;">B</th>
                <th style="width:26px;">L</th>
                <th style="width:26px;">D</th>
                <th class="group" style="width:26px;">B</th>
                <th style="width:26px;">L</th>
                <th style="width:26px;">D</th>
            </tr>
        </thead>
        <tbody>
            @forelse($days as $day)
                <tr>
                    <td class="num">{{ $day['day'] }}</td>
                    <td style="text-align:center;">{{ $day['location'] ?: '—' }}</td>
                    <td>{{ $day['port'] ?: '' }}</td>
                    {{-- The heads on board, which is the crew's rations: a man
                         fed all three sittings is one ration. --}}
                    <td class="num">{{ $day['crew_on_board'] > 0 ? rtrim(rtrim(number_format($day['crew_on_board'], 2), '0'), '.') : '—' }}</td>
                    <td class="num">{{ $day['passenger_rations'] > 0 ? rtrim(rtrim(number_format($day['passenger_rations'], 2), '0'), '.') : '—' }}</td>
                    <td class="num group">{{ $day['breakfast_visitors'] ?: '' }}</td>
                    <td class="num">{{ $day['lunch_visitors'] ?: '' }}</td>
                    <td class="num">{{ $day['dinner_visitors'] ?: '' }}</td>
                    <td class="num group">{{ $day['breakfast_charterer'] ?: '' }}</td>
                    <td class="num">{{ $day['lunch_charterer'] ?: '' }}</td>
                    <td class="num">{{ $day['dinner_charterer'] ?: '' }}</td>
                    <td class="group">{{ $day['additional_persons'] ?: '' }}</td>
                    <td class="num group"><strong>{{ number_format($day['total_rations'], 2) }}</strong></td>
                    <td class="num">{{ number_format($day['amount'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="14" style="padding:18px; text-align:center;" class="muted">
                        No days were recorded in this period.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if(count($days) > 0)
            <tfoot>
                <tr>
                    <td colspan="3" class="navy">TOTAL &nbsp;·&nbsp; {{ $totals['days_recorded'] }} days fed</td>
                    <td class="num navy">{{ number_format($totals['crew_rations'], 2) }}</td>
                    <td class="num navy">{{ number_format($totals['passenger_rations'], 2) }}</td>
                    <td colspan="3" class="num navy group">{{ $totals['visitor_meals'] }} meals</td>
                    <td colspan="3" class="num navy group">{{ $totals['charterer_meals'] }} meals</td>
                    <td class="group"></td>
                    <td class="num navy group">{{ number_format($totals['total_rations'], 2) }}</td>
                    <td class="num navy">{{ number_format($totals['amount'], 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <p class="sub" style="margin-top:10px; line-height:1.6;">
        <strong>Crew</strong> and <strong>Pass.</strong> are rations: the meals taken that day divided by three, so a man
        who missed a sitting counts as two thirds rather than a whole. Visitor columns are meals as served. B / L / D are
        breakfast, lunch and dinner.
    </p>

    @include('pdf.partials.footer')

</body>
</html>
