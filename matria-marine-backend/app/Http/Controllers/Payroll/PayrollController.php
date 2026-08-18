<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Payroll\Run;
use App\Models\Payroll\RunLine;
use Illuminate\Support\Facades\DB;

/**
 * Shared plumbing for the payroll module.
 *
 * The two things every screen needs: the same response shape, and the same
 * definition of "recalculate this month", so a figure can only ever be produced
 * one way.
 */
abstract class PayrollController extends Controller
{
    protected function ok(array $data = [], ?string $message = null, int $status = 200)
    {
        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], fn ($v) => $v !== null), $status);
    }

    protected function fail(string $message, int $status = 422)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    /**
     * Recalculate every line on a run.
     *
     * The CPF-liable pay each person has already had this calendar year is
     * gathered in one query up front, because the annual ceiling on bonuses
     * depends on it.
     */
    protected function recalculate(Run $run): Run
    {
        $ytd = $this->yearToDate($run);

        $run->load('lines');
        $run->lines->each(function (RunLine $line) use ($run, $ytd) {
            $line->setRelation('run', $run);
            $prior = $ytd[$line->code] ?? ['ow' => 0.0, 'aw' => 0.0];
            $line->recalculate($prior['ow'], $prior['aw']);
        });

        return $run->load('lines');
    }

    /**
     * CPF-liable wages already recorded this calendar year, per employee code,
     * from the months BEFORE this one. Keyed by code rather than employee id so
     * it still works for a line whose employee record was later deleted.
     *
     * @return array<string, array{ow: float, aw: float}>
     */
    protected function yearToDate(Run $run): array
    {
        $year = $run->period->year;

        $rows = RunLine::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_lines.run_id')
            ->whereYear('payroll_runs.period', $year)
            ->where('payroll_runs.period', '<', $run->period->toDateString())
            ->groupBy('payroll_run_lines.code')
            ->get([
                'payroll_run_lines.code',
                DB::raw('SUM(payroll_run_lines.ow_subject) as ow'),
                DB::raw('SUM(payroll_run_lines.aw_subject) as aw'),
            ]);

        return $rows->mapWithKeys(fn ($r) => [
            $r->code => ['ow' => (float) $r->ow, 'aw' => (float) $r->aw],
        ])->all();
    }
}
