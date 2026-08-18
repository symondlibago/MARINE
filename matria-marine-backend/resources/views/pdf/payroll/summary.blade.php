<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 30px 34px 66px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        .navy { color: #28364b; }
        .doc-title { font-size: 20px; font-weight: bold; letter-spacing: 1.6px; color: #28364b; }
        .rule { height: 3px; background: #28364b; margin-top: 12px; }
        .rule-gold { height: 2px; background: #cebd88; margin-bottom: 12px; }
        table { border-collapse: collapse; }
        table.items { width: 100%; }
        table.items thead th { background: #28364b; color: #fff; padding: 5px 6px; font-size: 8.5px;
                               text-transform: uppercase; letter-spacing: 0.4px; }
        table.items thead th.num { text-align: right; }
        table.items tbody td { padding: 5px 6px; font-size: 9.5px; border-bottom: 1px solid #eef0f3; }
        table.items tbody tr:nth-child(even) td { background: #fafbfc; }
        .num { text-align: right; }
        .muted { color: #9ca3af; }
        .grp { background: #eef1f5 !important; color: #28364b; font-size: 8px; text-transform: uppercase;
               letter-spacing: 0.6px; text-align: center; padding: 3px 6px; font-weight: bold; }
        .totrow td { padding: 7px 6px; font-size: 10.5px; font-weight: bold;
                     border-top: 2px solid #28364b; border-bottom: none; color: #28364b; }
        .cards { width: 100%; margin-top: 16px; }
        .cards td { width: 25%; padding: 0 5px; vertical-align: top; }
        .card { border: 1px solid #e5e7eb; border-top: 3px solid #28364b; padding: 8px 10px; }
        .card .k { font-size: 8px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; }
        .card .v { font-size: 15px; font-weight: bold; color: #28364b; margin-top: 3px; }
        .card.gold { border-top-color: #cebd88; }
        .flags { margin-top: 14px; border-left: 3px solid #cebd88; background: #fdfbf5;
                 padding: 8px 11px; font-size: 9px; color: #6b5b32; line-height: 1.6; }
        .page-footer { position: fixed; bottom: -48px; left: 0; right: 0; text-align: center;
                       font-size: 8px; color: #8a94a3; }
    </style>
</head>
<body>

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $currency = $run->currency;
    $flagged = $run->lines->filter(fn ($l) => ! empty($l->review_flags));
@endphp

<div class="page-footer">
    {{ $company->company_name }} &nbsp;&bull;&nbsp; Payroll summary for {{ $run->period->format('F Y') }}
    &nbsp;&bull;&nbsp; All figures in {{ $currency }}
</div>

<table style="width:100%;">
    <tr>
        <td style="width:60%; vertical-align:top;">
            @if($logo)<img src="{{ $logo }}" style="height:38px; margin-bottom:5px;">@endif
            <div style="font-size:13px; font-weight:bold;" class="navy">{{ $company->company_name }}</div>
            <div style="font-size:9px; color:#6b7280; margin-top:2px;">
                @if($company->uen)UEN: {{ $company->uen }}@endif
            </div>
        </td>
        <td style="width:40%; text-align:right; vertical-align:top;">
            <div class="doc-title">PAYROLL SUMMARY</div>
            <div style="font-size:10px; color:#6b7280; margin-top:3px;">
                {{ $run->period->format('F Y') }}
                @if($run->payment_date) &nbsp;&bull;&nbsp; Paid {{ $run->payment_date->format('d M Y') }} @endif
            </div>
            <div style="font-size:9px; color:{{ $run->status === 'finalised' ? '#166534' : '#b45309' }}; margin-top:3px; font-weight:bold; text-transform:uppercase;">
                {{ $run->status === 'finalised' ? 'Finalised' : 'Draft — not yet finalised' }}
            </div>
        </td>
    </tr>
</table>

<div class="rule"></div>
<div class="rule-gold"></div>

<table class="items">
    <thead>
        <tr>
            <th colspan="3"></th>
            <th class="grp" colspan="3">Earnings</th>
            <th class="grp" colspan="4">Employee Deductions</th>
            <th class="grp"></th>
            <th class="grp" colspan="3">Employer Cost</th>
        </tr>
        <tr>
            <th style="width:6%;">ID</th>
            <th style="width:17%;">Employee</th>
            <th style="width:8%;">CPF Scheme</th>
            <th class="num" style="width:7%;">Basic</th>
            <th class="num" style="width:7%;">Allow / OT</th>
            <th class="num" style="width:8%;">Gross</th>
            <th class="num" style="width:7%;">CPF</th>
            <th class="num" style="width:5%;">SHG</th>
            <th class="num" style="width:6%;">Other</th>
            <th class="num" style="width:7%;">Total</th>
            <th class="num" style="width:8%;">Net Pay</th>
            <th class="num" style="width:7%;">Emp CPF</th>
            <th class="num" style="width:4%;">SDL</th>
            <th class="num" style="width:8%;">Total Cost</th>
        </tr>
    </thead>
    <tbody>
        @foreach($run->lines as $line)
            <tr>
                <td>{{ $line->code }}</td>
                <td>{{ $line->full_name }}</td>
                <td class="muted" style="font-size:8px;">{{ $line->cpf_scheme }}</td>
                <td class="num">{{ $money($line->basic_pay) }}</td>
                <td class="num">{{ $money($line->fixed_allowance + $line->other_allowance + $line->ot_pay + $line->bonus_aw) }}</td>
                <td class="num"><strong>{{ $money($line->gross_earnings) }}</strong></td>
                <td class="num">{{ $money($line->employee_cpf) }}</td>
                <td class="num">{{ $money($line->shg_deduction) }}</td>
                <td class="num">{{ $money($line->no_pay_leave + $line->other_deduction) }}</td>
                <td class="num">{{ $money($line->total_deductions) }}</td>
                <td class="num"><strong>{{ $money($line->net_salary) }}</strong></td>
                <td class="num">{{ $money($line->employer_cpf) }}</td>
                <td class="num">{{ $money($line->sdl) }}</td>
                <td class="num">{{ $money($line->total_employer_cost) }}</td>
            </tr>
        @endforeach
        <tr class="totrow">
            <td colspan="3">TOTAL &mdash; {{ $totals['headcount'] }} employee(s)</td>
            <td class="num">{{ $money($run->lines->sum('basic_pay')) }}</td>
            <td class="num">{{ $money($run->lines->sum(fn ($l) => $l->fixed_allowance + $l->other_allowance + $l->ot_pay + $l->bonus_aw)) }}</td>
            <td class="num">{{ $money($totals['gross_earnings']) }}</td>
            <td class="num">{{ $money($totals['employee_cpf']) }}</td>
            <td class="num">{{ $money($totals['shg_deduction']) }}</td>
            <td class="num">{{ $money($run->lines->sum(fn ($l) => $l->no_pay_leave + $l->other_deduction)) }}</td>
            <td class="num">{{ $money($totals['total_deductions']) }}</td>
            <td class="num">{{ $money($totals['net_salary']) }}</td>
            <td class="num">{{ $money($totals['employer_cpf']) }}</td>
            <td class="num">{{ $money($totals['sdl']) }}</td>
            <td class="num">{{ $money($totals['total_employer_cost']) }}</td>
        </tr>
    </tbody>
</table>

<table class="cards">
    <tr>
        <td><div class="card"><div class="k">Total Net Pay</div><div class="v">{{ $currency }} {{ $money($totals['net_salary']) }}</div></div></td>
        <td><div class="card"><div class="k">Total CPF Payable</div><div class="v">{{ $currency }} {{ $money($totals['total_cpf']) }}</div></div></td>
        <td><div class="card gold"><div class="k">SDL Payable</div><div class="v">{{ $currency }} {{ $money($totals['sdl']) }}</div></div></td>
        <td><div class="card gold"><div class="k">Total Employer Cost</div><div class="v">{{ $currency }} {{ $money($totals['total_employer_cost']) }}</div></div></td>
    </tr>
</table>

@if($flagged->isNotEmpty())
    <div class="flags">
        <strong>Worth a look before submitting:</strong><br>
        @foreach($flagged as $line)
            @foreach($line->review_flags as $flag)
                &bull; {{ $line->code }} {{ $line->full_name }} &mdash; {{ $flag }}<br>
            @endforeach
        @endforeach
    </div>
@endif

@if($run->notes)
    <div style="margin-top:12px; font-size:9px; color:#6b7280;"><strong>Notes:</strong> {{ $run->notes }}</div>
@endif

</body>
</html>
