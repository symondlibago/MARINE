<?php

namespace App\Support;

use App\Models\DeliveryOrder;
use App\Models\Offer;

/**
 * The one shape the pro-forma invoice template renders.
 *
 * A pro-forma can come from two places — a delivery order, or a quotation for a
 * customer who pays before anything ships and never sees a delivery order. They
 * carry different fields (a DO has a delivery address and a readiness date; a
 * quotation has delivery charges and GST), so each is flattened into the same
 * shape here rather than teaching the template about both.
 *
 * Keeping one template matters: a pro-forma is what the customer pays against,
 * and two copies would drift.
 */
class ProformaDoc
{
    public static function fromDeliveryOrder(DeliveryOrder $do): array
    {
        return [
            'number' => $do->proforma_number ?: $do->do_number,
            'source_line' => 'for DO '.$do->do_number,
            'date' => optional($do->order_date)->format('n/j/Y') ?: $do->created_at->format('n/j/Y'),
            'currency' => $do->currency,
            'customer_name' => $do->customer_name,
            'customer_address' => $do->customer_address,
            'deliver_to' => $do->delivery_address,
            'second_label' => 'Readiness',
            'second_value' => optional($do->readiness_date)->format('n/j/Y') ?: '—',
            'customer_reference' => $do->customer_reference,
            'prepared_by' => self::preparedBy($do->creator),
            'items' => self::lines($do->items),
            // A delivery order's proforma has only ever shown its subtotal.
            'extra_totals' => [],
            'total' => (float) $do->subtotal,
            'standfirst' => 'This is a proforma invoice for the above delivery order.',
            'notes' => $do->notes,
        ];
    }

    public static function fromOffer(Offer $offer): array
    {
        $delivery = (float) $offer->packing_cost + (float) $offer->transportation_cost;

        // Only what is actually charged: a zero delivery line or 0% GST on a
        // zero-rated marine supply is noise on a document asking to be paid.
        $extra = [];

        // The discount taken off the whole quotation. Per-line discounts are
        // NOT added here — they are already inside each line's amount, and are
        // spelled out under their own item instead, so nothing is counted
        // twice. The VENDOR's discount never appears at all: that is our buying
        // price and none of the customer's business.
        $overall = round((float) $offer->discount_amount, 2);
        if ($overall > 0) {
            $extra[] = [
                'label' => 'Discount '.rtrim(rtrim(number_format((float) $offer->discount_pct, 2, '.', ''), '0'), '.').'%',
                'amount' => -$overall,
            ];
        }

        if ($delivery > 0) {
            $extra[] = ['label' => 'Delivery', 'amount' => round($delivery, 2)];
        }
        if ((float) $offer->tax_amount > 0) {
            $extra[] = [
                'label' => 'GST '.rtrim(rtrim(number_format((float) $offer->tax_rate, 2, '.', ''), '0'), '.').'%',
                'amount' => (float) $offer->tax_amount,
            ];
        }

        return [
            'number' => $offer->proforma_number ?: $offer->offer_number,
            'source_line' => 'for quotation '.$offer->offer_number,
            'date' => $offer->created_at->format('n/j/Y'),
            'currency' => $offer->currency,
            'customer_name' => $offer->customer_name,
            'customer_address' => $offer->customer_address,
            // No delivery order behind it, so there is no separate ship-to.
            'deliver_to' => null,
            'second_label' => 'Payment Terms',
            'second_value' => $offer->payment_terms ?: '—',
            'customer_reference' => $offer->customer_po_number ?: $offer->rfq?->customer_reference,
            'prepared_by' => self::preparedBy($offer->creator),
            'items' => self::lines($offer->items->where('is_heading', false)),
            'extra_totals' => $extra,
            // The full amount payable, not just the items — this is what the
            // customer transfers, so it has to match the quotation's total.
            'total' => (float) $offer->grand_total,
            'standfirst' => 'This is a proforma invoice for the above quotation, issued for payment in advance. It is not a tax invoice.',
            'notes' => $offer->notes,
        ];
    }

    private static function lines($items): array
    {
        return collect($items)->map(fn ($i) => [
            'description' => (string) $i->description,
            'unit' => (string) $i->unit,
            'qty' => (float) $i->qty,
            'unit_price' => (float) $i->unit_price,
            'line_total' => (float) $i->line_total,
            // A discount given on this line alone. Already inside line_total,
            // so it is printed under the item rather than added to the totals.
            'discount_pct' => (float) ($i->cust_discount_pct ?? 0),
            'discount_amount' => (float) ($i->cust_discount_amount ?? 0),
            'remarks' => $i->remarks,
        ])->values()->all();
    }

    private static function preparedBy($user): ?string
    {
        if (! $user?->name) {
            return null;
        }

        return $user->name.($user->phone ? ' · '.$user->phone : '');
    }
}
