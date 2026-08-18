<?php

namespace App\Http\Controllers\Payroll;

use App\Models\Payroll\Run;
use App\Models\Payroll\RunLine;
use App\Models\Payroll\Setting;
use App\Support\Payroll\CpfRates;
use Barryvdh\DomPDF\Facade\Pdf;
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
