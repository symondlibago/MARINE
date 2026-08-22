<?php

namespace App\Http\Controllers\Payroll;

use App\Models\Payroll\Employee;
use App\Models\Payroll\Run;
use App\Models\Payroll\RunLine;
use App\Models\Payroll\Setting;
use App\Support\Payroll\CpfRates;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A payroll month, from opening it to finalising it.
 *
 * Opening a month copies the active employees onto it. From then on the run is
 * self-contained: editing an employee's salary afterwards changes next month,
 * never a month already opened, and never a month already finalised.
 */
class RunController extends PayrollController
{
    public function index()
    {
        $runs = Run::withCount('lines')
            ->orderByDesc('period')
            ->get()
            ->map(fn (Run $run) => [
                'id' => $run->id,
                'period' => $run->period->toDateString(),
                'period_label' => $run->period->format('F Y'),
                'payment_date' => $run->payment_date?->toDateString(),
                'status' => $run->status,
                'currency' => $run->currency,
                'headcount' => $run->lines_count,
                'finalised_at' => $run->finalised_at?->toDateTimeString(),
            ]);

        return $this->ok([
            'runs' => $runs,
            'settings' => Setting::current(),
            'active_employees' => Employee::active()->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'date'],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $period = Carbon::parse($data['period'])->startOfMonth();

        if (Run::whereDate('period', $period)->exists()) {
            return $this->fail($period->format('F Y').' has already been opened.');
        }

        $employees = Employee::active()->orderBy('code')->get();

        if ($employees->isEmpty()) {
            return $this->fail('There are no active employees yet. Add someone on the Employees page first.');
        }

        $settings = Setting::current();

        $run = DB::transaction(function () use ($period, $data, $employees, $settings) {
            $run = Run::create([
                'period' => $period,
                'payment_date' => $data['payment_date'] ?? $period->copy()->endOfMonth(),
                'status' => 'draft',
                'currency' => $settings->currency,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($employees->values() as $i => $employee) {
                RunLine::create($this->snapshot($employee, $settings) + [
                    'run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'sort' => $i,
                ]);
            }

            return $run;
        });

        return $this->ok(['run' => $this->present($this->recalculate($run))],
            $period->format('F Y').' opened with '.$employees->count().' employee(s).', 201);
    }

    public function show(Run $run)
    {
        return $this->ok(['run' => $this->present($run->load('lines'))]);
    }

    /** The run header — payment date and notes. */
    public function update(Request $request, Run $run)
    {
        if ($run->isLocked()) {
            return $this->fail('This month is finalised. Reopen it before making changes.');
        }

        $run->update($request->validate([
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]));

        return $this->ok(['run' => $this->present($run->fresh()->load('lines'))], 'Saved.');
    }

    /**
     * One line's typed figures. Everything else on the line is recalculated,
     * and so is the rest of the run — a bonus here can move the annual CPF
     * ceiling for the months that follow.
     */
    public function updateLine(Request $request, Run $run, RunLine $line)
    {
        if ($run->isLocked()) {
            return $this->fail('This month is finalised. Reopen it before making changes.');
        }

        if ($line->run_id !== $run->id) {
            return $this->fail('That line belongs to another month.', 404);
        }

        $data = $request->validate([
            'basic_pay' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'fixed_allowance' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'other_allowance' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'ot_pay' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'bonus_aw' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'aw_subject_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'no_pay_leave' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'other_deduction' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'other_employer_cost' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $line->fill(array_intersect_key($data, array_flip(RunLine::INPUTS)))->save();

        return $this->ok(['run' => $this->present($this->recalculate($run))]);
    }

    /**
     * Pull corrections to the employee master into an open month.
     *
     * A month is a snapshot on purpose, so that a later pay rise cannot rewrite
     * a payslip already issued. But when the snapshot was taken from incomplete
     * records — a missing date of birth, the wrong CPF scheme — the office needs
     * a way to bring the correction in without deleting and reopening the month.
     *
     * Identity and CPF details are refreshed because they are facts about the
     * person. A basic pay that was typed over by hand is left alone: only a
     * figure still sitting at the old salary follows the employee master.
     */
    public function syncFromEmployees(Run $run)
    {
        if ($run->isLocked()) {
            return $this->fail('This month is finalised. Reopen it before refreshing it.');
        }

        $employees = Employee::whereIn('code', $run->lines()->pluck('code'))->get()->keyBy('code');
        $changed = 0;

        foreach ($run->lines as $line) {
            $employee = $employees->get($line->code);

            if (! $employee) {
                continue;   // their record was deleted; the line keeps its own copy
            }

            $fresh = [
                'employee_id' => $employee->id,
                'full_name' => $employee->full_name,
                'nric' => $employee->nric,
                'date_of_birth' => $employee->date_of_birth,
                'cpf_scheme' => $employee->cpf_scheme,
                'shg_fund' => $employee->shg_fund,
                'payment_method' => $employee->payment_method ?: $line->payment_method,
                'bank_ref' => $employee->bank_ref,
                'monthly_basic' => $employee->basic_salary,
            ];

            // Untouched pay follows the master; a hand-typed figure does not.
            if ((float) $line->basic_pay === (float) $line->monthly_basic) {
                $fresh['basic_pay'] = $employee->basic_salary;
            }

            $line->fill($fresh);

            if ($line->isDirty()) {
                $line->save();
                $changed++;
            }
        }

        $this->recalculate($run);

        return $this->ok(
            ['run' => $this->present($run->fresh()->load('lines'))],
            $changed === 0
                ? 'Already up to date with the employee records.'
                : $changed.' line(s) refreshed from the employee records.'
        );
    }

    /** Bring someone onto a month they were not on — a mid-month joiner. */
    public function addLine(Request $request, Run $run)
    {
        if ($run->isLocked()) {
            return $this->fail('This month is finalised. Reopen it before making changes.');
        }

        $employee = Employee::findOrFail($request->validate([
            'employee_id' => ['required', 'integer', 'exists:payroll_employees,id'],
        ])['employee_id']);

        if ($run->lines()->where('code', $employee->code)->exists()) {
            return $this->fail($employee->full_name.' is already on this month.');
        }

        RunLine::create($this->snapshot($employee, Setting::current()) + [
            'run_id' => $run->id,
            'employee_id' => $employee->id,
            'sort' => (int) $run->lines()->max('sort') + 1,
        ]);

        return $this->ok(['run' => $this->present($this->recalculate($run))], $employee->full_name.' added to this month.');
    }

    /** Take someone off a month — a leaver, or somebody opened by mistake. */
    public function removeLine(Run $run, RunLine $line)
    {
        if ($run->isLocked()) {
            return $this->fail('This month is finalised. Reopen it before making changes.');
        }

        if ($line->run_id !== $run->id) {
            return $this->fail('That line belongs to another month.', 404);
        }

        $name = $line->full_name;
        $line->delete();

        return $this->ok(['run' => $this->present($run->fresh()->load('lines'))], $name.' removed from this month.');
    }

    public function finalise(Request $request, Run $run)
    {
        if ($run->isLocked()) {
            return $this->fail('This month is already finalised.');
        }

        $this->recalculate($run);

        $run->update([
            'status' => 'finalised',
            'finalised_at' => now(),
            'finalised_by' => $request->user()?->id,
        ]);

        return $this->ok(['run' => $this->present($run->fresh()->load('lines'))],
            $run->period->format('F Y').' finalised.');
    }

    public function reopen(Run $run)
    {
        if (! $run->isLocked()) {
            return $this->fail('This month is already open.');
        }

        $run->update(['status' => 'draft', 'finalised_at' => null, 'finalised_by' => null]);

        return $this->ok(['run' => $this->present($run->fresh()->load('lines'))],
            $run->period->format('F Y').' reopened for editing.');
    }

    /** Only a draft may be thrown away. Finalised months stay as a record. */
    public function destroy(Run $run)
    {
        if ($run->isLocked()) {
            return $this->fail('A finalised month cannot be deleted. Reopen it first if you really mean to.');
        }

        $label = $run->period->format('F Y');
        $run->delete();

        return $this->ok([], $label.' deleted.');
    }

    /** The employee's details as they stand today, copied onto the month. */
    private function snapshot(Employee $employee, Setting $settings): array
    {
        return [
            'code' => $employee->code,
            'full_name' => $employee->full_name,
            'nric' => $employee->nric,
            'date_of_birth' => $employee->date_of_birth,
            'cpf_scheme' => $employee->cpf_scheme,
            'shg_fund' => $employee->shg_fund,
            'payment_method' => $employee->payment_method ?: $settings->default_payment_method,
            'bank_ref' => $employee->bank_ref,
            'monthly_basic' => $employee->basic_salary,
            'basic_pay' => $employee->basic_salary,
            'fixed_allowance' => $employee->fixed_allowance,
        ];
    }

    /** One run as every screen sees it. */
    private function present(Run $run): array
    {
        $run->loadMissing('lines');

        return [
            'id' => $run->id,
            'period' => $run->period->toDateString(),
            'period_label' => $run->period->format('F Y'),
            'payment_date' => $run->payment_date?->toDateString(),
            'status' => $run->status,
            'currency' => $run->currency,
            'notes' => $run->notes,
            'finalised_at' => $run->finalised_at?->toDateTimeString(),
            'locked' => $run->isLocked(),
            'lines' => $run->lines->map(fn (RunLine $l) => $l->toArray() + [
                'age_band_label' => CpfRates::AGE_BANDS[$l->age_band] ?? '',
            ]),
            'totals' => $run->totals(),
            'available_employees' => Employee::active()
                ->whereNotIn('code', $run->lines->pluck('code'))
                ->orderBy('code')
                ->get(['id', 'code', 'full_name']),
        ];
    }
}
