import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link } from "wouter";
import { CalendarRange, Play, ArrowRight, Trash2, Save } from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { dateOf, btn, inputCls, numCls } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";
import Modal from "@/portal/ui/Modal";
import DatePicker from "@/portal/ui/DatePicker";

const today = () => new Date().toISOString().slice(0, 10);

/**
 * Supply periods. Closing one records the counted ending stock, which turns
 * "beginning + supplied - ending" into the measured consumption that drives
 * every order quantity.
 */
export default function Periods() {
  const qc = useQueryClient();
  const confirm = useConfirm();

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "periods"],
    queryFn: async () => (await inventoryAPI.periods()).data.data,
  });

  // Blank fields fall back to the vessel's own settings on the server, so the
  // usual case stays one click: open the form, press Start.
  const [form, setForm] = useState(null);

  const open = useMutation({
    mutationFn: () =>
      inventoryAPI.openPeriod({
        label: form.label.trim() || null,
        start_date: form.start_date || null,
        days: form.days === "" ? null : Number(form.days),
      }),
    onSuccess: () => {
      toast.success("Period opened — today's stock is its starting point.");
      setForm(null);
      qc.invalidateQueries({ queryKey: ["inv", "periods"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not open a period."),
  });

  const remove = useMutation({
    mutationFn: (id) => inventoryAPI.removePeriod(id),
    onSuccess: () => {
      toast.success("Period deleted.");
      qc.invalidateQueries({ queryKey: ["inv", "periods"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not delete."),
  });

  const askRemove = async (p) => {
    const ok = await confirm({
      title: "Delete period",
      message: `Delete ${p.label || "this period"}? Only an open period can be deleted.`,
      confirmText: "Delete",
      tone: "danger",
    });
    if (ok) remove.mutate(p.id);
  };

  if (isLoading) return <PageLoader label="Loading supply periods…" />;

  const periods = data?.periods ?? [];
  const openPeriod = data?.open;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-[#28364b]">Supply periods</h1>
          <p className="text-sm text-slate-500">
            A period runs from one supply to the next. Closing it with a stock count is what makes consumption measured
            instead of guessed.
          </p>
        </div>
        {!openPeriod && (
          <button
            onClick={() => setForm({ label: "", start_date: today(), days: "" })}
            className={btn.primary}
          >
            <Play className="h-4 w-4" /> Start a period
          </button>
        )}
      </div>

      {openPeriod && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#cebd88] bg-[#cebd88]/10 p-4">
          <div>
            <p className="text-sm font-bold text-[#28364b]">{openPeriod.label} is open</p>
            <p className="text-xs text-slate-500">
              Started {dateOf(openPeriod.start_date)} · deliveries recorded in Receiving are added to it automatically.
            </p>
          </div>
          <Link href={`/periods/${openPeriod.id}`} className={btn.primary}>
            Count &amp; close <ArrowRight className="h-4 w-4" />
          </Link>
        </div>
      )}

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">Period</th>
                <th className="px-2 py-2 font-semibold">Start</th>
                <th className="px-2 py-2 font-semibold">End</th>
                <th className="px-2 py-2 text-right font-semibold">Days</th>
                <th className="px-2 py-2 text-right font-semibold">Crew</th>
                <th className="px-2 py-2 font-semibold">Status</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              {periods.map((p) => (
                <tr key={p.id} className="border-t border-slate-100 transition-colors hover:bg-slate-50">
                  <td className="px-4 py-2">
                    <Link href={`/periods/${p.id}`} className="font-semibold text-[#28364b] hover:underline">
                      {p.label || `Period #${p.id}`}
                    </Link>
                  </td>
                  <td className="px-2 py-2 text-slate-500">{dateOf(p.start_date)}</td>
                  <td className="px-2 py-2 text-slate-500">{dateOf(p.end_date)}</td>
                  <td className="px-2 py-2 text-right text-slate-500">{p.days}</td>
                  <td className="px-2 py-2 text-right text-slate-500">{p.crew_size ?? "—"}</td>
                  <td className="px-2 py-2">
                    <span
                      className={`rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ${
                        p.status === "closed"
                          ? "bg-slate-100 text-slate-500 ring-slate-200"
                          : "bg-emerald-50 text-emerald-700 ring-emerald-200"
                      }`}
                    >
                      {p.status}
                    </span>
                  </td>
                  <td className="px-4 py-2">
                    <div className="flex items-center justify-end gap-1">
                      {p.status === "open" && (
                        <button
                          onClick={() => askRemove(p)}
                          className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                          title="Delete"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      )}
                      <Link
                        href={`/periods/${p.id}`}
                        className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                        title="Open"
                      >
                        <ArrowRight className="h-4 w-4" />
                      </Link>
                    </div>
                  </td>
                </tr>
              ))}
              {!periods.length && (
                <tr>
                  <td colSpan={7} className="px-4 py-16 text-center text-sm text-slate-400">
                    <CalendarRange className="mx-auto mb-2 h-8 w-8 text-slate-200" />
                    No periods yet. Until one is closed, ordering uses the usage figures from the vessel's workbook.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      <Modal open={!!form} onClose={() => setForm(null)} title="Start a supply period" maxWidth="max-w-lg">
        {form && (
          <form
            className="space-y-4 p-6"
            onSubmit={(e) => {
              e.preventDefault();
              open.mutate();
            }}
          >
            <p className="text-sm text-slate-500">
              The stock on board today becomes this period's starting point, and every delivery confirmed in Receiving is
              added to it.
            </p>

            <div className="grid gap-4 sm:grid-cols-2">
              <label className="block">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Starts</span>
                <div className="mt-1">
                  <DatePicker
                    value={form.start_date}
                    onChange={(v) => setForm((f) => ({ ...f, start_date: v }))}
                  />
                </div>
              </label>
              <label className="block">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Days</span>
                <input
                  type="number"
                  min="1"
                  max="365"
                  value={form.days}
                  onChange={(e) => setForm((f) => ({ ...f, days: e.target.value }))}
                  placeholder="Vessel default"
                  className={`${numCls} mt-1 w-full`}
                />
              </label>
            </div>

            <label className="block">
              <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Name</span>
              <input
                value={form.label}
                onChange={(e) => setForm((f) => ({ ...f, label: e.target.value }))}
                placeholder="Named after the month it starts in"
                className={`${inputCls} mt-1 w-full`}
              />
              <span className="mt-1 block text-xs text-slate-400">
                Worth setting if more than one period starts in the same month — otherwise they all read alike in the
                list.
              </span>
            </label>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setForm(null)} className={btn.ghost}>
                Cancel
              </button>
              <button type="submit" disabled={open.isLoading} className={btn.primary}>
                {open.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Start period
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
}
