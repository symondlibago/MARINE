<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $table = 'payroll_employees';

    protected $fillable = [
        'code', 'full_name', 'nric', 'date_of_birth', 'status',
        'cpf_scheme', 'shg_fund', 'basic_salary', 'fixed_allowance',
        'payment_method', 'bank_ref', 'job_title', 'start_date', 'end_date', 'notes',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'basic_salary' => 'decimal:2',
        'fixed_allowance' => 'decimal:2',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function lines()
    {
        return $this->hasMany(RunLine::class, 'employee_id');
    }

    /** The next free employee code, e.g. MM007. */
    public static function nextCode(string $prefix = 'MM'): string
    {
        $highest = 0;

        foreach (static::pluck('code') as $code) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/i', (string) $code, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return $prefix.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }
}
