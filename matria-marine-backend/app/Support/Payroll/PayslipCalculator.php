<?php

namespace App\Support\Payroll;

use Carbon\CarbonInterface;

/**
 * The single definition of one month's pay for one person.
 *
 * Pure: it reads nothing and writes nothing. Give it the typed inputs and it
 * returns every figure on the payslip. Both the payroll grid and the PDFs go
 * through here, so a number can never disagree with itself between screens.
 */
class PayslipCalculator
{
    /**
     * @param  array  $in  keys: cpf_scheme, shg_fund, date_of_birth, basic_pay,
     *                     fixed_allowance, other_allowance, ot_pay, bonus_aw,
     *                     aw_subject_override, no_pay_leave, other_deduction,
     *                     other_employer_cost, ytd_ow_subject, ytd_aw_subject
     */
    public static function compute(array $in, CarbonInterface $periodStart): array
    {
        $n = fn (string $k) => round((float) ($in[$k] ?? 0), 2);

        $basic = $n('basic_pay');
        $fixed = $n('fixed_allowance');
        $other = $n('other_allowance');
        $ot = $n('ot_pay');
        $bonus = $n('bonus_aw');
        $noPay = $n('no_pay_leave');
        $otherDed = $n('other_deduction');
        $otherCost = $n('other_employer_cost');

        // What the payslip prints as earnings. Unpaid leave is shown further
        // down as a deduction, the way the client's workbook lays it out.
        $gross = round($basic + $fixed + $other + $ot + $bonus, 2);

        // Ordinary Wages are the month's recurring pay. Days not worked are not
        // wages, so they come off before CPF is worked out.
        $ordinary = round(max($basic + $fixed + $other + $ot - $noPay, 0), 2);
        $owSubject = round(min($ordinary, CpfRates::OW_CEILING), 2);

        // Additional Wages — bonus and commission. CPF is payable on them only
        // up to what is left of the annual ceiling after the year's OW.
        $ytdOw = round((float) ($in['ytd_ow_subject'] ?? 0), 2);
        $ytdAw = round((float) ($in['ytd_aw_subject'] ?? 0), 2);
        $awRoom = round(max(CpfRates::ANNUAL_CEILING - $ytdOw - $owSubject - $ytdAw, 0), 2);

        $override = $in['aw_subject_override'] ?? null;
        $awSubject = $override !== null
            ? round(max((float) $override, 0), 2)
            : round(min($bonus, $awRoom), 2);

        $cpfWages = round($owSubject + $awSubject, 2);

        $dob = $in['date_of_birth'] ?? null;
        $band = CpfRates::ageBand($dob, $periodStart);
        $scheme = (string) ($in['cpf_scheme'] ?? 'No CPF');

        [$totalCpf, $employeeCpf, $employerCpf] = self::contributions($scheme, $band, $cpfWages);

        $fund = (string) ($in['shg_fund'] ?? 'None');
        $shg = CpfRates::shg($fund, $gross);

        $deductions = round($employeeCpf + $shg + $noPay + $otherDed, 2);
        $net = round($gross - $deductions, 2);

        $sdl = CpfRates::sdl($gross);
        $employerCost = round($gross + $employerCpf + $sdl + $otherCost, 2);

        return [
            'gross_earnings' => $gross,
            'ow_subject' => $owSubject,
            'aw_subject' => $awSubject,
            'cpf_wages' => $cpfWages,
            'age_band' => $band,
            'employee_cpf' => $employeeCpf,
            'employer_cpf' => $employerCpf,
            'total_cpf' => $totalCpf,
            'shg_deduction' => $shg,
            'total_deductions' => $deductions,
            'net_salary' => $net,
            'sdl' => $sdl,
            'total_employer_cost' => $employerCost,
            'review_flags' => self::flags($bonus, $awSubject, $awRoom, $override, $ordinary, $net, $dob, $scheme),
        ];
    }

    /**
     * The CPF contribution formula, as published.
     *
     * Below $50 nothing is payable. Up to $500 the employer pays alone. Between
     * $500 and $750 the employee's share phases in. From $750 the full rates
     * apply. The total is rounded to the nearest dollar and the employee's share
     * down to the nearest dollar; the employer takes the remainder.
     *
     * @return array{0: float, 1: float, 2: float} total, employee, employer
     */
    private static function contributions(string $scheme, int $band, float $wages): array
    {
        [$graduatedEmployer, $phaseIn, $fullTotal, $fullEmployee] = CpfRates::ratesFor($scheme, $band);

        if ($wages <= CpfRates::NIL_UP_TO || $fullTotal <= 0) {
            return [0.0, 0.0, 0.0];
        }

        if ($wages <= CpfRates::GRADUATED_FROM) {
            $total = $wages * $graduatedEmployer;
            $employee = 0.0;
        } elseif ($wages < CpfRates::FULL_FROM) {
            $employee = $phaseIn * ($wages - CpfRates::GRADUATED_FROM);
            $total = $wages * $graduatedEmployer + $employee;
        } else {
            $total = $wages * $fullTotal;
            $employee = $wages * $fullEmployee;
        }

        $total = round($total, 0, PHP_ROUND_HALF_UP);
        $employee = floor($employee);
        $employer = max($total - $employee, 0);

        return [(float) $total, (float) $employee, (float) $employer];
    }

    /**
     * Things a person should look at before finalising. Never blocking — the
     * office may well have a good reason; the run just says so out loud.
     */
    private static function flags(
        float $bonus,
        float $awSubject,
        float $awRoom,
        $override,
        float $ordinary,
        float $net,
        $dob,
        string $scheme
    ): array {
        $flags = [];

        if ($bonus > 0 && $override === null && $awSubject < $bonus) {
            $flags[] = 'Bonus is above the annual CPF ceiling — only '.number_format($awRoom, 2).' of it attracts CPF. Check against any bonus paid before this system was in use.';
        }

        if ($bonus > 0 && $override !== null) {
            $flags[] = 'The CPF-liable part of the bonus was set by hand.';
        }

        if ($ordinary > CpfRates::OW_CEILING) {
            $flags[] = 'Monthly wages are above the '.number_format(CpfRates::OW_CEILING, 0).' CPF ceiling, so CPF stops at the ceiling.';
        }

        if ($net < 0) {
            $flags[] = 'Deductions come to more than the earnings — net pay is negative.';
        }

        if (! $dob && $scheme !== 'No CPF') {
            $flags[] = 'No date of birth on file, so the 55-and-below CPF rate was used.';
        }

        return $flags;
    }
}
