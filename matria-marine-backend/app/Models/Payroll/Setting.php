<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;

/**
 * The company header printed on every payslip. Exactly one row, created on
 * first use so the module never has to be "set up" before it will open.
 */
class Setting extends Model
{
    protected $table = 'payroll_settings';

    protected $fillable = [
        'company_name', 'uen', 'company_address', 'currency',
        'default_payment_method', 'payslip_footer',
    ];

    public static function current(): self
    {
        return static::first() ?? static::create([
            'company_name' => 'Matria Marine',
            'currency' => 'SGD',
            'default_payment_method' => 'Bank Transfer',
            'payslip_footer' => 'This is a system-generated payslip. No signature is required.',
        ]);
    }
}
