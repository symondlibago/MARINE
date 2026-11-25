import { useEffect, useMemo, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  ChevronLeft,
  ChevronRight,
  Save,
  Users,
  UtensilsCrossed,
  Wallet,
  CalendarCheck,
  FileText,
  Download,
  Columns3,
} from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import CrewRoster from "./CrewRoster";
import { money, btn, inputCls, numCls, StatCard, downloadBlob } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import Select from "@/portal/ui/Select";
import Modal from "@/portal/ui/Modal";
import DatePicker from "@/portal/ui/DatePicker";

/**
 * The crew ration sheet — one month at a time, the way the vessel keeps it.
 *
 * Who was fed, at which sitting, and on whose account. Crew and passengers are
 * billed by the ration (three meals feed one man for a day); visitors are
 * billed by the meal, at a lower rate, and the charterer's people often on a
 * rate of their own again.
 *
 * The derived columns are echoed here as you type, purely so a mistyped count
 * is obvious before it is saved. The backend owns them: App\Support\Inventory\
 * RationSheet recomputes every figure on read, so what is on screen after a
 * save is always the server's arithmetic, not this file's.
 */
const CREW = ["breakfast_crew", "lunch_crew", "dinner_crew"];
const PASSENGER = ["breakfast_passenger", "lunch_passenger", "dinner_passenger"];
const VISITOR = ["breakfast_visitors", "lunch_visitors", "dinner_visitors"];
const CHARTERER = ["breakfast_charterer", "lunch_charterer", "dinner_charterer"];
const COUNTS = [...CREW, ...PASSENGER, ...VISITOR, ...CHARTERER];

const MEALS_PER_RATION = 3;
const round2 = (v) => Math.round(v * 100) / 100;

/** Mirrors RationSheet::day — see the note above. */
function derive(day, rates) {
  const sum = (fields) => fields.reduce((t, f) => t + Math.max(0, Number(day[f] || 0)), 0);

  const crewMeals = sum(CREW);
  const passengerMeals = sum(PASSENGER);
  const visitorMeals = sum(VISITOR);
  const chartererMeals = sum(CHARTERER);

  const crewRations = round2(crewMeals / MEALS_PER_RATION);
  const passengerRations = round2(passengerMeals / MEALS_PER_RATION);
  const visitorRations = round2(visitorMeals / MEALS_PER_RATION);
  const chartererRations = round2(chartererMeals / MEALS_PER_RATION);

  return {
    crew_meals: crewMeals,
    visitor_meals: visitorMeals,
    charterer_meals: chartererMeals,
    crew_rations: crewRations,
    passenger_rations: passengerRations,
    total_meals: crewMeals + passengerMeals + visitorMeals + chartererMeals,
    total_rations: round2(crewRations + passengerRations + visitorRations + chartererRations),
    amount: round2(
      (crewRations + passengerRations) * (rates.ration || 0) +
        visitorMeals * (rates.visitor || 0) +
        chartererMeals * (rates.charterer || 0)
    ),
  };
}

const thisMonth = () => new Date().toISOString().slice(0, 7);

