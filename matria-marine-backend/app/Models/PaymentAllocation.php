<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How much of one payment is applied to one invoice or purchase order. */
class PaymentAllocation extends Model
{
    protected $fillable = [
        'payment_id',
        'customer_invoice_id',
        'purchase_order_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice()
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
