<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditMemoItem extends Model
{
    protected $fillable = [
        'credit_memo_id',
        'customer_invoice_item_id',
        'description',
        'unit',
        'qty',
        'unit_price',
        'line_total',
        'reason',
        'sort',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:2',
    ];

    public function creditMemo()
    {
        return $this->belongsTo(CreditMemo::class);
    }
}
