<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerInvoiceItem extends Model
{
    protected $fillable = [
        'customer_invoice_id',
        'is_heading',
        'description',
        'code',
        'unit',
        'qty',
        'unit_price',
        'line_total',
        'remarks',
        'sort',
    ];

    protected $casts = [
        'is_heading' => 'boolean',
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }
}
