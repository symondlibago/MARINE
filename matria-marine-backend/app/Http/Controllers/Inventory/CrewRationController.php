<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\CrewMember;
use App\Models\Inventory\CrewRation;
use App\Models\Inventory\Vessel;
use App\Support\Inventory\MoneyVeil;
use App\Support\Inventory\RationSheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The crew ration sheet: who was fed, on which day, in which port.
 *
 * One screen, one month at a time, the way the vessel's workbook keeps it. The
 * sheet always returns every day of the month — including the ones nobody has
 * filled in — so the crew can see at a glance where the gaps are rather than
 * having to work out which dates are missing.
 *
 * The report is the other end of the same data: a period, usually from one
 * supply to the next rather than a calendar month, totalled into something the
 * owner can be invoiced from.
 */
class CrewRationController extends InventoryController
{
    /** A month of the sheet, with every day present. */
    public function index(Request $request)
    {
        $vessel = $this->vessel($request);
        $month = $this->month($request);
        $rates = RationSheet::rates($vessel);

        $days = $this->days(
            $vessel,
            $month,
            $month->copy()->endOfMonth(),
            $rates,
            fillGaps: true
        );

        // The roster wins over the settings where there is one.
        //
        // "Fill empty days" writes this number onto every sitting, so it had
        // better be who is actually aboard rather than the figure somebody
        // typed into Settings when the ship joined the fleet. Only the crew
        // category counts: passengers and the charterer's people are billed
        // differently and are not fed as crew.
        $named = CrewMember::where('vessel_id', $vessel->id)
            ->aboard()
            ->where('category', CrewMember::CREW)
            ->count();

        return $this->ok($this->withoutMoney([
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'rates' => $rates,
            'currency' => $vessel->currency,
            'crew_size' => $named > 0 ? $named : (int) $vessel->crew_size,
            'crew_named' => $named,
            'days' => $days,
            'totals' => RationSheet::total($days, $rates),
        ], $request));
    }

    /**
     * Save a month's counts.
     *
     * The screen posts every day it is showing, so a day emptied back to zero
     * has its row removed rather than left behind as a row of noughts that
     * would read as "nobody ate" instead of "not counted yet".
     */
    public function save(Request $request)
    {
        $vessel = $this->vessel($request);

        $rules = [
            'days' => ['present', 'array'],
            'days.*.date' => ['required', 'date'],
            'days.*.port' => ['nullable', 'string', 'max:255'],
            'days.*.location' => ['nullable', 'in:Sea,Port'],
            'days.*.additional_persons' => ['nullable', 'string', 'max:255'],
            'days.*.purchases_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'days.*.notes' => ['nullable', 'string', 'max:255'],
        ];
        foreach (CrewRation::COUNTS as $field) {
            $rules["days.*.{$field}"] = ['nullable', 'integer', 'min:0', 'max:999'];
        }

        $data = $request->validate($rules);

        $saved = 0;
        $cleared = 0;

        DB::transaction(function () use ($vessel, $data, $request, &$saved, &$cleared) {
            foreach ($data['days'] as $day) {
                $date = Carbon::parse($day['date'])->toDateString();

                $row = CrewRation::firstOrNew(['vessel_id' => $vessel->id, 'date' => $date]);

                foreach (CrewRation::COUNTS as $field) {
                    $row->{$field} = (int) ($day[$field] ?? 0);
                }
                foreach (['port', 'location', 'additional_persons', 'notes'] as $text) {
                    $row->{$text} = ($day[$text] ?? '') !== '' ? $day[$text] : null;
                }
                $row->purchases_amount = ($day['purchases_amount'] ?? '') !== '' && $day['purchases_amount'] !== null
                    ? (float) $day['purchases_amount']
                    : null;

                if ($row->isEmpty()) {
                    if ($row->exists) {
                        $row->delete();
                        $cleared++;
                    }

                    continue;
                }

                $row->created_by ??= $request->user()?->id;
                $row->save();
                $saved++;
            }
        });

        $message = $saved === 1 ? '1 day saved.' : "{$saved} days saved.";
        if ($cleared > 0) {
            $message .= $cleared === 1 ? ' 1 day cleared.' : " {$cleared} days cleared.";
        }

        return $this->ok([], $message);
    }

    /**
     * The rations report: one line per month, for the year.
     *
     * What the sheet is for — rations fed against what they were allowed to
     * cost — read over a run of months rather than one at a time.
     */
    public function report(Request $request)
    {
        $vessel = $this->vessel($request);
        $rates = RationSheet::rates($vessel);

        $year = (int) ($request->integer('year') ?: Carbon::today()->year);
        $start = Carbon::create($year, 1, 1)->startOfDay();

        $days = $this->days($vessel, $start, $start->copy()->endOfYear(), $rates);

        $byMonth = [];
        foreach ($days as $day) {
            $byMonth[Carbon::parse($day['date'])->format('Y-m')][] = $day;
        }

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $first = Carbon::create($year, $m, 1);
            $months[] = [
                'month' => $first->format('Y-m'),
                'label' => $first->format('F Y'),
            ] + RationSheet::total($byMonth[$first->format('Y-m')] ?? [], $rates);
        }

