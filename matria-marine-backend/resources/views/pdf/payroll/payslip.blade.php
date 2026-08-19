<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 34px 40px 76px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .navy { color: #28364b; }
        .doc-title { font-size: 24px; font-weight: bold; letter-spacing: 2px; color: #28364b; }
        .rule { height: 3px; background: #28364b; margin: 14px 0 0 0; }
        .rule-gold { height: 2px; background: #cebd88; margin-bottom: 16px; }
        table { border-collapse: collapse; }
        .meta { width: 100%; margin-bottom: 4px; }
        .meta td { padding: 4px 0; font-size: 10.5px; vertical-align: top; }
        .meta .k { color: #6b7280; text-transform: uppercase; font-size: 8.5px; letter-spacing: 0.6px; }
        .meta .v { font-weight: bold; color: #28364b; font-size: 11.5px; }
        .bar { background: #28364b; color: #fff; padding: 5px 9px; font-size: 9.5px;
               font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .lines { width: 100%; }
        .lines td { padding: 5px 9px; font-size: 11px; border-bottom: 1px solid #eef0f3; }
        .lines td.num { text-align: right; }
        .lines tr.sub td { border-top: 1.5px solid #28364b; border-bottom: none;
                           font-weight: bold; color: #28364b; padding-top: 6px; }
        .lines tr.zero td { color: #9ca3af; }
        .net { width: 100%; margin-top: 16px; background: #28364b; color: #fff; }
        .net td { padding: 11px 14px; }
        .net .label { font-size: 11px; letter-spacing: 1.6px; text-transform: uppercase; }
        .net .amount { text-align: right; font-size: 21px; font-weight: bold; }
        .info { width: 100%; margin-top: 16px; border: 1px solid #e5e7eb; }
        .info th { background: #f7f8fa; color: #6b7280; font-size: 8.5px; text-transform: uppercase;
                   letter-spacing: 0.6px; padding: 5px 9px; text-align: left; font-weight: bold; }
        .info td { padding: 5px 9px; font-size: 10.5px; border-top: 1px solid #eef0f3; }
        .info td.num { text-align: right; }
        .note { margin-top: 12px; border-left: 3px solid #cebd88; background: #fdfbf5;
                padding: 7px 10px; font-size: 9.5px; color: #6b5b32; line-height: 1.55; }
        .ytd { width: 100%; margin-top: 16px; border: 1px solid #e5e7eb; }
        .ytd th { background: #28364b; color: #fff; font-size: 8.5px; text-transform: uppercase;
                  letter-spacing: 0.6px; padding: 5px 9px; text-align: left; font-weight: bold; }
        .ytd th .sub { font-weight: normal; text-transform: none; letter-spacing: 0; color: #cbd3df; }
        .ytd td { width: 33.33%; border-top: 1px solid #eef0f3; text-align: center; }
        .ytd .lab td { color: #6b7280; font-size: 8.5px; text-transform: uppercase;
                       letter-spacing: 0.6px; padding: 6px 9px 2px 9px; }
        .ytd .val td { color: #28364b; font-size: 13.5px; font-weight: bold; padding: 0 9px 7px 9px; }
        .ytd td + td { border-left: 1px solid #eef0f3; }
        .page-footer { position: fixed; bottom: -58px; left: 0; right: 0; text-align: center;
                       font-size: 8.5px; color: #8a94a3; line-height: 1.6; }
    </style>
</head>
<body>

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $currency = $run->currency;

    $earnings = [
        ['Basic Pay', $line->basic_pay],
        ['Fixed Allowance', $line->fixed_allowance],
        ['Other Allowance', $line->other_allowance],
        ['Overtime Pay', $line->ot_pay],
        ['Bonus / Commission', $line->bonus_aw],
    ];

    $deductions = [
        ['Employee CPF', $line->employee_cpf],
        [$line->shg_fund === 'None' ? 'Self-Help Group Fund' : $line->shg_fund.' Contribution', $line->shg_deduction],
        ['No-Pay Leave / Absence', $line->no_pay_leave],
        ['Other Deductions', $line->other_deduction],
    ];
@endphp

<div class="page-footer">
    {{ $company->payslip_footer ?: 'This is a system-generated payslip. No signature is required.' }}<br>
    Prepared using the Matria Marine Payroll System &nbsp;&bull;&nbsp; Currency: {{ $currency }}
</div>

{{-- Header --}}
<table style="width:100%;">
    <tr>
        <td style="width:58%; vertical-align:top;">
            @if($logo)<img src="{{ $logo }}" style="height:44px; margin-bottom:6px;">@endif
            <div style="font-size:14px; font-weight:bold;" class="navy">{{ $company->company_name }}</div>
            <div style="font-size:9.5px; color:#6b7280; margin-top:3px; line-height:1.55;">
                @if($company->uen)UEN: {{ $company->uen }}<br>@endif
                @if($company->company_address){!! nl2br(e($company->company_address)) !!}@endif
            </div>
        </td>
        <td style="width:42%; text-align:right; vertical-align:top;">
            <div class="doc-title">PAYSLIP</div>
            <div style="font-size:11px; color:#6b7280; margin-top:4px;">{{ $run->period->format('F Y') }}</div>
        </td>
    </tr>
</table>

<div class="rule"></div>
<div class="rule-gold"></div>

{{-- Who and when --}}
<table class="meta">
    <tr>
        <td style="width:34%;"><div class="k">Employee Name</div><div class="v">{{ $line->full_name }}</div></td>
        <td style="width:22%;"><div class="k">Employee ID</div><div class="v">{{ $line->code }}</div></td>
        <td style="width:22%;"><div class="k">NRIC / FIN</div><div class="v">{{ $line->nric ?: '—' }}</div></td>
        <td style="width:22%;"><div class="k">Pay Period</div><div class="v">{{ $run->period->format('M Y') }}</div></td>
    </tr>
    <tr>
        <td><div class="k">Payment Date</div><div class="v">{{ $run->payment_date?->format('d M Y') ?: '—' }}</div></td>
        <td><div class="k">Payment Method</div><div class="v">{{ $line->payment_method ?: '—' }}</div></td>
        <td><div class="k">Bank / Pay Ref</div><div class="v">{{ $line->bank_ref ?: '—' }}</div></td>
        <td><div class="k">CPF Scheme</div><div class="v" style="font-size:10px;">{{ $line->cpf_scheme }}</div></td>
    </tr>
</table>

{{-- Earnings and deductions, side by side --}}
<table style="width:100%; margin-top:14px;">
    <tr>
        <td style="width:49%; vertical-align:top;">
            <div class="bar">Earnings</div>
            <table class="lines">
                @foreach($earnings as [$label, $amount])
                    <tr class="{{ (float) $amount == 0.0 ? 'zero' : '' }}">
                        <td>{{ $label }}</td>
                        <td class="num">{{ $money($amount) }}</td>
                    </tr>
                @endforeach
                <tr class="sub">
                    <td>Gross Earnings</td>
                    <td class="num">{{ $money($line->gross_earnings) }}</td>
                </tr>
            </table>
        </td>
        <td style="width:2%;"></td>
        <td style="width:49%; vertical-align:top;">
            <div class="bar">Deductions</div>
            <table class="lines">
                @foreach($deductions as [$label, $amount])
                    <tr class="{{ (float) $amount == 0.0 ? 'zero' : '' }}">
                        <td>{{ $label }}</td>
                        <td class="num">{{ $money($amount) }}</td>
                    </tr>
                @endforeach
                <tr class="sub">
                    <td>Total Deductions</td>
                    <td class="num">{{ $money($line->total_deductions) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- The number the employee is looking for --}}
<table class="net">
    <tr>
        <td class="label">Net Salary</td>
        <td class="amount">{{ $currency }} {{ $money($line->net_salary) }}</td>
    </tr>
</table>

{{-- Employer side: shown so the figure is transparent, not deducted from pay --}}
<table class="info">
    <tr>
        <th colspan="4">Employer Contributions &mdash; for information only, not deducted from your pay</th>
    </tr>
    <tr>
        <td style="width:34%;">Employer CPF</td>
        <td class="num" style="width:16%;">{{ $money($line->employer_cpf) }}</td>
        <td style="width:34%;">Total CPF (Employer + Employee)</td>
        <td class="num" style="width:16%;">{{ $money($line->total_cpf) }}</td>
    </tr>
    <tr>
        <td>CPF Wages This Month</td>
        <td class="num">{{ $money($line->cpf_wages) }}</td>
        <td>CPF Age Band</td>
        <td class="num">{{ $ageBand }}</td>
    </tr>
</table>

{{-- Year to date: totals paid so far this calendar year, for tax declaration --}}
<table class="ytd">
    <tr>
        <th colspan="3">
            Year to Date &mdash; {{ $run->period->format('Y') }}
            <span class="sub">&nbsp; totals paid Jan&ndash;{{ $run->period->format('M') }}, for tax declaration</span>
        </th>
    </tr>
    <tr class="lab">
        <td>YTD Gross Earnings</td>
        <td>YTD Employee CPF</td>
        <td>YTD Employer CPF</td>
    </tr>
    <tr class="val">
        <td>{{ $currency }} {{ $money($ytd['gross']) }}</td>
        <td>{{ $currency }} {{ $money($ytd['employee_cpf']) }}</td>
        <td>{{ $currency }} {{ $money($ytd['employer_cpf']) }}</td>
    </tr>
</table>

@if($line->remarks)
    <div class="note"><strong>Remarks:</strong> {{ $line->remarks }}</div>
@endif

</body>
</html>