const shiftMonth = (month, by) => {
  const [y, m] = month.split("-").map(Number);
  const d = new Date(y, m - 1 + by, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
};

/** Day 0 of the following month is the last day of this one. */
const monthEnd = (month) => {
  const [y, m] = month.split("-").map(Number);
  const d = new Date(y, m, 0);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

/**
 * Two years back and one forward — far enough to go over last year's sheet,
 * and to start the coming month before it arrives.
 */
function monthRange() {
  const out = [];
  for (let i = -24; i <= 12; i++) {
    const value = shiftMonth(thisMonth(), i);
    const [y, m] = value.split("-").map(Number);
    out.push({
      value,
      label: new Date(y, m - 1, 1).toLocaleDateString("en-GB", { month: "long", year: "numeric" }),
    });
  }
  return out;
}

const LOCATIONS = [
  { value: "", label: "—" },
  { value: "Sea", label: "Sea" },
  { value: "Port", label: "Port" },
];

export default function CrewRations() {
  const qc = useQueryClient();
  const [month, setMonth] = useState(thisMonth);
  const [draft, setDraft] = useState([]);
  const [showAll, setShowAll] = useState(false);
  const [report, setReport] = useState(null);
  const monthOptions = useMemo(monthRange, []);

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "crew-rations", month],
    queryFn: async () => (await inventoryAPI.crewRations({ month })).data.data,
  });

  useEffect(() => {
    if (data) setDraft(data.days);
  }, [data]);

  const rates = data?.rates ?? { ration: 0, visitor: 0, charterer: 0 };
  const crewSize = Number(data?.crew_size || 0);
  // `money` here is the formatter from lib; the access flag is the rank rule.
  const { vessel, money: seesMoney } = useAccess();
  const currency = data?.currency || "USD";

  const setDay = (date, patch) =>
    setDraft((rows) => rows.map((r) => (r.date === date ? { ...r, ...patch } : r)));

  /** Three sittings at full crew — the usual day, typed once instead of three times. */
  const fillEmptyDays = () => {
    let filled = 0;
    setDraft((rows) =>
      rows.map((r) => {
        if (COUNTS.some((f) => Number(r[f] || 0) > 0)) return r;
        filled++;
        return { ...r, breakfast_crew: crewSize, lunch_crew: crewSize, dinner_crew: crewSize };
      })
    );
    toast.success(`${filled} empty ${filled === 1 ? "day" : "days"} set to ${crewSize} crew — save to keep.`);
  };

  const save = useMutation({
    mutationFn: () =>
      inventoryAPI.saveCrewRations(
        draft.map((d) => ({
          date: d.date,
          port: d.port || null,
          location: d.location || null,
          additional_persons: d.additional_persons || null,
          purchases_amount: d.purchases_amount === "" || d.purchases_amount == null ? null : Number(d.purchases_amount),
          notes: d.notes || null,
          ...Object.fromEntries(COUNTS.map((f) => [f, Number(d[f] || 0)])),
        }))
      ),
    onSuccess: (res) => {
      toast.success(res.data.message || "Saved.");
      qc.invalidateQueries({ queryKey: ["inv", "crew-rations"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the ration sheet."),
  });

  const [downloading, setDownloading] = useState(false);

  const downloadReport = async () => {
    setDownloading(true);
    try {
      const res = await inventoryAPI.rationInvoicePdf({ from: report.from, to: report.to });
      downloadBlob(res.data, `Ration-Report-${report.from}.pdf`);
      setReport(null);
    } catch {
      toast.error("Could not generate the report.");
    } finally {
      setDownloading(false);
    }
  };

  // Totals follow what is on screen, so the foot of the sheet agrees with the
  // rows above it even before the month is saved.
  const totals = useMemo(() => {
    const sum = { crew_meals: 0, visitor_meals: 0, charterer_meals: 0, total_meals: 0, total_rations: 0, amount: 0 };
    let daysRecorded = 0;

    for (const day of draft) {
      const d = derive(day, rates);
      if (d.total_meals > 0) daysRecorded++;
      for (const k of Object.keys(sum)) sum[k] += d[k];
    }
    for (const k of ["total_rations", "amount"]) sum[k] = round2(sum[k]);

    return { ...sum, days_recorded: daysRecorded };
  }, [draft, rates]);

  if (isLoading) return <PageLoader label="Loading the ration sheet…" />;

  const num = "w-12 px-1 py-1 text-right";
  const noRates = !rates.ration && !rates.visitor && !rates.charterer;

  const Counts = ({ day, fields, first }) =>
    fields.map((field, i) => (
      <td key={field} className={`px-1 py-1.5 ${i === 0 && first ? "border-l border-slate-200" : ""}`}>
        <input
          type="number"
          min={0}
          value={day[field] ?? 0}
          onChange={(e) => setDay(day.date, { [field]: e.target.value })}
          onFocus={(e) => e.target.select()}
          className={`${numCls} ${num}`}
        />
      </td>
    ));

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-[#28364b]">Crew rations</h1>
          <p className="text-sm text-slate-500">
            Heads fed at each sitting. Crew and passengers are billed by the ration — three meals feed one man for a day
            — and visitors by the meal.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1 rounded-lg border border-slate-200 bg-white p-1">
            <button
              onClick={() => setMonth((m) => shiftMonth(m, -1))}
              className="rounded-md p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
              title="Previous month"
            >
              <ChevronLeft className="h-4 w-4" />
            </button>
            <div className="w-44">
              <Select value={month} onChange={(v) => setMonth(v || thisMonth())} options={monthOptions} triggerClassName="px-2 py-1" />
            </div>
            <button
              onClick={() => setMonth((m) => shiftMonth(m, 1))}
              className="rounded-md p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
              title="Next month"
            >
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>
          {seesMoney && (
            <button
              onClick={() => setReport({ from: `${month}-01`, to: monthEnd(month) })}
              className={btn.ghost}
              title="The catering account for a period, as the owner is billed"
            >
              <FileText className="h-4 w-4" /> Ration report
            </button>
          )}
          {crewSize > 0 && (
            <button onClick={fillEmptyDays} className={btn.ghost} title="Set every day with no counts yet to a full crew at all three sittings">
              <Users className="h-4 w-4" /> Fill empty days
            </button>
          )}
          <button onClick={() => save.mutate()} disabled={save.isLoading} className={btn.primary}>
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
          </button>
        </div>
      </div>

      {/* The names behind the figures. Collapsed, because the roster changes
          at a crew change and the sheet below is filled in every day. */}
      <CrewRoster vessel={vessel} />

      {noRates && seesMoney && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          No rates are set, so every amount reads zero. Set the ration and visitor meal rates in <strong>Settings</strong>.
        </div>
      )}

      <div className={`grid gap-4 sm:grid-cols-2 ${seesMoney ? "lg:grid-cols-4" : "lg:grid-cols-3"}`}>
        <StatCard label="Days recorded" value={`${totals.days_recorded} / ${draft.length}`} icon={CalendarCheck} />
        <StatCard
          label="Total meals"
          value={totals.total_meals.toLocaleString()}
          hint={`${totals.crew_meals} crew · ${totals.visitor_meals + totals.charterer_meals} visitors`}
          icon={UtensilsCrossed}
        />
        <StatCard
          label="Total rations"
          value={money(totals.total_rations)}
          hint={seesMoney ? undefined : "Three meals feed one man for a day"}
          icon={Users}
          tone={seesMoney ? undefined : "navy"}
        />
        {seesMoney && (
          <StatCard
            label="Charged"
            value={`${currency} ${money(totals.amount)}`}
            hint={`${money(rates.ration)} a ration · ${money(rates.visitor)} a visitor meal`}
            tone="navy"
            icon={Wallet}
          />
        )}
      </div>

      <div className="flex items-center justify-end">
        <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
          <input
            type="checkbox"
            checked={showAll}
            onChange={(e) => setShowAll(e.target.checked)}
            className="h-3.5 w-3.5 accent-[#28364b]"
          />
          <Columns3 className="h-3.5 w-3.5" /> Passengers, charterer &amp; purchases
        </label>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="overflow-x-auto">
          <table className={`w-full text-sm ${showAll ? "min-w-[1500px]" : "min-w-[1040px]"}`}>
            <thead>
              <tr className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-3 py-2 text-left font-semibold" rowSpan={2}>Date</th>
                <th className="px-2 py-2 text-left font-semibold" rowSpan={2}>At</th>
                <th className="px-2 py-2 text-left font-semibold" rowSpan={2}>Port</th>
                <th className="border-l border-slate-200 px-2 py-1.5 text-center font-semibold" colSpan={3}>Crew</th>
                {showAll && <th className="border-l border-slate-200 px-2 py-1.5 text-center font-semibold" colSpan={3}>Passengers</th>}
                <th className="border-l border-slate-200 px-2 py-1.5 text-center font-semibold" colSpan={3}>Visitors — owner</th>
                {showAll && <th className="border-l border-slate-200 px-2 py-1.5 text-center font-semibold" colSpan={3}>Visitors — charterer</th>}
                <th className="border-l border-slate-200 px-2 py-2 text-left font-semibold" rowSpan={2}>Additional persons</th>
                {showAll && <th className="px-2 py-2 text-right font-semibold" rowSpan={2}>Purchases</th>}
                <th className="border-l border-slate-200 px-2 py-2 text-right font-semibold" rowSpan={2}>Rations</th>
                {seesMoney && <th className="px-3 py-2 text-right font-semibold" rowSpan={2}>Charged</th>}
              </tr>
              <tr className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-400">
                {(showAll ? ["crew", "passenger", "visitor", "charterer"] : ["crew", "visitor"]).flatMap((group) => [
                  <th key={`${group}-b`} className="border-l border-slate-200 px-1 py-1.5 text-right font-semibold">B</th>,
                  <th key={`${group}-l`} className="px-1 py-1.5 text-right font-semibold">L</th>,
                  <th key={`${group}-d`} className="px-1 py-1.5 text-right font-semibold">D</th>,
                ])}
              </tr>
            </thead>
            <tbody>
              {draft.map((day) => {
                const d = derive(day, rates);
                const blank = d.total_meals === 0;

                return (
                  <tr key={day.date} className={`border-t border-slate-100 ${blank ? "" : "bg-emerald-50/20"}`}>
                    <td className="whitespace-nowrap px-3 py-1.5">
                      <span className="font-semibold text-[#28364b]">{day.date.slice(8)}</span>{" "}
                      <span className="text-xs text-slate-400">{day.weekday}</span>
                    </td>
                    <td className="px-2 py-1.5">
                      <div className="w-20">
                        <Select
                          value={day.location ?? ""}
                          onChange={(v) => setDay(day.date, { location: v })}
                          options={LOCATIONS}
                          triggerClassName="px-2 py-1"
                        />
                      </div>
                    </td>
                    <td className="px-2 py-1.5">
                      <input
                        value={day.port ?? ""}
                        onChange={(e) => setDay(day.date, { port: e.target.value })}
                        placeholder="—"
                        className={`${inputCls} w-28 px-2 py-1`}
                      />
                    </td>
                    <Counts day={day} fields={CREW} first />
                    {showAll && <Counts day={day} fields={PASSENGER} first />}
                    <Counts day={day} fields={VISITOR} first />
                    {showAll && <Counts day={day} fields={CHARTERER} first />}
                    <td className="border-l border-slate-200 px-2 py-1.5">
                      <input
                        value={day.additional_persons ?? ""}
                        onChange={(e) => setDay(day.date, { additional_persons: e.target.value })}
                        placeholder="Supt., technicians…"
                        className={`${inputCls} w-36 px-2 py-1`}
                      />
                    </td>
                    {showAll && (
                      <td className="px-2 py-1.5">
                        <input
                          type="number"
                          min={0}
                          step="any"
                          value={day.purchases_amount ?? ""}
                          onChange={(e) => setDay(day.date, { purchases_amount: e.target.value })}
                          placeholder="—"
                          className={`${numCls} w-20 px-2 py-1`}
                        />
                      </td>
                    )}
                    <td className="border-l border-slate-200 px-2 py-1.5 text-right font-semibold text-[#28364b]">
                      {blank ? "—" : money(d.total_rations)}
                    </td>
                    {seesMoney && (
                      <td className="px-3 py-1.5 text-right font-semibold text-[#28364b]">
                        {blank ? "—" : money(d.amount)}
                      </td>
                    )}
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-[#28364b] bg-slate-50 font-bold text-[#28364b]">
                {/* Everything up to and including Purchases, so the two
                    totalled columns land under their own headings. */}
                <td className="px-3 py-2.5" colSpan={showAll ? 17 : 10}>
                  {data?.label} · {totals.days_recorded} {totals.days_recorded === 1 ? "day" : "days"} recorded ·{" "}
                  {totals.total_meals} meals
                </td>
                <td className="border-l border-slate-200 px-2 py-2.5 text-right">{money(totals.total_rations)}</td>
                {seesMoney && <td className="px-3 py-2.5 text-right">{currency} {money(totals.amount)}</td>}
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <Modal open={!!report} onClose={() => setReport(null)} title="Ration report" maxWidth="max-w-lg">
        {report && (
          <div className="space-y-4 p-6">
            <p className="text-sm text-slate-500">
              The catering account for a period, as the owner is billed: a charge line for crew, passengers and each
              kind of visitor, and the daily record it was added up from.
            </p>
            <p className="text-xs text-slate-400">
              A period usually runs from one supply to the next rather than month end to month end, so pick the dates
              the agreement covers.
            </p>

            <div className="grid gap-4 sm:grid-cols-2">
              <label className="block">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">From</span>
                <div className="mt-1">
                  <DatePicker value={report.from} onChange={(v) => setReport((r) => ({ ...r, from: v }))} />
                </div>
              </label>
              <label className="block">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">To</span>
                <div className="mt-1">
                  <DatePicker value={report.to} onChange={(v) => setReport((r) => ({ ...r, to: v }))} />
                </div>
              </label>
            </div>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setReport(null)} className={btn.ghost}>
                Cancel
              </button>
              <button onClick={downloadReport} disabled={downloading || !report.from || !report.to} className={btn.primary}>
                {downloading ? <Spinner className="h-4 w-4" /> : <Download className="h-4 w-4" />} Download PDF
              </button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
