<?php

namespace App\Http\Controllers\Payroll;

use App\Models\Payroll\Run;
use App\Models\Payroll\RunLine;
use App\Models\Payroll\Setting;
use App\Support\Payroll\CpfRates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The two printed documents: one payslip per person, and the month's summary.
 * Both are a single A4 page.
 */
class PdfController extends PayrollController
{
    public function payslip(Run $run, RunLine $line)
    {
        if ($line->run_id !== $run->id) {
            return $this->fail('That payslip belongs to another month.', 404);
        }

        $pdf = Pdf::loadView('pdf.payroll.payslip', [
            'company' => Setting::current(),
            'run' => $run,
            'line' => $line,
            'ageBand' => CpfRates::AGE_BANDS[$line->age_band] ?? '',
            'ytd' => $this->yearToDateTotals($run, $line->code),
            'logo' => $this->logo(),
        ])->setPaper('a4');

        return $pdf->download($this->filename($line->code.'-payslip-'.$run->period->format('Y-m')));
    }

    public function summary(Run $run)
    {
        $run->load('lines');

        $pdf = Pdf::loadView('pdf.payroll.summary', [
            'company' => Setting::current(),
            'run' => $run,
            'totals' => $run->totals(),
            'logo' => $this->logo(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($this->filename('payroll-summary-'.$run->period->format('Y-m')));
    }

    /**
     * The three running totals the office asked for, so a payslip doubles as a
     * year-to-date figure for tax: gross earnings paid, employee CPF and
     * employer CPF, summed across this calendar year up to AND including the
     * month being printed.
     *
     * Keyed by the snapshotted code rather than the employee id, so it keeps
     * working for someone whose employee record was later removed — the same
     * reasoning the run lines are snapshotted for in the first place.
     *
     * @return array{gross: float, employee_cpf: float, employer_cpf: float}
     */
    private function yearToDateTotals(Run $run, string $code): array
    {
        $row = RunLine::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_lines.run_id')
            ->whereYear('payroll_runs.period', $run->period->year)
            ->where('payroll_runs.period', '<=', $run->period->toDateString())
            ->where('payroll_run_lines.code', $code)
            ->first([
                DB::raw('COALESCE(SUM(payroll_run_lines.gross_earnings), 0) as gross'),
                DB::raw('COALESCE(SUM(payroll_run_lines.employee_cpf), 0) as employee_cpf'),
                DB::raw('COALESCE(SUM(payroll_run_lines.employer_cpf), 0) as employer_cpf'),
            ]);

        return [
            'gross' => (float) ($row->gross ?? 0),
            'employee_cpf' => (float) ($row->employee_cpf ?? 0),
            'employer_cpf' => (float) ($row->employer_cpf ?? 0),
        ];
    }

    private function logo(): ?string
    {
        $path = public_path('logo.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
    }

    private function filename(string $stem): string
    {
        return Str::slug($stem).'.pdf';
    }
}