        return $this->ok($this->withoutMoney([
            'year' => $year,
            'rates' => $rates,
            'currency' => $vessel->currency,
            'months' => $months,
            'totals' => RationSheet::total($days, $rates),
        ], $request));
    }

    /**
     * The invoice ration report for a period, as the owner is billed.
     *
     * A period is rarely a calendar month — it runs from one supply to the
     * next — so this takes dates rather than a month, defaulting to the one
     * just gone.
     */
    public function invoice(Request $request)
    {
        [$vessel, $from, $to, $days, $totals] = $this->period($request);

        return $this->ok($this->withoutMoney([
            'vessel' => $vessel->only(['id', 'name', 'imo', 'currency']),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days_in_period' => $from->diffInDays($to) + 1,
            'currency' => $vessel->currency,
            'rates' => RationSheet::rates($vessel),
            'days' => $days,
            'totals' => $totals,
        ], $request));
    }

    /** The same report, printed. */
    public function pdf(Request $request)
    {
        // The ration report is the billing document itself — a charge line per
        // category at the agreed rate, which is the one thing on this screen
        // that cannot be shown with the money taken out. The master answers for
        // the vessel's catering account and reads it; the cook keeps the sheet
        // it is added up from.
        if (! $this->seesMoney($request)) {
            abort(403, 'The ration report is the catering invoice. Ask the master or the office for a copy.');
        }

        [$vessel, $from, $to, $days, $totals] = $this->period($request);

        $name = 'Ration-Report-'.$vessel->name.'-'.$from->format('Y-m-d').'.pdf';

        return Pdf::loadView('pdf.inventory.ration-report', [
            'vessel' => $vessel,
            'company' => config('procurement.company'),
            'logo' => $this->logo(),
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'totals' => $totals,
            'currency' => $vessel->currency,
            // Who the figures are for. A report with twenty meals a day on it
            // and no names is hard to check and harder to dispute, and the
            // master is the one who gets asked.
            'crew' => CrewMember::where('vessel_id', $vessel->id)
                ->aboard()
                ->orderByRaw("CASE category WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END", [
                    CrewMember::CREW, CrewMember::PASSENGER,
                ])
                ->orderBy('sort')->orderBy('name')
                ->get(),
        ])->setPaper('a4')->download($name);
    }

    /* ----------------------------- helpers ----------------------------- */

    /**
     * The sheet with the rates and the amounts taken out, for an account that
     * is not shown money.
     *
     * Only the rates and what they come to go. Every count stays, because the
     * cook is the man who fills this screen in — see MoneyVeil::RATION, which
     * holds the list and the reasoning, including why the purchases box stays.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutMoney(array $payload, Request $request): array
    {
        return $this->seesMoney($request)
            ? $payload
            : MoneyVeil::hide($payload, MoneyVeil::RATION);
    }

    /**
     * The period in scope and everything scored over it.
     *
     * @return array{0: Vessel, 1: Carbon, 2: Carbon, 3: array, 4: array}
     */
    private function period(Request $request): array
    {
        $vessel = $this->vessel($request);
        $rates = RationSheet::rates($vessel);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : Carbon::today()->subMonthNoOverflow()->startOfMonth();

        $to = $request->query('to')
            ? Carbon::parse($request->query('to'))->startOfDay()
            : $from->copy()->endOfMonth();

        // A period that reads backwards is somebody's typo, not a request to
        // invoice nothing.
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $days = $this->days($vessel, $from, $to, $rates);

        return [$vessel, $from, $to, $days, RationSheet::total($days, $rates)];
    }

    /**
     * The days between two dates, scored.
     *
     * @param  bool  $fillGaps  include days nobody has entered — right for the
     *                          entry screen, wrong for an invoice, which should
     *                          show the days that happened and nothing else.
     * @return array<int, array>
     */
    private function days(Vessel $vessel, Carbon $from, Carbon $to, array $rates, bool $fillGaps = false): array
    {
        $saved = CrewRation::where('vessel_id', $vessel->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get()
            ->keyBy(fn (CrewRation $row) => $row->date->toDateString());

        $days = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $row = $saved->get($key);

            if ($row || $fillGaps) {
                $counts = [];
                foreach (CrewRation::COUNTS as $field) {
                    $counts[$field] = $row ? (int) $row->{$field} : 0;
                }

                $days[] = [
                    'date' => $key,
                    'day' => $cursor->format('d'),
                    'weekday' => $cursor->format('D'),
                    'port' => $row?->port,
                    'location' => $row?->location,
                    'additional_persons' => $row?->additional_persons,
                    'purchases_amount' => $row?->purchases_amount === null ? null : (float) $row->purchases_amount,
                    'notes' => $row?->notes,
                    'saved' => (bool) $row,
                ] + $counts + RationSheet::day($counts, $rates);
            }

            $cursor->addDay();
        }

        return $days;
    }

    /** The month in scope — ?month=2026-06, this month by default. */
    private function month(Request $request): Carbon
    {
        $month = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
            } catch (\Throwable) {
                // Fall through to this month.
            }
        }

        return Carbon::today()->startOfMonth();
    }

    private function logo(): ?string
    {
        $path = public_path('logo.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
    }
}
