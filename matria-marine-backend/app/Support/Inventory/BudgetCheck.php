<?php

namespace App\Support\Inventory;

use App\Models\Inventory\Vessel;

/**
 * Whether an order fits the budget, and what to say when it does not.
 *
 * A warning, never a refusal. The office sets a vessel a monthly figure, and
 * there are good reasons to go past it — a long leg, a crew change, a yard
 * period, a price that moved. What is not acceptable is finding out afterwards.
 *
 * The case worth getting right is the one the owner raised: a ship on a $5,000
 * month ordering sixty days of supply is not over budget by any single month's
 * reckoning — it is spending two months at once. The order looks like $10,000
 * against a $5,000 figure and reads as a disaster, when in fact it is exactly
 * on budget for the time it covers. What the office actually needs to know is
 * that next month is already committed.
 *
 * So the comparison is against the allowance for the DAYS THE ORDER COVERS, not
 * against one month, and the message says which of the two situations it is.
 */
class BudgetCheck
{
    /** The month everyone means when they say "monthly budget". */
    private const DAYS_IN_MONTH = 30;

    /** Within this much of the allowance, say so before it is exceeded. */
    private const CLOSE_PCT = 90;

    /**
     * @return array{
     *     budget: float, months: float, allowance: float, order_value: float,
     *     balance: float, used_pct: float|null, days: int,
     *     level: string, message: string|null, spans_months: bool
     * }
     */
    public static function for(Vessel $vessel, float $orderValue, ?int $days = null): array
    {
        $budget = round((float) $vessel->budget, 2);
        $days = max(1, (int) ($days ?: $vessel->supply_period_days ?: self::DAYS_IN_MONTH));
        $orderValue = round($orderValue, 2);

        $months = round($days / self::DAYS_IN_MONTH, 2);
        $allowance = round($budget * $months, 2);

        // No budget set is not a budget of zero. Saying "over budget" to a
        // vessel nobody has given a figure to would train the crew to ignore
        // the warning on the ships where it means something.
        if ($budget <= 0) {
            return self::result($budget, $months, $allowance, $orderValue, $days, 'none', null);
        }

        $usedPct = $allowance > 0 ? round($orderValue / $allowance * 100, 1) : null;
        $money = fn (float $n) => '$'.number_format($n, 2);
        $spans = $days > self::DAYS_IN_MONTH;

        // Over the allowance for the period it covers. The real overspend.
        if ($orderValue > $allowance) {
            $over = round($orderValue - $allowance, 2);

            return self::result($budget, $months, $allowance, $orderValue, $days, 'over', $spans
                ? "This order covers {$days} days — about {$months} months of the {$money($budget)} monthly budget, "
                    ."so {$money($allowance)} to spend. At {$money($orderValue)} it is {$money($over)} over. "
                    .'Trim the quantities, or shorten the period it has to cover.'
                : "{$money($orderValue)} against a {$money($budget)} budget — {$money($over)} over. "
                    .'Approve it if the ship needs it, but the office should know.');
        }

        // Inside the allowance, but it eats more than one month of it. This is
        // the message the owner asked for: it is not an overspend, it is next
        // month being spent in advance.
        if ($spans) {
            $thisMonth = min($orderValue, $budget);
            $next = round($orderValue - $thisMonth, 2);

            return self::result($budget, $months, $allowance, $orderValue, $days, 'spans', $next > 0
                ? "This order covers {$days} days, so it draws on more than one month. "
                    ."{$money($thisMonth)} comes out of this month's {$money($budget)} and about {$money($next)} "
                    .'out of next — plan next month around what is already on board.'
                : "This order covers {$days} days and still fits inside one month's budget.");
        }

        if ($usedPct !== null && $usedPct >= self::CLOSE_PCT) {
            $left = round($allowance - $orderValue, 2);

            return self::result($budget, $months, $allowance, $orderValue, $days, 'close',
                "{$usedPct}% of the month's budget, leaving {$money($left)} for anything else that comes up.");
        }

        return self::result($budget, $months, $allowance, $orderValue, $days, 'ok', null);
    }

    /**
     * The same verdict with the figures taken out, for an account that is not
     * shown money.
     *
     * The cook still has to be told. The owner's words: "when they have budget
     * of 5000 and they ordered more than it it should flag it so the master or
     * cook will decrease the quantity of the items they will order." So what
     * survives is the part that makes him act — there is too much on this
     * order, take something off — and what goes is every figure behind it.
     *
     * The wording lives here beside the version with the money in it, because
     * two descriptions of the same verdict drifting apart is how a ship ends up
     * being told one thing by the warning and another by the master.
     *
     * `used_pct` stays: a percentage of an allowance the reader cannot see is
     * not a sum of money, and "95% of what you are allowed" is the single most
     * useful thing a cook can be told while trimming an order down.
     */
    public static function withoutMoney(array $result): array
    {
        $days = (int) ($result['days'] ?? self::DAYS_IN_MONTH);
        $pct = $result['used_pct'] ?? null;

        $message = match ($result['level'] ?? 'none') {
            'over' => ($result['spans_months'] ?? false)
                ? "This order covers {$days} days and is over what the vessel is allowed for them. "
                    .'Trim the quantities, or shorten the period it has to cover.'
                : "This order is over the vessel's budget. Trim the quantities, "
                    .'or send it up as it is and let the master decide.',
            'spans' => "This order covers {$days} days, so it draws on more than one month's budget. "
                .'The master should know before it goes ashore.',
            'close' => $pct === null
                ? null
                : "About {$pct}% of what the vessel is allowed for these {$days} days.",
            default => null,
        };

        return array_merge($result, [
            'budget' => null,
            'allowance' => null,
            'order_value' => null,
            'balance' => null,
            'message' => $message,
        ]);
    }

    private static function result(
        float $budget, float $months, float $allowance, float $orderValue,
        int $days, string $level, ?string $message
    ): array {
        return [
            'budget' => $budget,
            'days' => $days,
            'months' => $months,
            'allowance' => $allowance,
            'order_value' => $orderValue,
            'balance' => round($allowance - $orderValue, 2),
            'used_pct' => $allowance > 0 ? round($orderValue / $allowance * 100, 1) : null,
            // none | ok | close | spans | over — the screen colours by this and
            // never decides for itself what counts as a problem.
            'level' => $level,
            'spans_months' => $days > self::DAYS_IN_MONTH,
            'message' => $message,
        ];
    }
}
