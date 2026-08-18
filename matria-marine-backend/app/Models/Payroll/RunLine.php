<?php

namespace App\Models\Payroll;

use App\Support\Payroll\PayslipCalculator;
use Illuminate\Database\Eloquent\Model;

class RunLine extends Model
{
    protected $table = 'payroll_run_lines';

    /** What the office may type. Everything else is calculated or snapshotted. */
    public const INPUTS = [
        'basic_pay', 'fixed_allowance', 'other_allowance', 'ot_pay', 'bonus_aw',
        'aw_subject_override', 'no_pay_leave', 'other_deduction', 'other_employer_cost', 'remarks',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'date_of_birth' => 'date',
        'review_flags' => 'array',
        'monthly_basic' => 'decimal:2',
        'basic_pay' => 'decimal:2',
        'fixed_allowance' => 'decimal:2',
        'other_allowance' => 'decimal:2',
        'ot_pay' => 'decimal:2',
        'bonus_aw' => 'decimal:2',
        'aw_subject_override' => 'decimal:2',
        'no_pay_leave' => 'decimal:2',
        'other_deduction' => 'decimal:2',
        'other_employer_cost' => 'decimal:2',
        'gross_earnings' => 'decimal:2',
        'ow_subject' => 'decimal:2',
        'aw_subject' => 'decimal:2',
        'cpf_wages' => 'decimal:2',
        'employee_cpf' => 'decimal:2',
        'employer_cpf' => 'decimal:2',
        'total_cpf' => 'decimal:2',
        'shg_deduction' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'sdl' => 'decimal:2',
        'total_employer_cost' => 'decimal:2',
    ];

    public function run()
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Recalculate this line and write the figures back.
     *
     * The year-to-date CPF wages are passed in rather than looked up here, so a
     * whole run can be recalculated without one query per line.
     */
    public function recalculate(float $ytdOw = 0, float $ytdAw = 0): self
    {
        // Read as a property: when a whole run is recalculated the relation is
        // already set, so this does not fire a query per line.
        $period = $this->run->period;

        $figures = PayslipCalculator::compute([
            'cpf_scheme' => $this->cpf_scheme,
            'shg_fund' => $this->shg_fund,
            'date_of_birth' => $this->date_of_birth,
            'basic_pay' => $this->basic_pay,
            'fixed_allowance' => $this->fixed_allowance,
            'other_allowance' => $this->other_allowance,
            'ot_pay' => $this->ot_pay,
            'bonus_aw' => $this->bonus_aw,
            'aw_subject_override' => $this->aw_subject_override,
            'no_pay_leave' => $this->no_pay_leave,
            'other_deduction' => $this->other_deduction,
            'other_employer_cost' => $this->other_employer_cost,
            'ytd_ow_subject' => $ytdOw,
            'ytd_aw_subject' => $ytdAw,
        ], $period);

        $this->forceFill($figures)->save();

        return $this;
    }
}
