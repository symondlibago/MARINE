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
        // Inherited from the invoice line being credited, so the credit lands
        // back in the account the sale came from.
        'account_code',
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
