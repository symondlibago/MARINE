import { useState } from "react";
import { Link, useRoute } from "wouter";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  ArrowLeft,
  FileText,
  Lock,
  Unlock,
  Plus,
  Trash2,
  Download,
  AlertTriangle,
  Wallet,
  Landmark,
  Receipt,
  Building2,
  RefreshCw,
} from "lucide-react";
import { toast } from "sonner";
import { payrollAPI } from "./api";
import { money, dim, dateOf, btn, inputCls, cellCls, StatCard, StatusPill, downloadBlob } from "./lib";
import DatePicker from "./DatePicker";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";
import Select from "@/portal/ui/Select";

/** The columns the office types into, in the order they appear on screen. */
const INPUTS = [
  { key: "basic_pay", label: "Basic Pay" },
  { key: "fixed_allowance", label: "Fixed Allow." },
  { key: "other_allowance", label: "Other Allow." },
  { key: "ot_pay", label: "Overtime" },
  { key: "bonus_aw", label: "Bonus / Comm." },
  { key: "no_pay_leave", label: "No-Pay Leave" },
  { key: "other_deduction", label: "Other Deduct." },
];

/**
 * One payroll month.
 *
 * Only the blue columns are typed. Everything to the right of Gross is worked
 * out by the backend and written back on every edit, so the grid, the payslip
 * and the summary can never disagree.
 */
