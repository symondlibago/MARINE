import { useState } from "react";
import { useLocation } from "wouter";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { CalendarRange, Plus, ChevronRight, Trash2, Save, Users } from "lucide-react";
import { toast } from "sonner";
import { payrollAPI } from "./api";
import { dateOf, monthOf, btn, inputCls, StatusPill } from "./lib";
import DatePicker from "./DatePicker";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";
import Modal from "@/portal/ui/Modal";
import Select from "@/portal/ui/Select";

const MONTHS = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December",
].map((label, i) => ({ value: String(i + 1).padStart(2, "0"), label }));

/** A window either side of today — far enough for a late catch-up or a run ahead. */
const YEARS = (() => {
  const now = new Date().getFullYear();
  return [now - 2, now - 1, now, now + 1].map((y) => ({ value: String(y), label: String(y) }));
})();

/**
 * Every payroll month, newest first. Opening a month copies the active
 * employees onto it; from then on that month stands on its own.
 */
export default function Runs() {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const [, navigate] = useLocation();
  const [form, setForm] = useState(null);

  const { data, isLoading } = useQuery({
    queryKey: ["pay", "runs"],
    queryFn: async () => (await payrollAPI.runs()).data.data,
  });

  const runs = data?.runs ?? [];
  const currency = data?.settings?.currency ?? "SGD";

  const create = useMutation({
    mutationFn: () =>
      payrollAPI.createRun({
        period: `${form.year}-${form.month}-01`,
        payment_date: form.payment_date || null,
        notes: form.notes?.trim() || null,
      }),
    onSuccess: (res) => {
      toast.success(res?.data?.message || "Month opened.");
      setForm(null);
      qc.invalidateQueries({ queryKey: ["pay", "runs"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not open that month."),
  });

  const remove = useMutation({
    mutationFn: (id) => payrollAPI.removeRun(id),
    onSuccess: (res) => {
      toast.success(res?.data?.message || "Deleted.");
      qc.invalidateQueries({ queryKey: ["pay", "runs"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not delete that month."),
  });

  const askRemove = async (run) => {
    const ok = await confirm({
      title: `Delete ${run.period_label}?`,
      message: "This draft month and all its lines will be removed. Employees are not affected.",
      confirmText: "Delete",
      tone: "danger",
    });
    if (ok) remove.mutate(run.id);
  };

  if (isLoading) return <PageLoader label="Loading payroll months…" />;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-bold text-[#28364b]">
            <CalendarRange className="h-5 w-5 text-[#cebd88]" /> Payroll Months
          </h1>
          <p className="mt-0.5 text-sm text-slate-500">
            {data?.active_employees ?? 0} active employee(s) · figures in {currency}
          </p>
        </div>
        <button
          onClick={() => {
            const now = new Date();
            setForm({
              month: String(now.getMonth() + 1).padStart(2, "0"),
              year: String(now.getFullYear()),
              payment_date: "",
              notes: "",
            });
          }}
          className={btn.primary}
        >
          <Plus className="h-4 w-4" /> Open a month
        </button>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3">Month</th>
              <th className="px-4 py-3">Payment date</th>
              <th className="px-4 py-3 text-right">Employees</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody>
            {runs.map((r) => (
              // The whole row opens the month; only the delete button opts out.
              <tr
                key={r.id}
                onClick={() => navigate(`/runs/${r.id}`)}
                className="group cursor-pointer border-b border-slate-100 last:border-0 transition-colors hover:bg-slate-50"
              >
                <td className="px-4 py-3 font-semibold text-[#28364b]">{r.period_label}</td>
                <td className="px-4 py-3 text-slate-500">{dateOf(r.payment_date)}</td>
                <td className="px-4 py-3 text-right text-slate-500">{r.headcount}</td>
                <td className="px-4 py-3">
                  <StatusPill status={r.status} />
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center justify-end gap-1">
                    {r.status !== "finalised" && (
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          askRemove(r);
                        }}
                        className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                        title="Delete this draft month"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    )}
                    <ChevronRight className="h-4 w-4 text-slate-300 transition-colors group-hover:text-[#28364b]" />
                  </div>
                </td>
              </tr>
            ))}
            {runs.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-12 text-center">
                  <Users className="mx-auto mb-2 h-8 w-8 text-slate-200" />
                  <p className="text-slate-400">No payroll months yet.</p>
                  <p className="mt-1 text-xs text-slate-400">
                    Add your employees first, then open a month here.
                  </p>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <Modal open={!!form} onClose={() => setForm(null)} title="Open a payroll month">
        {form && (
          <form
            onSubmit={(ev) => {
              ev.preventDefault();
              create.mutate();
            }}
            className="space-y-4 px-6 py-5"
          >
            <p className="rounded-lg bg-slate-50 px-3 py-2.5 text-xs leading-relaxed text-slate-500">
              Every <strong>active</strong> employee is copied onto the month at their current salary. Editing an
              employee afterwards will not change a month already opened.
            </p>

            <div className="grid grid-cols-2 gap-3">
              <label className="flex flex-col gap-1">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Month</span>
                <Select
                  value={form.month}
                  onChange={(month) => setForm((f) => ({ ...f, month }))}
                  options={MONTHS}
                />
              </label>
              <label className="flex flex-col gap-1">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Year</span>
                <Select
                  value={form.year}
                  onChange={(year) => setForm((f) => ({ ...f, year }))}
                  options={YEARS}
                />
              </label>
            </div>

            <div className="flex flex-col gap-1">
              <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Payment date</span>
              <DatePicker
                value={form.payment_date}
                onChange={(payment_date) => setForm((f) => ({ ...f, payment_date }))}
                placeholder="Last day of the month"
              />
              <span className="text-[11px] text-slate-400">Left blank, the last day of the month is used.</span>
            </div>

            <label className="flex flex-col gap-1">
              <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Notes</span>
              <textarea
                rows={2}
                value={form.notes}
                onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
                className={`${inputCls} w-full`}
              />
            </label>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setForm(null)} className={btn.ghost}>
                Cancel
              </button>
              <button type="submit" disabled={create.isLoading} className={btn.primary}>
                {create.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Open{" "}
                {monthOf(`${form.year}-${form.month}-01`)}
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
}