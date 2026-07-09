<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DeliveryOrder extends Model
{
    protected $fillable = [
        'do_number',
        'proforma_number',
        'offer_id',
        'purchase_order_id',
        'rfq_id',
        'customer_id',
        'customer_name',
        'customer_address',
        'delivery_address',
        'customer_reference',
        'currency',
        'status',
        'order_date',
        'readiness_date',
        'subtotal',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'readiness_date' => 'date',
        'subtotal' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(DeliveryOrderItem::class)->orderBy('sort')->orderBy('id');
    }

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recalcTotals(): void
    {
        $this->update(['subtotal' => round($this->items()->sum('line_total'), 2)]);
    }

    /**
     * Build (or return the existing) delivery order for an accepted offer.
     * Shared by staff ("Create Delivery Order") and customer acceptance, so both
     * paths produce an identical DO. Idempotent: one DO per offer.
     */
    public static function generateFromOffer(Offer $offer, ?int $userId = null): self
    {
        $existing = static::where('offer_id', $offer->id)->first();
        if ($existing) {
            return $existing;
        }

        $offer->loadMissing(['items', 'rfq:id,customer_reference']);

        return DB::transaction(function () use ($offer, $userId) {
            $do = static::create([
                'do_number' => static::nextNumber(),
                'offer_id' => $offer->id,
                'rfq_id' => $offer->rfq_id,
                'customer_id' => $offer->customer_id,
                'customer_name' => $offer->customer_name,
                'customer_address' => $offer->customer_address,
                'delivery_address' => $offer->customer_address, // default — staff confirms/edits
                'customer_reference' => $offer->rfq?->customer_reference,
                'currency' => $offer->currency,
                'status' => 'draft',
                'order_date' => now()->toDateString(),
                'subtotal' => $offer->subtotal,
                'created_by' => $userId,
            ]);

            $sort = 0;
            foreach ($offer->items->where('is_heading', false) as $it) {
                $do->items()->create([
                    'offer_item_id' => $it->id,
                    'description' => $it->description,
                    'code' => $it->code,
                    'unit' => $it->unit,
                    'qty' => $it->qty,
                    'unit_price' => $it->unit_price,
                    'discount_amount' => $it->discount_amount,
                    'line_total' => $it->line_total,
                    'remarks' => $it->remarks, // carry the offer line's remark through
                    'sort' => $sort++,
                ]);
            }

            $do->recalcTotals();

            if ($offer->status !== 'accepted') {
                $offer->update(['status' => 'accepted']);
            }

            return $do;
        });
    }

    /**
     * Build (or return the existing) delivery order for ONE purchase order —
     * the new flow: process the PO first, then create its own DO. The DO covers
     * exactly the PO's lines but at the CUSTOMER prices from the markup offer
     * (vendor costs never appear on customer documents). Idempotent: one DO per PO.
     */
    public static function generateFromPurchaseOrder(PurchaseOrder $po, ?int $userId = null): self
    {
        $existing = static::where('purchase_order_id', $po->id)->first();
        if ($existing) {
            return $existing;
        }

        $offer = Offer::with('items')->where('rfq_id', $po->rfq_id)->first();
        if (! $offer) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'offer' => 'Create the customer offer (Markup & Offer) first — the delivery order uses its customer prices.',
            ]);
        }

        $po->loadMissing(['items', 'rfq:id,customer_reference']);
        // Customer price per enquiry line, from the offer.
        $offerByRfqItem = $offer->items->whereNotNull('rfq_item_id')->keyBy('rfq_item_id');

        return DB::transaction(function () use ($po, $offer, $userId, $offerByRfqItem) {
            $do = static::create([
                'do_number' => static::nextNumber(),
                'offer_id' => $offer->id,
                'purchase_order_id' => $po->id,
                'rfq_id' => $po->rfq_id,
                'customer_id' => $offer->customer_id,
                'customer_name' => $offer->customer_name,
                'customer_address' => $offer->customer_address,
                'delivery_address' => $po->delivery_address ?: $offer->customer_address,
                'customer_reference' => $po->rfq?->customer_reference,
                'currency' => $offer->currency,
                'status' => 'draft',
                'order_date' => now()->toDateString(),
                'created_by' => $userId,
            ]);

            $sort = 0;
            foreach ($po->items as $line) {
                $oi = $line->rfq_item_id ? $offerByRfqItem->get($line->rfq_item_id) : null;
                $unit = (float) ($oi?->unit_price ?? 0);
                $discount = (float) ($oi?->discount_amount ?? 0);
                $qty = (float) $line->qty;

                $do->items()->create([
                    'offer_item_id' => $oi?->id,
                    'description' => $line->description,
                    'code' => $oi?->code,
                    'unit' => $line->unit,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'discount_amount' => $discount,
                    'line_total' => round(($unit - $discount) * $qty, 2),
                    'remarks' => $oi?->remarks ?? $line->remarks,
                    'sort' => $sort++,
                ]);
            }

            $do->recalcTotals();

            return $do;
        });
    }

    /** Next delivery-order number: MMS-DO-2026-000001. */
    public static function nextNumber(): string
    {
        return \App\Support\DocNumber::next('DO');
    }
}
