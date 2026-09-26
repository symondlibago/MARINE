<?php

namespace App\Models\Payroll;

use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    protected $table = 'payroll_runs';

    protected $fillable = [
        // Three accounts, because a run posts three different costs. The first
        // is the salaries account; the other two take what the employer adds
        // on top of the wage.
        'period', 'payment_date', 'status', 'currency',
        'account_code', 'cpf_account_code', 'sdl_account_code',
        'notes', 'finalised_at', 'finalised_by',
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

    /** The account this month's wages are booked to, if one has been chosen. */
    public function accountRecord(): ?\App\Models\Account
    {
        return \App\Models\Account::find_by_code($this->account_code);
    }

    /**
     * Where each part of this run is posted.
     *
     * Salaries, the employer's CPF and the levy are three separate costs and
     * land in three separate accounts. A run saved before those existed falls
     * back to the staff-cost accounts, which is where they belonged anyway.
     *
     * @return array{salaries: string, cpf: string, sdl: string}
     */
    public function postingAccounts(): array
    {
        return [
            'salaries' => $this->account_code ?: \App\Models\Account::STAFF_SALARIES,
            'cpf' => $this->cpf_account_code ?: \App\Models\Account::STAFF_CPF,
            'sdl' => $this->sdl_account_code ?: \App\Models\Account::STAFF_SDL,
        ];
    }

    /**
     * What this month actually cost the business.
     *
     * Gross pay plus employer CPF plus SDL. NOT gross plus every CPF figure:
     * the employee's own CPF and their SHG contribution come out of the gross
     * they were already paid, so counting them again would inflate the wage
     * bill by roughly a fifth.
     */
    public function costToBusiness(): float
    {
        return round((float) ($this->totals()['total_employer_cost'] ?? 0), 2);
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
