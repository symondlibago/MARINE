<?php

namespace App\Http\Controllers\Payroll;

use App\Models\Payroll\Employee;
use App\Support\Payroll\CpfRates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The employee master. Entered once; every payroll month reads from here.
 */
class EmployeeController extends PayrollController
{
    public function index(Request $request)
    {
        $employees = Employee::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('full_name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('nric', 'like', $term));
            })
            ->orderBy('code')
            ->get();

        return $this->ok([
            'employees' => $employees,
            'next_code' => Employee::nextCode(),
            'cpf_schemes' => CpfRates::schemeNames(),
            'shg_funds' => CpfRates::shgNames(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        // 'code' is optional and validate() omits absent optional keys entirely.
        $data['code'] = ($data['code'] ?? null) ?: Employee::nextCode();

        $employee = Employee::create($data);

        return $this->ok(['employee' => $employee], $employee->full_name.' added.', 201);
    }

    public function update(Request $request, Employee $employee)
    {
        $employee->update($this->validated($request, $employee));

        return $this->ok(['employee' => $employee->fresh()], 'Employee updated.');
    }

    /**
     * Removing someone leaves their past payslips intact: every run line keeps
     * its own copy of the details it was calculated from.
     */
    public function destroy(Employee $employee)
    {
        $name = $employee->full_name;
        $employee->delete();

        return $this->ok([], $name.' removed. Past payslips are unaffected.');
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:32', Rule::unique('payroll_employees', 'code')->ignore($employee?->id)],
            'full_name' => ['required', 'string', 'max:190'],
            'nric' => ['nullable', 'string', 'max:32'],
            'date_of_birth' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'cpf_scheme' => ['required', Rule::in(CpfRates::schemeNames())],
            'shg_fund' => ['required', Rule::in(CpfRates::shgNames())],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'fixed_allowance' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'bank_ref' => ['nullable', 'string', 'max:190'],
            'job_title' => ['nullable', 'string', 'max:190'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
