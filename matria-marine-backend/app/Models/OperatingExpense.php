<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperatingExpense extends Model
{
    protected $fillable = [
        'name',
        'category',
        'amount',
        'currency',
        'exchange_rate',
        'effective_date',
        'recurring',
        'end_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'end_date' => 'date',
        'recurring' => 'boolean',
        'amount' => 'decimal:4',
        'exchange_rate' => 'decimal:8',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** This expense's amount for a single month, converted to base currency. */
    public function baseAmount(): float
    {
        return (float) $this->amount * (float) ($this->exchange_rate ?: 1);
    }
}