export default function RunPage() {
  const [, params] = useRoute("/runs/:id");
  const id = params?.id;
  const qc = useQueryClient();
  const confirm = useConfirm();

  const [adding, setAdding] = useState("");

  const { data: run, isLoading } = useQuery({
    queryKey: ["pay", "run", id],
    queryFn: async () => (await payrollAPI.run(id)).data.data.run,
    enabled: !!id,
  });

  const applyRun = (res) => {
    const next = res?.data?.data?.run;
    if (next) qc.setQueryData(["pay", "run", id], next);
    qc.invalidateQueries({ queryKey: ["pay", "runs"] });
  };

  const saveCell = useMutation({
    mutationFn: ({ lineId, key, value }) => payrollAPI.updateLine(id, lineId, { [key]: value }),
    onSuccess: applyRun,
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save that figure."),
  });

  const saveHeader = useMutation({
    mutationFn: (payload) => payrollAPI.updateRun(id, payload),
    onSuccess: applyRun,
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save."),
  });

  const addLine = useMutation({
    mutationFn: (employeeId) => payrollAPI.addLine(id, employeeId),
    onSuccess: (res) => {
      toast.success(res?.data?.message || "Added.");
      setAdding("");
      applyRun(res);
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not add that person."),
  });

  const removeLine = useMutation({
    mutationFn: (lineId) => payrollAPI.removeLine(id, lineId),
    onSuccess: (res) => {
      toast.success(res?.data?.message || "Removed.");
      applyRun(res);
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not remove that line."),
  });

  // A month is a snapshot, so a correction made on the employee record after it
  // was opened has to be pulled in deliberately.
  const refresh = useMutation({
    mutationFn: () => payrollAPI.refresh(id),
    onSuccess: (res) => {
      toast.success(res?.data?.message || "Refreshed.");
      applyRun(res);
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not refresh this month."),
  });

  const lock = useMutation({
    mutationFn: (finalise) => (finalise ? payrollAPI.finalise(id) : payrollAPI.reopen(id)),
    onSuccess: (res) => {
      toast.success(res?.data?.message || "Done.");
      applyRun(res);
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not change the status."),
  });

  const [downloading, setDownloading] = useState(null);

  const getPdf = async (kind, line) => {
    setDownloading(kind === "summary" ? "summary" : line.id);
    try {
      const res = kind === "summary" ? await payrollAPI.summaryPdf(id) : await payrollAPI.payslipPdf(id, line.id);
      downloadBlob(
        res.data,
        kind === "summary"
          ? `payroll-summary-${run.period.slice(0, 7)}.pdf`
          : `${line.code}-payslip-${run.period.slice(0, 7)}.pdf`
      );
    } catch {
      toast.error("Could not produce the PDF.");
    } finally {
      setDownloading(null);
    }
  };

  const askFinalise = async () => {
    const ok = await confirm({
      title: `Finalise ${run.period_label}?`,
      message: "The month is locked and its figures stop changing. You can reopen it later if you need to.",
      confirmText: "Finalise",
    });
    if (ok) lock.mutate(true);
  };

  const askRemoveLine = async (line) => {
    const ok = await confirm({
      title: `Take ${line.full_name} off ${run.period_label}?`,
      message: "Their employee record stays; only this month's line is removed.",
      confirmText: "Remove",
      tone: "danger",
    });
    if (ok) removeLine.mutate(line.id);
  };

  if (isLoading || !run) return <PageLoader label="Loading the month…" />;

  const locked = run.locked;
  const t = run.totals;
  const ccy = run.currency || "SGD";
  const flagged = run.lines.filter((l) => (l.review_flags || []).length > 0);

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <Link href="/" className="mb-1 inline-flex items-center gap-1 text-xs text-slate-500 hover:text-[#28364b]">
            <ArrowLeft className="h-3.5 w-3.5" /> All payroll months
          </Link>
          <h1 className="flex items-center gap-3 text-xl font-bold text-[#28364b]">
            {run.period_label}
            <StatusPill status={run.status} />
          </h1>
          <p className="mt-0.5 text-sm text-slate-500">
            {t.headcount} employee(s) · paid {dateOf(run.payment_date)} · figures in {ccy}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {!locked && (
            <button onClick={() => refresh.mutate()} disabled={refresh.isLoading} className={btn.ghost}
              title="Pull corrected employee details — date of birth, CPF scheme, salary — into this month">
              {refresh.isLoading ? <Spinner className="h-4 w-4" /> : <RefreshCw className="h-4 w-4" />} Refresh from employees
            </button>
          )}
          <button onClick={() => getPdf("summary")} disabled={downloading === "summary"} className={btn.ghost}>
            {downloading === "summary" ? <Spinner className="h-4 w-4" /> : <FileText className="h-4 w-4" />} Summary PDF
          </button>
          {locked ? (
            <button onClick={() => lock.mutate(false)} disabled={lock.isLoading} className={btn.ghost}>
              <Unlock className="h-4 w-4" /> Reopen
            </button>
          ) : (
            <button onClick={askFinalise} disabled={lock.isLoading} className={btn.primary}>
              {lock.isLoading ? <Spinner className="h-4 w-4" /> : <Lock className="h-4 w-4" />} Finalise
            </button>
          )}
        </div>
      </div>

      {/* Totals */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Gross Earnings" value={money(t.gross_earnings)} currency={ccy} icon={Wallet} />
        <StatCard label="Total Deductions" value={money(t.total_deductions)} currency={ccy} hint={`CPF ${money(t.employee_cpf)} · SHG ${money(t.shg_deduction)}`} icon={Receipt} />
        <StatCard label="Total Net Pay" value={money(t.net_salary)} currency={ccy} tone="navy" icon={Landmark} />
        <StatCard label="Total Employer Cost" value={money(t.total_employer_cost)} currency={ccy} hint={`Employer CPF ${money(t.employer_cpf)} · SDL ${money(t.sdl)}`} icon={Building2} />
      </div>

      {locked && (
        <div className="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3 text-sm text-emerald-800">
          <Lock className="mt-0.5 h-4 w-4 shrink-0" />
          <span>This month is finalised, so the figures are fixed. Reopen it if something needs changing.</span>
        </div>
      )}

      {flagged.length > 0 && (
        <div className="rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-sm text-amber-900">
          <div className="mb-1 flex items-center gap-2 font-semibold">
            <AlertTriangle className="h-4 w-4" /> Worth a look before finalising
          </div>
          <ul className="space-y-0.5 pl-6 text-[13px] leading-relaxed">
            {flagged.map((l) =>
              (l.review_flags || []).map((f, i) => (
                <li key={`${l.id}-${i}`} className="list-disc">
                  <strong>{l.code}</strong> {l.full_name} — {f}
                </li>
              ))
            )}
          </ul>
          {!locked && (
            <p className="mt-2 pl-6 text-[12.5px] text-amber-800/80">
              Corrected something on the employee record since this month was opened? Use{" "}
              <strong>Refresh from employees</strong> above to bring it in.
            </p>
          )}
        </div>
      )}

      {/* The grid */}
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full min-w-[1500px] text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-[10px] uppercase tracking-wide text-slate-400">
              <th colSpan={2} />
              <th colSpan={INPUTS.length} className="bg-sky-50/70 px-2 py-1.5 text-center font-bold text-sky-700">
                Typed each month
              </th>
              <th colSpan={5} className="px-2 py-1.5 text-center font-bold text-slate-500">
                Calculated
              </th>
              <th colSpan={4} className="bg-slate-50 px-2 py-1.5 text-center font-bold text-slate-500">
                Employer cost
              </th>
            </tr>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
              <th className="px-3 py-2.5">ID</th>
              <th className="px-3 py-2.5">Employee</th>
              {INPUTS.map((c) => (
                <th key={c.key} className="bg-sky-50/70 px-2 py-2.5 text-right">
                  {c.label}
                </th>
              ))}
              <th className="px-2 py-2.5 text-right">Gross</th>
              <th className="px-2 py-2.5 text-right">Emp. CPF</th>
              <th className="px-2 py-2.5 text-right">SHG</th>
              <th className="px-2 py-2.5 text-right">Total Ded.</th>
              <th className="px-3 py-2.5 text-right text-[#28364b]">Net Pay</th>
              <th className="bg-slate-50 px-2 py-2.5 text-right">Empr. CPF</th>
              <th className="bg-slate-50 px-2 py-2.5 text-right">SDL</th>
              <th className="bg-slate-50 px-2 py-2.5 text-right">Total Cost</th>
              <th className="bg-slate-50 px-2 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {run.lines.map((line) => (
              <tr key={line.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/40">
                <td className="px-3 py-2 font-semibold text-[#28364b]">{line.code}</td>
                <td className="px-3 py-2">
                  <div className="whitespace-nowrap font-medium text-[#28364b]">{line.full_name}</div>
                  <div className="text-[11px] text-slate-400">
                    {line.cpf_scheme} · {line.shg_fund}
                  </div>
                </td>

                {INPUTS.map((c) => (
                  <td key={c.key} className="bg-sky-50/40 px-1 py-1.5">
                    <NumberCell
                      value={line[c.key]}
                      disabled={locked}
                      onCommit={(value) => saveCell.mutate({ lineId: line.id, key: c.key, value })}
                    />
                  </td>
                ))}

                <td className="px-2 py-2 text-right font-semibold text-[#28364b]">{money(line.gross_earnings)}</td>
                <td className={`px-2 py-2 text-right ${dim(line.employee_cpf)}`}>{money(line.employee_cpf)}</td>
                <td className={`px-2 py-2 text-right ${dim(line.shg_deduction)}`}>{money(line.shg_deduction)}</td>
                <td className={`px-2 py-2 text-right ${dim(line.total_deductions)}`}>{money(line.total_deductions)}</td>
                <td className="px-3 py-2 text-right text-[15px] font-bold text-[#28364b]">{money(line.net_salary)}</td>
                <td className={`bg-slate-50/60 px-2 py-2 text-right ${dim(line.employer_cpf)}`}>{money(line.employer_cpf)}</td>
                <td className={`bg-slate-50/60 px-2 py-2 text-right ${dim(line.sdl)}`}>{money(line.sdl)}</td>
                <td className="bg-slate-50/60 px-2 py-2 text-right text-slate-600">{money(line.total_employer_cost)}</td>
                <td className="bg-slate-50/60 px-2 py-2">
                  <div className="flex justify-end gap-1">
                    <button
                      onClick={() => getPdf("payslip", line)}
                      disabled={downloading === line.id}
                      title="Download payslip"
                      className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-200 hover:text-[#28364b]"
                    >
                      {downloading === line.id ? <Spinner className="h-4 w-4" /> : <Download className="h-4 w-4" />}
                    </button>
                    {!locked && (
                      <button
                        onClick={() => askRemoveLine(line)}
                        title="Take off this month"
                        className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}

            <tr className="bg-slate-50 font-bold text-[#28364b]">
              <td className="px-3 py-3" colSpan={2}>
                TOTAL
              </td>
              <td className="px-2 py-3" colSpan={INPUTS.length} />
              <td className="px-2 py-3 text-right">{money(t.gross_earnings)}</td>
              <td className="px-2 py-3 text-right">{money(t.employee_cpf)}</td>
              <td className="px-2 py-3 text-right">{money(t.shg_deduction)}</td>
              <td className="px-2 py-3 text-right">{money(t.total_deductions)}</td>
              <td className="px-3 py-3 text-right text-[15px]">{money(t.net_salary)}</td>
              <td className="px-2 py-3 text-right">{money(t.employer_cpf)}</td>
              <td className="px-2 py-3 text-right">{money(t.sdl)}</td>
              <td className="px-2 py-3 text-right">{money(t.total_employer_cost)}</td>
              <td />
            </tr>
          </tbody>
        </table>
      </div>

      {/* Month settings and late joiners */}
      {!locked && (
        <div className="grid gap-3 lg:grid-cols-2">
          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="text-sm font-bold text-[#28364b]">Add someone to this month</h2>
            <p className="mt-0.5 text-xs text-slate-500">
              For a joiner who was not on the list when the month was opened.
            </p>
            <div className="mt-3 flex gap-2">
              <Select
                className="flex-1"
                value={adding}
                onChange={setAdding}
                placeholder="Choose an employee…"
                options={(run.available_employees || []).map((e) => ({
                  value: String(e.id),
                  label: `${e.code} — ${e.full_name}`,
                }))}
              />
              <button
                onClick={() => addLine.mutate(Number(adding))}
                disabled={!adding || addLine.isLoading}
                className={btn.ghost}
              >
                {addLine.isLoading ? <Spinner className="h-4 w-4" /> : <Plus className="h-4 w-4" />} Add
              </button>
            </div>
            {(run.available_employees || []).length === 0 && (
              <p className="mt-2 text-xs text-slate-400">Every active employee is already on this month.</p>
            )}
          </div>

          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="text-sm font-bold text-[#28364b]">Month details</h2>
            <div className="mt-3 grid gap-2 sm:grid-cols-2">
              <div className="flex flex-col gap-1">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Payment date</span>
                <DatePicker
                  value={run.payment_date || ""}
                  onChange={(value) => {
                    if (value !== (run.payment_date || "")) saveHeader.mutate({ payment_date: value || null });
                  }}
                  placeholder="Pick a date"
                />
              </div>
              <label className="flex flex-col gap-1">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Notes</span>
                <input
                  defaultValue={run.notes || ""}
                  onBlur={(e) => {
                    if (e.target.value !== (run.notes || "")) saveHeader.mutate({ notes: e.target.value || null });
                  }}
                  className={inputCls}
                />
              </label>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * A figure in the grid. Held locally while it is being typed and sent once on
 * blur, so a recalculation of the whole month does not fire on every keystroke.
 */
function NumberCell({ value, disabled, onCommit }) {
  const [draft, setDraft] = useState(null);
  const shown = draft ?? (Number(value) === 0 ? "" : String(Number(value)));

  return (
    <input
      type="number"
      step="0.01"
      min="0"
      disabled={disabled}
      placeholder="0.00"
      value={shown}
      onChange={(e) => setDraft(e.target.value)}
      onFocus={(e) => e.target.select()}
      onKeyDown={(e) => {
        if (e.key === "Enter") e.currentTarget.blur();
      }}
      onBlur={() => {
        if (draft === null) return;
        const next = Number(draft) || 0;
        setDraft(null);
        if (next !== Number(value)) onCommit(next);
      }}
      className={cellCls}
    />
  );
}