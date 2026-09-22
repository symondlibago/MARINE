<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashToMasterRecord extends Model
{
    protected $fillable = [
        'reference', 'transaction_date', 'customer_id', 'vessel', 'currency',
        'exchange_rate', 'cash_delivered', 'transit_cash_incoming',
        'transit_cash_outgoing', 'funds_account_code', 'fee_account_code',
        'fx_account_code', 'notes', 'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'exchange_rate' => 'decimal:8',
        'cash_delivered' => 'decimal:4',
        'transit_cash_incoming' => 'decimal:4',
        'transit_cash_outgoing' => 'decimal:4',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The agent fee earned: client funds above the principal delivered. */
    public function feeAmount(): float
    {
        return round((float) $this->transit_cash_incoming - (float) $this->cash_delivered, 4);
    }

    public function feePercentage(): float
    {
        $principal = (float) $this->cash_delivered;

        return $principal > 0 ? round($this->feeAmount() / $principal * 100, 4) : 0.0;
    }

    /** The FX loss, transfer fee, or other outgoing cost entered for the CTM. */
    public function fxExpense(): float
    {
        return round((float) $this->transit_cash_outgoing, 4);
    }

    /** Kept as the API/accounting compatibility name for the CTM FX expense. */
    public function fxVariance(): float
    {
        return $this->fxExpense();
    }

    public function netProfit(): float
    {
        return round($this->feeAmount() - $this->fxExpense(), 4);
    }
}
