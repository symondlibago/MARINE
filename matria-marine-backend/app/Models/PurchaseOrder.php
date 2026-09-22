<?php

namespace App\Models;

use App\Models\Concerns\HasAccountCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    use HasAccountCode;

    /** What we buy to fulfil a customer job is cost of sales. */
    protected string $defaultAccountCode = Account::DEFAULT_PURCHASE;

    protected $fillable = [
        'account_code',
        'tax_rate',
        'tax_amount',
        'po_number',
        'invoice_number',
        'token',
        'rfq_id',
        'vendor_id',
        'ship_name',
        'delivery_port',
        'delivery_address',
        'currency',
        'base_currency',
        'exchange_rate',
        'status',
        'issued_date',
        'expected_date',
        'opened_at',
        'accepted_at',
        'accepted_by_name',
        'acceptance_note',
        'subtotal',
        'receipt_amount',
        'has_credit_note',
        'credit_note_number',
        'credit_note_amount',
        'credit_note_account_code',
        'expenses',
        'expense_items',
        'expense_currency',
        'expense_rate',
        'paid_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'expected_date' => 'date',
        'opened_at' => 'datetime',
        'accepted_at' => 'datetime',
        'paid_at' => 'datetime',
        'exchange_rate' => 'decimal:8',
        'expense_rate' => 'decimal:8',
        'subtotal' => 'decimal:4',
        'has_credit_note' => 'boolean',
        'credit_note_amount' => 'decimal:4',
        'tax_rate' => 'decimal:3',
        'tax_amount' => 'decimal:4',
        'expense_items' => 'array',
    ];

    protected static function booted(): void
    {
        // Every PO gets a unique vendor magic-link token at creation.
        static::creating(function (PurchaseOrder $po) {
            if (empty($po->token)) {
                $po->token = Str::random(48);
            }
        });
    }

    /**
     * Orders that are actually money we owe.
     *
     * The vendor-side counterpart to {@see CustomerInvoice::scopeIssued()}: a
     * cancelled order is not a payable and must not reach a statement, an
     * open-entries report or a payment screen.
     */
    public function scopeLive($query)
    {
        return $query->where('status', '!=', 'cancelled');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort')->orderBy('id');
    }

    public function returnNote()
    {
        return $this->hasOne(ReturnNote::class);
    }

    public function attachments()
    {
        return $this->hasMany(PurchaseOrderAttachment::class)->latest();
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Recompute and persist the subtotal from the current line items (PO currency). */
    public function recalcSubtotal(): void
    {
        $this->update(['subtotal' => $this->items()->sum('line_total')]);
    }

    /** Vendor charge before a credit note is applied, in PO currency. */
    public function vendorGrossAmount(): float
    {
        return round((float) ($this->receipt_amount ?? $this->subtotal), 4);
    }

    /** Positive credit granted by the vendor, in PO currency. */
    public function vendorCreditAmount(): float
    {
        return $this->has_credit_note ? round(max((float) $this->credit_note_amount, 0), 4) : 0.0;
    }

    /** Actual cost and payable after the vendor credit note. */
    public function vendorNetAmount(): float
    {
        return round(max($this->vendorGrossAmount() - $this->vendorCreditAmount(), 0), 4);
    }
}
