<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperatingExpenseItem extends Model
{
    protected $fillable = [
        'operating_expense_id',
        'name',
        'category',
        'amount',
        'sort',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function group()
    {
        return $this->belongsTo(OperatingExpense::class, 'operating_expense_id');
    }
}
