<?php

namespace App\Support\Inventory;

/**
 * Take the money out of a payload bound for an account that is not shown it.
 *
 * Who that is belongs to VesselAccess::seesMoney(); this knows only which
 * fields are sums of money, which is the other half of the same question and
 * the half that goes stale. A field added to the calculator next month is a
 * price the cook can read unless somebody remembers — so the names live here,
 * in one list, instead of being re-remembered at a dozen controllers.
 *
 * Hidden, not omitted. A key that disappears makes `row.unit_price` undefined
 * and the screen renders "NaN" or "$undefined"; a key that is null reads as
 * "not for you" everywhere, and the client already draws a dash for it. The
 * difference matters because the same components serve both kinds of account.
 *
 * What is NOT money, and must survive:
 *
 *   over_budget     the flag. The cook is told an order is too big — that is
 *                   how he knows to take something off — he is simply not told
 *                   by how much, or what anything costs.
 *   price_missing   whether a line has no price yet. A gap, not a figure.
 *   missing_prices  how many of them. A count.
 *   stock_batches   how many batches are on the shelf. A count.
 *
 * None of this is a security boundary and it is not pretending to be one. It
 * keeps money off the screens of people whose job does not involve it, which is
 * what was asked for.
 */
class MoneyVeil
{
    /**
     * Money on one scored item row, as ProvisionCalculator::calculate returns
     * it. The list is that method's output read field by field.
     */
    public const ITEM = [
        'unit_price',
        'standing_price',
        'stock_cost',
        'stock_cost_low',
        'stock_cost_high',
        'stock_value',
        'order_value',
        'expiring_value',
    ];

    /** Money on the aggregate, as ProvisionCalculator::summarize returns it. */
    public const SUMMARY = [
        'stock_value',
        'order_value',
        'budget',
        'budget_balance',
        'expiring_value',
    ];

    /**
     * Prices on a requisition's lines — off the ship's copy for BOTH ranks.
     *
     * Not a rank rule but a document rule, and the owner's own: "in the
     * requisition it should have no price displayed here please since its a
     * request, the prices will vary each month."
     *
     * A requisition asks for quantities. Its prices are whatever the catalogue
     * held the day it was written, and the order goes to vendors precisely to
     * find out what they really are. A master trimming an order against a
     * figure nobody will be charged is being asked to do arithmetic on fiction,
     * so the reason holds for him as much as for the cook. Money enters at the
     * enquiry, where it is real.
     */
    public const REQUISITION_LINES = [
        'unit_price',
        'line_total',
    ];

    /**
     * What the order comes to, and the budget behind it — the cook's only.
     *
     * The master signs the order off and answers for what the vessel spends, so
     * he is told the size of what he is approving even though the breakdown
     * above is not shown to him. The cook gets the flag and no figure.
     */
    public const REQUISITION_TOTALS = [
        'total_value',
        'budget',
        'budget_at_issue',
        'budget_balance',
    ];

    /** Money on a withdrawal, and on the batches it was drawn from. */
    public const WITHDRAWAL = [
        'cost',
        'unit_cost',
        'value',
        'expired_value',
        'expiring_value',
    ];

    /** Money on a delivery line. */
    public const RECEIPT = [
        'unit_price',
    ];

    /**
     * Money on the ship herself.
     *
     * What she may spend in a month, and the three rates her catering is billed
     * at. The master decides how his vessel is provisioned; he does not set
     * either of those, and the cook is shown neither. `exchange_rate` is not
     * here — it converts a figure, it is not one.
     */
    public const VESSEL = [
        'budget',
        'ration_rate',
        'visitor_meal_rate',
        'charterer_meal_rate',
    ];

    /**
     * The catering rates, and every amount struck from them.
     *
     * A ration rate is a commercial term between the owner and the charterer.
     * The cook feeds people; he does not price them. So every count survives —
     * meals, rations, heads on board, days recorded — because a ration sheet
     * with the heads blanked out is no use to the man filling it in, and what
     * goes is the rate and the money.
     *
     * `purchases_amount` is deliberately NOT here. It is money, but it is not a
     * rate he is reading: it is what he spent at the market this morning and is
     * reporting. He is the only person who knows the figure, so he is the one
     * who types it.
     *
     * `ration`, `visitor` and `charterer` are the three keys of the rates map
     * and appear nowhere else in these payloads — every count is prefixed
     * (`visitor_meals`, `charterer_rations`), and the charge lines carry
     * 'visitor' as a value, which hide() does not look at. Keep it that way: a
     * bare `visitor` key added elsewhere would be nulled by this list.
     */
    public const RATION = [
        'amount',
        'rate',
        'ration',
        'visitor',
        'charterer',
    ];

    /**
     * Null out the named fields, however deep they are.
     *
     * Recursive because almost nothing here is flat: a requisition carries
     * lines, a delivery carries items, the batch board carries lots inside a
     * summary. Recursing is what stops the list above being written twice.
     *
     * The caller passes the field list rather than a single global one, because
     * `value` is money on a batch and an option's identifier on a dropdown in
     * the very same response. A list per shape is the only honest way to tell
     * those apart.
     *
     * @param  array<string>  $fields
     */
    public static function hide(array $payload, array $fields): array
    {
        $keys = array_flip($fields);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::hide($value, $fields);

                continue;
            }

            if (isset($keys[$key])) {
                $payload[$key] = null;
            }
        }

        return $payload;
    }
}
