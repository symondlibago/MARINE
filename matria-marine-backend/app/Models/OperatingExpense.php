<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperatingExpense extends Model
{
    use Concerns\HasAccountCode;

    /** Our own overheads — not tied to any one customer job. */
    protected string $defaultAccountCode = Account::DEFAULT_EXPENSE;

    protected $fillable = [
        'account_code',
        'tax_rate',
        'tax_amount',
        'label',
        'period_start',
        'period_end',
        'currency',
        'exchange_rate',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'exchange_rate' => 'decimal:8',
        'tax_rate' => 'decimal:3',
        'tax_amount' => 'decimal:4',
    ];

    public function items()
    {
        return $this->hasMany(OperatingExpenseItem::class)->orderBy('sort')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sum of the group's line items in its own currency. */
    public function total(): float
    {
        return (float) $this->items->sum(fn ($i) => (float) $i->amount);
    }

    /** Group total converted to base currency. */
    public function totalBase(): float
    {
        return $this->total() * (float) ($this->exchange_rate ?: 1);
    }
}
