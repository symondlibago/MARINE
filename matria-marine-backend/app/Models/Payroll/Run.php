<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    protected $table = 'payroll_runs';

    protected $fillable = [
        'period', 'payment_date', 'status', 'currency', 'notes', 'finalised_at', 'finalised_by',
    ];

    protected $casts = [
        'period' => 'date',
        'payment_date' => 'date',
        'finalised_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(RunLine::class, 'run_id')->orderBy('sort')->orderBy('id');
    }

    /**
     * A finalised month is history. The one rule the whole module hangs on:
     * nothing that has been finalised may be edited without reopening it first.
     */
    public function isLocked(): bool
    {
        return $this->status === 'finalised';
    }

    /** The month's totals, as the list and the summary PDF both show them. */
    public function totals(): array
    {
        $sum = fn (string $c) => round((float) $this->lines->sum(fn ($l) => (float) $l->{$c}), 2);

        return [
            'headcount' => $this->lines->count(),
            'gross_earnings' => $sum('gross_earnings'),
            'employee_cpf' => $sum('employee_cpf'),
            'shg_deduction' => $sum('shg_deduction'),
            'total_deductions' => $sum('total_deductions'),
            'net_salary' => $sum('net_salary'),
            'employer_cpf' => $sum('employer_cpf'),
            'total_cpf' => $sum('total_cpf'),
            'sdl' => $sum('sdl'),
            'total_employer_cost' => $sum('total_employer_cost'),
        ];
    }
}
