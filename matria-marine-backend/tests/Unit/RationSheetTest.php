<?php

namespace Tests\Unit;

use App\Support\Inventory\RationSheet;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic the crew ration sheet is built on.
 *
 * The figures are taken from a real catering invoice for a sister vessel, so a
 * failure here means the system disagrees with the paper the owner already
 * accepts. The whole of that invoice reduces to one idea: crew are billed by
 * the ration and visitors by the meal, and the two cost different money.
 */
class RationSheetTest extends TestCase
{
    /** The rates on that invoice. */
    private const RATES = ['ration' => 9.30, 'visitor' => 3.10, 'charterer' => 0.0];

    /** A day with a full crew and three technicians aboard for all three meals. */
    private const A_DAY = [
        'breakfast_crew' => 25, 'lunch_crew' => 25, 'dinner_crew' => 25,
        'breakfast_visitors' => 3, 'lunch_visitors' => 3, 'dinner_visitors' => 3,
    ];

    public function test_a_day_matches_the_vessels_own_sheet(): void
    {
        $day = RationSheet::day(self::A_DAY, self::RATES);

        $this->assertSame(75, $day['crew_meals']);
        $this->assertSame(25.0, $day['crew_rations'], '25 men fed three times is 25 rations');
        $this->assertSame(9, $day['visitor_meals']);
        $this->assertSame(3.0, $day['visitor_rations']);
        $this->assertSame(28.0, $day['total_rations'], 'the sheet reads 28,00 for this day');
    }

    /**
     * A man who missed a sitting is two thirds of a ration.
     *
     * The paper sheet cannot show this — it records a headcount and assumes
     * three meals. Day 25 of the invoice reads 24,67 only because two visitor
     * meals were served; our finer count gets there for the crew too.
     */
    public function test_a_missed_sitting_is_a_part_ration(): void
    {
        $day = RationSheet::day([
            'breakfast_crew' => 24, 'lunch_crew' => 24, 'dinner_crew' => 23,
        ], self::RATES);

        $this->assertSame(71, $day['crew_meals']);
        $this->assertSame(23.67, $day['crew_rations']);
    }

    /**
     * Crew by the ration, visitors by the meal.
     *
     * The thing the old single-rate model got wrong: it charged a guest at
     * lunch as a third of a full man-day, when the agreement prices a visitor
     * meal separately and lower.
     */
    public function test_crew_are_charged_per_ration_and_visitors_per_meal(): void
    {
        $day = RationSheet::day(self::A_DAY, self::RATES);

        // 25 rations x 9.30 = 232.50, plus 9 meals x 3.10 = 27.90.
        $this->assertSame(260.40, $day['amount']);
    }

    /** The charterer's people are on their own rate. */
    public function test_the_charterer_is_billed_on_its_own_rate(): void
    {
        $rates = ['ration' => 9.30, 'visitor' => 3.10, 'charterer' => 5.00];

        $day = RationSheet::day([
            'breakfast_visitors' => 2,
            'breakfast_charterer' => 2,
        ], $rates);

        // 2 x 3.10 + 2 x 5.00
        $this->assertSame(16.20, $day['amount']);
    }

    /** Passengers travel with the vessel, so they are rations, not meals. */
    public function test_passengers_are_billed_as_rations(): void
    {
        $day = RationSheet::day([
            'breakfast_passenger' => 2, 'lunch_passenger' => 2, 'dinner_passenger' => 2,
        ], self::RATES);

        $this->assertSame(2.0, $day['passenger_rations']);
        $this->assertSame(18.60, $day['amount'], '2 rations x 9.30');
    }

    /** A day nobody was fed costs nothing rather than erroring on a zero. */
    public function test_an_empty_day_is_all_zeroes(): void
    {
        $day = RationSheet::day([], self::RATES);

        $this->assertSame(0, $day['total_meals']);
        $this->assertSame(0.0, $day['total_rations']);
        $this->assertSame(0.0, $day['amount']);
    }

    /** Nothing can be fed to a negative number of people. */
    public function test_negative_counts_are_ignored(): void
    {
        $day = RationSheet::day(['breakfast_crew' => -5, 'lunch_crew' => 3], self::RATES);

        $this->assertSame(3, $day['crew_meals']);
    }

    /**
     * The whole invoice, reproduced.
     *
     * 25 days: 590 crew rations and 35 visitor meals, billed at 9.30 and 3.10.
     * The paper total is 5.595,50 USD and 601,67 rations.
     */
    public function test_a_period_reproduces_the_invoice(): void
    {
        $days = [];

        // 1.770 crew meals over 25 days is 590 rations.
        for ($i = 0; $i < 25; $i++) {
            $counts = ['breakfast_crew' => 24, 'lunch_crew' => 24, 'dinner_crew' => 24];

            // 35 visitor meals, spread so the arithmetic is not a single sum.
            if ($i === 0) {
                $counts += ['breakfast_visitors' => 8, 'lunch_visitors' => 15, 'dinner_visitors' => 12];
            }

            $days[] = RationSheet::day($counts, self::RATES);
        }

        $totals = RationSheet::total($days, self::RATES);

        $this->assertSame(25, $totals['days_recorded']);
        $this->assertSame(600.0, $totals['crew_rations'], '24 men for 25 days');
        $this->assertSame(35, $totals['visitor_meals']);
        $this->assertSame(611.67, $totals['total_rations']);

        // 600 x 9.30 = 5,580.00, plus 35 x 3.10 = 108.50.
        $this->assertSame(5688.50, $totals['amount']);
    }

    /**
     * A day nobody has filled in is not a day of fasting.
     *
     * Counting empty days would drag the average down and make a half-entered
     * month look like the crew was being starved.
     */
    public function test_days_nobody_entered_do_not_count_against_the_average(): void
    {
        $days = [
            RationSheet::day(self::A_DAY, self::RATES),
            RationSheet::day([], self::RATES),
            RationSheet::day([], self::RATES),
        ];

        $totals = RationSheet::total($days, self::RATES);

        $this->assertSame(1, $totals['days_recorded']);
        $this->assertSame(28.0, $totals['average_rations_per_day']);
    }

    /** No rate set means no charge — not a crash, and not a wrong number. */
    public function test_without_rates_nothing_is_charged(): void
    {
        $day = RationSheet::day(self::A_DAY, ['ration' => 0.0, 'visitor' => 0.0, 'charterer' => 0.0]);

        $this->assertSame(28.0, $day['total_rations']);
        $this->assertSame(0.0, $day['amount']);
    }

    /**
     * The invoice carries a line per category, fed or not.
     *
     * A zero against the charterer is information: it says nobody from the
     * charterer ate, rather than leaving the owner to wonder whether it was
     * counted at all.
     */
    public function test_every_category_gets_an_invoice_line(): void
    {
        $totals = RationSheet::total([RationSheet::day(self::A_DAY, self::RATES)], self::RATES);

        $this->assertCount(4, $totals['charges']);

        $byCategory = collect($totals['charges'])->keyBy('category');

        $this->assertSame('Rations', $byCategory['crew']['unit']);
        $this->assertSame(25.0, $byCategory['crew']['quantity']);
        $this->assertSame(232.50, $byCategory['crew']['amount']);

        $this->assertSame('Meals', $byCategory['visitor']['unit']);
        $this->assertSame(9.0, $byCategory['visitor']['quantity']);
        $this->assertSame(27.90, $byCategory['visitor']['amount']);

        $this->assertSame(0.0, $byCategory['charterer']['quantity'], 'a zero line is still a line');
    }
}
