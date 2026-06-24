<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DeliveryOrder extends Model
{
    protected $fillable = [
        'do_number',
        'offer_id',
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

    /** Next sequential DO number (DO-0001), computed up front. */
    public static function nextNumber(): string
    {
        $last = static::orderByDesc('id')->value('do_number');
        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return 'DO-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
