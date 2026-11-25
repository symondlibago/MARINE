<?php

namespace App\Support\Inventory;

use App\Models\Inventory\CrewRation;

/**
 * Everything the crew ration sheet works out from the counts.
 *
 * The vessel records who was fed at each sitting. Everything else — meals,
 * ration equivalents, and what the owner is billed — is arithmetic on those,
 * and none of it is stored. A stored total is a total that can end up
 * disagreeing with the counts underneath it; change a count or a rate and
 * every figure here follows on the next read.
 *
 * The one thing worth understanding is that the owner is billed on two bases
 * at once:
 *
 *   Crew and passengers  by the RATION. Three meals feed one man for one day,
 *                        and that day costs the agreed rate whether he took
 *                        two meals or three.
 *   Visitors             by the MEAL, at a lower rate — a guest at lunch is
 *                        not a man fed for a day. The charterer's people are
 *                        often on a rate of their own again.
 *
 * So "total rations" and "total amount" are not the same shape of number, and
 * dividing everything by three would overcharge every visitor on the sheet.
 */
class RationSheet
{
    /** Breakfast, lunch and dinner feed one man for one day. */
    public const MEALS_PER_RATION = 3;

    /**
     * The rates in force, defaulted so a vessel with none set still totals.
     *
     * @return array{ration: float, visitor: float, charterer: float}
     */
    public static function rates($vessel): array
    {
        return [
            'ration' => (float) ($vessel->ration_rate ?? 0),
            'visitor' => (float) ($vessel->visitor_meal_rate ?? 0),
            'charterer' => (float) ($vessel->charterer_meal_rate ?? 0),
        ];
    }

    /**
     * One day of the sheet: meals and rations per category, and what the day
     * costs.
     *
     * @param  array<string, mixed>  $counts  the twelve count fields
     * @param  array{ration: float, visitor: float, charterer: float}  $rates
     */
    public static function day(array $counts, array $rates): array
    {
        $out = [];
        $meals = 0;
        $rations = 0.0;
        $amount = 0.0;

        foreach (CrewRation::CATEGORIES as $category => $fields) {
            $categoryMeals = self::sum($counts, $fields);

            // Each category is rounded on its own so the row adds up for
            // whoever reads it: the parts shown are the parts summed.
            $categoryRations = round($categoryMeals / self::MEALS_PER_RATION, 2);

            $out[$category.'_meals'] = $categoryMeals;
            $out[$category.'_rations'] = $categoryRations;

            $meals += $categoryMeals;
            $rations += $categoryRations;
            $amount += self::charge($category, $categoryMeals, $categoryRations, $rates);
        }

        $out['total_meals'] = $meals;
        $out['total_rations'] = round($rations, 2);
        $out['amount'] = round($amount, 2);

        // The heads on board, as the owner reads the column: a full day's
        // eating for one man. Not a separate measurement — crew rations under
        // another name, which is what the paper sheet calls "Crew".
        $out['crew_on_board'] = $out['crew_rations'];

        return $out;
    }

    /**
     * What one category costs for a day.
     *
     * Rations for the people the vessel carries, meals for the people visiting
     * it — the whole reason there is more than one rate.
     */
    private static function charge(string $category, int $meals, float $rations, array $rates): float
    {
        if (in_array($category, CrewRation::BY_RATION, true)) {
            return $rations * $rates['ration'];
        }

        return $meals * ($category === 'charterer' ? $rates['charterer'] : $rates['visitor']);
    }

    /**
     * The foot of the sheet, and the body of the invoice.
     *
     * Columns are summed from the daily figures rather than recomputed from
     * the raw meals, so the total under a column is the sum of the column.
     *
     * @param  iterable<array>  $days  rows already through day()
     * @param  array{ration: float, visitor: float, charterer: float}  $rates
     */
    public static function total(iterable $days, array $rates): array
    {
        $totals = ['days_recorded' => 0, 'total_meals' => 0, 'total_rations' => 0.0, 'amount' => 0.0];

        foreach (array_keys(CrewRation::CATEGORIES) as $category) {
            $totals[$category.'_meals'] = 0;
            $totals[$category.'_rations'] = 0.0;
        }

        foreach ($days as $day) {
            // A day with nobody fed is a day not yet entered, not a day of
            // fasting — counting it would drag the average per day down.
            if (($day['total_meals'] ?? 0) > 0) {
                $totals['days_recorded']++;
            }

            foreach (array_keys($totals) as $field) {
                if ($field !== 'days_recorded') {
                    $totals[$field] += $day[$field] ?? 0;
                }
            }
        }

        foreach ($totals as $field => $value) {
            if (is_float($value)) {
                $totals[$field] = round($value, 2);
            }
        }

        $totals['crew_on_board'] = $totals['crew_rations'];
        $totals['average_rations_per_day'] = $totals['days_recorded'] > 0
            ? round($totals['total_rations'] / $totals['days_recorded'], 2)
            : 0.0;

        return $totals + ['rates' => $rates] + ['charges' => self::charges($totals, $rates)];
    }

    /**
     * The invoice lines: what is being billed, on what basis, at what rate.
     *
     * One line per category whether or not anybody was fed under it, because
     * a zero against "Charterer" is information — it says nobody from the
     * charterer ate, rather than leaving the owner to wonder.
     *
     * @return array<int, array>
     */
    public static function charges(array $totals, array $rates): array
    {
        $labels = [
            'crew' => 'Crew',
            'passenger' => 'Passengers',
            'visitor' => 'Visitors — owner / crew',
            'charterer' => 'Visitors — charterer',
        ];

        $lines = [];
        foreach (CrewRation::CATEGORIES as $category => $_) {
            $byRation = in_array($category, CrewRation::BY_RATION, true);

            $quantity = $byRation ? ($totals[$category.'_rations'] ?? 0) : ($totals[$category.'_meals'] ?? 0);
            $rate = $byRation ? $rates['ration'] : ($category === 'charterer' ? $rates['charterer'] : $rates['visitor']);

            $lines[] = [
                'category' => $category,
                'label' => $labels[$category],
                'unit' => $byRation ? 'Rations' : 'Meals',
                'quantity' => round((float) $quantity, 2),
                'rate' => round($rate, 4),
                'amount' => round((float) $quantity * $rate, 2),
            ];
        }

        return $lines;
    }

    /** @param string[] $fields */
    private static function sum(array $counts, array $fields): int
    {
        $total = 0;
        foreach ($fields as $field) {
            $total += max(0, (int) ($counts[$field] ?? 0));
        }

        return $total;
    }
}
