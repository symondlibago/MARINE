<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single bank movement — a receipt from a customer or a payment to a vendor.
 *
 * The payment records what hit the bank. Which documents it settles lives in
 * {@see PaymentAllocation}, so one receipt can clear several invoices and one
 * invoice can be settled by several part-payments.
 */
class Payment extends Model
{
    protected $fillable = [
        'payment_number',
        'direction',
        'party_type',
        'customer_id',
        'vendor_id',
        'party_name',
        'payment_date',
        'currency',
        'amount',
        'method',
        'reference',
        'bank_account',
        'account_code',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** Bank slips and invoice copies filed against this payment. */
    public function attachments()
    {
        return $this->hasMany(PaymentAttachment::class)->orderBy('id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Total applied to documents. The rest sits unapplied (a payment on account).
     *
     * Reads the relation as a property, not a query: when the caller has eager
     * loaded allocations — as the statement does for every payment on the page
     * — this costs nothing. Calling allocations()->sum() here would fire one
     * query per payment.
     */
    public function allocatedAmount(): float
    {
        return round((float) $this->allocations->sum('amount'), 2);
    }

    /** Money received but not yet matched to an invoice — NAV's "unapplied". */
    public function unappliedAmount(): float
    {
        return round((float) $this->amount - $this->allocatedAmount(), 2);
    }
}
