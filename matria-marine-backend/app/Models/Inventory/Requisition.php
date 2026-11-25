<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved order raised from the live "to order" list.
 *
 * It travels: the cook writes it, the master approves it, the office buys it.
 * Each step is a different person on a different screen, and the status is how
 * they find the ones waiting on them.
 */
class Requisition extends Model
{
    protected $table = 'provision_requisitions';

    /** Being written on board. Only the ship sees it. */
    public const DRAFT = 'draft';
    /** Sent up for the master's approval. */
    public const PENDING = 'pending';
    /** The master sent it back, with a reason. Back in the cook's hands. */
    public const DECLINED = 'declined';
    /** Approved on board. Now on the office's list to be bought. */
    public const APPROVED = 'approved';
    /** The office has sent it out to vendors. */
    public const ISSUED = 'issued';
    /** Delivered and done. */
    public const CLOSED = 'closed';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::PENDING => 'Waiting for the master',
        self::DECLINED => 'Sent back',
        self::APPROVED => 'Approved',
        self::ISSUED => 'Ordered',
        self::CLOSED => 'Closed',
    ];

    /**
     * Still the ship's to change.
     *
     * Once the master has approved it the quantities are what the office is
     * buying against, so editing stops being a correction and becomes a
     * surprise for whoever is holding the purchase order.
     */
    public const EDITABLE = [self::DRAFT, self::DECLINED];

    /** On the office's list: approved on board, not yet bought. */
    public const AT_THE_OFFICE = [self::APPROVED, self::ISSUED, self::CLOSED];

    protected $fillable = [
        'vessel_id',
        'supplier_id',
        'req_number',
        'req_date',
        'required_by',
        'days_of_supply',
        'status',
        'currency',
        'total_value',
        'budget_at_issue',
        'notes',
        'issued_at',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'declined_at',
        'decline_reason',
        'created_by',
    ];

    protected $casts = [
        'req_date' => 'date',
        'required_by' => 'date',
        'days_of_supply' => 'integer',
        'total_value' => 'decimal:2',
        'budget_at_issue' => 'decimal:2',
        'issued_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(RequisitionItem::class, 'requisition_id')->orderBy('sort')->orderBy('id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function vessel()
    {
        return $this->belongsTo(Vessel::class, 'vessel_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The enquiry the office raised from this order.
     *
     * The one link between the two operations, and it only points this way:
     * the provisions side knows which enquiry it caused, while procurement
     * carries on exactly as before without needing to know a ship was behind
     * it. See App\Support\Inventory\RequisitionBridge.
     */
    public function rfq()
    {
        return $this->hasOne(\App\Models\Rfq::class, 'requisition_id');
    }

    /** The deliveries booked in against this order, drafts included. */
    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'requisition_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recalcTotal(): void
    {
        $this->total_value = round($this->items()->sum('line_total'), 2);
        $this->save();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** How many days the order was written to cover. */
    public function days(): int
    {
        return (int) ($this->days_of_supply ?: $this->vessel?->supply_period_days ?: 30);
    }
}
