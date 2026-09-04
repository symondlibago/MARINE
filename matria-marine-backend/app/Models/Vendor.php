<?php

namespace App\Models;

use App\Support\PartyNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use HasFactory;

    /**
     * `vendor_no` is deliberately NOT fillable — it is issued by
     * {@see PartyNumber}, never posted in by a form.
     */
    protected $fillable = [
        'name',
        'contact_name',
        'email',
        'phone',
        'address',
        'currency',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'vendor_no' => 'integer',
    ];

    /** Every new vendor gets its number, whichever screen created it. */
    protected static function booted(): void
    {
        static::created(fn (self $vendor) => PartyNumber::assign($vendor));
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
