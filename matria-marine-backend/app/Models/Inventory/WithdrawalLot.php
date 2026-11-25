<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of a withdrawal: this much, out of that lot, at that price.
 *
 * Without these a withdrawal could be recorded but never undone — putting the
 * quantity back would mean guessing which batches it had come out of. They are
 * also what makes a cost auditable: 20 kg at $220 is a claim, "10 at $10 and
 * 10 at $12" is the working.
 */
class WithdrawalLot extends Model
{
    protected $table = 'provision_withdrawal_lots';

    protected $fillable = [
        'withdrawal_id',
        'stock_lot_id',
        'quantity',
        'unit_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
    ];

    public function lot()
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    public function withdrawal()
    {
        return $this->belongsTo(Withdrawal::class, 'withdrawal_id');
    }

    public function value(): float
    {
        return round((float) $this->quantity * (float) $this->unit_cost, 4);
    }
}
