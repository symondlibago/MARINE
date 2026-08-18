<?php

namespace App\Support\Payroll;

use Carbon\CarbonInterface;

/**
 * The 2026 Singapore statutory figures, in one place.
 *
 * Everything here is a published rate, not a business decision, so it lives as
 * a constant rather than an editable setting — there is nothing for the office
 * to tune and nothing that can be got wrong by a mis-click. CPF rates change on
 * 1 Jan 2027; when they do, only this file needs revisiting.
 *
 * Taken from the client's own Matria_Marine_Payroll workbook (Rates sheet).
 */
class CpfRates
{
    /** The year these figures are published for. */
    public const YEAR = 2026;

    /** Ordinary Wage ceiling — CPF is not payable on OW above this a month. */
    public const OW_CEILING = 8000.0;

    /** Annual salary ceiling — the total wages CPF is payable on in a year. */
    public const ANNUAL_CEILING = 102000.0;

    /** Skills Development Levy: employer cost, never an employee deduction. */
    public const SDL_RATE = 0.0025;
    public const SDL_MIN = 2.0;
    public const SDL_MAX = 11.25;

    /**
     * The three wage bands of the CPF contribution formula.
     * At or below NIL nothing is payable; between NIL and GRADUATED_FROM the
     * employer pays alone; between GRADUATED_FROM and FULL_FROM the employee's
     * share phases in; from FULL_FROM the full rates apply.
     */
    public const NIL_UP_TO = 50.0;
    public const GRADUATED_FROM = 500.0;
    public const FULL_FROM = 750.0;

    /**
     * Contribution rates by scheme and age band.
     *
     * Each row is [employer rate in the graduated band, employee phase-in
     * factor, full total rate, full employee rate].
     */
    public const SCHEMES = [
        'SC / SPR 3+ (Full)' => [
            1 => [0.17, 0.60, 0.37, 0.20],
            2 => [0.16, 0.54, 0.34, 0.18],
            3 => [0.125, 0.375, 0.25, 0.125],
            4 => [0.09, 0.225, 0.165, 0.075],
            5 => [0.075, 0.15, 0.125, 0.05],
        ],
        'SPR 1st Year (G/G)' => [
            1 => [0.04, 0.15, 0.09, 0.05],
            2 => [0.04, 0.15, 0.09, 0.05],
            3 => [0.035, 0.15, 0.085, 0.05],
            4 => [0.035, 0.15, 0.085, 0.05],
            5 => [0.035, 0.15, 0.085, 0.05],
        ],
        'SPR 2nd Year (G/G)' => [
            1 => [0.09, 0.45, 0.24, 0.15],
            2 => [0.06, 0.375, 0.185, 0.125],
            3 => [0.035, 0.225, 0.11, 0.075],
            4 => [0.035, 0.15, 0.085, 0.05],
            5 => [0.035, 0.15, 0.085, 0.05],
        ],
        'SPR 1st Year (F/G)' => [
            1 => [0.17, 0.15, 0.22, 0.05],
            2 => [0.16, 0.15, 0.21, 0.05],
            3 => [0.125, 0.15, 0.175, 0.05],
            4 => [0.09, 0.15, 0.14, 0.05],
            5 => [0.075, 0.15, 0.125, 0.05],
        ],
        'SPR 2nd Year (F/G)' => [
            1 => [0.17, 0.45, 0.32, 0.15],
            2 => [0.16, 0.375, 0.285, 0.125],
            3 => [0.125, 0.225, 0.20, 0.075],
            4 => [0.09, 0.15, 0.14, 0.05],
            5 => [0.075, 0.15, 0.125, 0.05],
        ],
        // Work-pass holders and anyone else outside the scheme.
        'No CPF' => [
            1 => [0.0, 0.0, 0.0, 0.0],
            2 => [0.0, 0.0, 0.0, 0.0],
            3 => [0.0, 0.0, 0.0, 0.0],
            4 => [0.0, 0.0, 0.0, 0.0],
            5 => [0.0, 0.0, 0.0, 0.0],
        ],
    ];

    /** Self-help group contributions, by fund, as [wage from, monthly amount]. */
    public const SHG_BRACKETS = [
        'CDAC' => [[0, 0.50], [2000.01, 1.00], [3500.01, 1.50], [5000.01, 2.00], [7500.01, 3.00]],
        'ECF' => [[0, 2.00], [1000.01, 4.00], [1500.01, 6.00], [2500.01, 9.00], [4000.01, 12.00], [7000.01, 16.00], [10000.01, 20.00]],
        'MBMF' => [[0, 3.00], [1000.01, 4.50], [2000.01, 6.50], [3000.01, 15.00], [4000.01, 19.50], [6000.01, 22.00], [8000.01, 24.00], [10000.01, 26.00]],
        'SINDA' => [[0, 1.00], [1000.01, 3.00], [1500.01, 5.00], [2500.01, 7.00], [4500.01, 9.00], [7500.01, 12.00], [10000.01, 18.00], [15000.01, 30.00]],
        'None' => [],
    ];

    /** Age bands, in the order the CPF tables use them. */
    public const AGE_BANDS = [
        1 => '55 and below',
        2 => 'Above 55 to 60',
        3 => 'Above 60 to 65',
        4 => 'Above 65 to 70',
        5 => 'Above 70',
    ];

    public static function schemeNames(): array
    {
        return array_keys(self::SCHEMES);
    }

    public static function shgNames(): array
    {
        return array_keys(self::SHG_BRACKETS);
    }

    /**
     * The four rates for a scheme and age band, falling back to No CPF rather
     * than throwing: an unrecognised scheme must never stop a payroll run.
     */
    public static function ratesFor(string $scheme, int $band): array
    {
        $table = self::SCHEMES[$scheme] ?? self::SCHEMES['No CPF'];

        return $table[$band] ?? $table[1];
    }

    /**
     * The CPF age band for a payroll month.
     *
     * A new rate applies from the first day of the month AFTER the birthday, so
     * the age that matters is the one held on the first day of the pay month.
     */
    public static function ageBand(?CarbonInterface $dateOfBirth, CarbonInterface $periodStart): int
    {
        if (! $dateOfBirth) {
            return 1;
        }

        // floor(): Carbon 3 returns a fractional year count, Carbon 2 a whole one.
        $age = (int) floor(abs($dateOfBirth->diffInYears($periodStart->copy()->startOfMonth())));

        return match (true) {
            $age <= 55 => 1,
            $age <= 60 => 2,
            $age <= 65 => 3,
            $age <= 70 => 4,
            default => 5,
        };
    }

    /** The monthly self-help group contribution for a fund at these wages. */
    public static function shg(string $fund, float $wages): float
    {
        $amount = 0.0;

        foreach (self::SHG_BRACKETS[$fund] ?? [] as [$from, $value]) {
            if ($wages >= $from) {
                $amount = $value;
            }
        }

        return $wages > 0 ? $amount : 0.0;
    }

    /** SDL: 0.25% of the month's wages, floored at $2 and capped at $11.25. */
    public static function sdl(float $wages): float
    {
        if ($wages <= 0) {
            return 0.0;
        }

        return round(min(max($wages * self::SDL_RATE, self::SDL_MIN), self::SDL_MAX), 2);
    }
}
