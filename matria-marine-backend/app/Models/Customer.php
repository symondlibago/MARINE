<?php

namespace App\Models;

use App\Support\PartyNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    /**
     * `customer_no` is deliberately NOT fillable — it is issued by
     * {@see PartyNumber}, never posted in by a form, so no request can set or
     * change one.
     */
    protected $fillable = [
        'name',
        'address',
        'email',
        'phone',
        'currency',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'customer_no' => 'integer',
    ];

    /** Every new customer gets its number, whichever screen created it. */
    protected static function booted(): void
    {
        static::created(fn (self $customer) => PartyNumber::assign($customer));
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}
