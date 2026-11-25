import { useEffect, useMemo, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useRoute } from "wouter";
import { ArrowLeft, Save, Lock, Search, Info } from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { qty, btn, inputCls, numCls, dateOf } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";

/**
 * The stocktake sheet for one period.
 *
 * The crew types the counted ending stock; consumption per line is shown live
 * so an obviously wrong count is easy to spot before the period is closed.
 */
export default function PeriodPage() {
  const [, params] = useRoute("/periods/:id");
  const id = params?.id;
  const qc = useQueryClient();
  const confirm = useConfirm();

  const [counts, setCounts] = useState({});   // item_id -> typed ending stock
  const [search, setSearch] = useState("");
  const [onlyMoved, setOnlyMoved] = useState(true);
  const [openNext, setOpenNext] = useState(true);

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "period", id],
    queryFn: async () => (await inventoryAPI.period(id)).data.data,
    enabled: !!id,
  });

  useEffect(() => {
    if (!data) return;
    const seed = {};
    for (const l of data.lines) seed[l.item_id] = l.ending_qty;
    setCounts(seed);
  }, [data]);

  const period = data?.period;
  const closed = period?.status === "closed";

  const lines = useMemo(() => {
    let rows = data?.lines ?? [];
    const t = search.trim().toLowerCase();

    if (onlyMoved && !t) {
      // Most of a 900-item catalogue never moves in a period. Default to the
      // lines that actually have something to count.
      rows = rows.filter(
        (l) => Number(l.beginning_qty) > 0 || Number(l.supplied_qty) > 0 || Number(counts[l.item_id] || 0) > 0
      );
    }

    if (t) {
      rows = rows.filter(
        (l) => l.name.toLowerCase().includes(t) || l.code.toLowerCase().includes(t) || (l.variant || "").toLowerCase().includes(t)
      );
    }

    return rows;
  }, [data, search, onlyMoved, counts]);

  const saveCounts = useMutation({
    mutationFn: () =>
      inventoryAPI.saveCounts(
        id,
        Object.entries(counts).map(([item_id, ending_qty]) => ({
          item_id: Number(item_id),
          ending_qty: ending_qty === "" ? 0 : Number(ending_qty),
        }))
      ),
    onSuccess: () => {
      toast.success("Counts saved — the period is still open.");
      qc.invalidateQueries({ queryKey: ["inv", "period", id] });
    },
    onError: () => toast.error("Could not save the counts."),
  });

  const close = useMutation({
    mutationFn: () =>
      inventoryAPI.closePeriod(id, {
        lines: Object.entries(counts).map(([item_id, ending_qty]) => ({
          item_id: Number(item_id),
          ending_qty: ending_qty === "" ? 0 : Number(ending_qty),
        })),
        apply_to_stock: true,
        open_next: openNext,
      }),
    onSuccess: (res) => {
      toast.success(res.data.message);
      qc.invalidateQueries();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not close the period."),
  });

  const askClose = async () => {
    const ok = await confirm({
      title: "Close this period",
      message: openNext
        ? "The counted quantities become the stock on board, consumption is worked out from them, and the next period opens straight away. This cannot be undone."
        : "The counted quantities become the stock on board and consumption is worked out from them. No new period opens, so deliveries will have nowhere to be recorded until you start one. This cannot be undone.",
      confirmText: "Close period",
    });
    if (ok) close.mutate();
  };

  if (isLoading) return <PageLoader label="Loading the stocktake…" />;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Link href="/periods" className="rounded-lg p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]">
            <ArrowLeft className="h-4 w-4" />
          </Link>
          <div>
            <h1 className="text-xl font-bold text-[#28364b]">{period?.label || `Period #${id}`}</h1>
            <p className="text-sm text-slate-500">
              {dateOf(period?.start_date)} → {period?.end_date ? dateOf(period.end_date) : "open"} · {period?.days} days
              {closed ? " · closed" : ""}
            </p>
          </div>
        </div>
        {!closed && (
          <div className="flex flex-wrap items-center gap-2">
            {/* Normally the next period should open straight away, or a delivery
                confirmed tomorrow has no period to be recorded against. Worth
                declining when the vessel is laid up, or when closing several in
                a row by hand. */}
            <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
              <input
                type="checkbox"
                checked={openNext}
                onChange={(e) => setOpenNext(e.target.checked)}
                className="h-3.5 w-3.5 accent-[#28364b]"
              />
              Open the next period
            </label>
            <button onClick={() => saveCounts.mutate()} disabled={saveCounts.isLoading} className={btn.ghost}>
              {saveCounts.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save counts
            </button>
            <button onClick={askClose} disabled={close.isLoading} className={btn.primary}>
              {close.isLoading ? <Spinner className="h-4 w-4" /> : <Lock className="h-4 w-4" />} Close period
            </button>
          </div>
        )}
      </div>

      <div className="flex items-start gap-2 rounded-lg border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500">
        <Info className="mt-0.5 h-4 w-4 shrink-0 text-[#cebd88]" />
        <p>
          Consumption is <strong>beginning stock + supplied − ending stock</strong>. Beginning stock was captured when the
          period opened and supplied comes from confirmed deliveries, so the only thing to type is what is physically left
          on board.
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[240px] flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Find an item to count…"
            className={`${inputCls} w-full pl-9`}
          />
        </div>
        <label className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600">
          <input type="checkbox" checked={onlyMoved} onChange={(e) => setOnlyMoved(e.target.checked)} className="accent-[#28364b]" />
          Only items with stock or deliveries
        </label>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">Code</th>
                <th className="px-4 py-2 font-semibold">Item</th>
                <th className="px-2 py-2 font-semibold">Unit</th>
                <th className="px-2 py-2 text-right font-semibold">Beginning</th>
                <th className="px-2 py-2 text-right font-semibold">Supplied</th>
                <th className="px-2 py-2 text-right font-semibold">Ending count</th>
                <th className="px-4 py-2 text-right font-semibold">Consumed</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((l) => {
                const ending = counts[l.item_id] ?? l.ending_qty;
                const consumed = Number(l.beginning_qty) + Number(l.supplied_qty) - Number(ending || 0);
                const negative = consumed < 0;

                return (
                  <tr key={l.item_id} className="border-t border-slate-100">
                    <td className="whitespace-nowrap px-4 py-2 font-mono text-xs text-slate-400">{l.code}</td>
                    <td className="px-4 py-2">
                      <span className="font-medium text-[#28364b]">{l.name}</span>
                      {l.variant && <span className="ml-1 text-xs text-slate-400">{l.variant}</span>}
                    </td>
                    <td className="px-2 py-2 text-xs text-slate-500">{l.unit}</td>
                    <td className="px-2 py-2 text-right text-slate-500">{qty(l.beginning_qty)}</td>
                    <td className="px-2 py-2 text-right text-slate-500">{qty(l.supplied_qty)}</td>
                    <td className="px-2 py-2 text-right">
                      <input
                        type="number"
                        min="0"
                        step="any"
                        disabled={closed}
                        value={ending}
                        onChange={(e) => setCounts((c) => ({ ...c, [l.item_id]: e.target.value }))}
                        className={`${numCls} w-24 py-1 disabled:bg-slate-50`}
                      />
                    </td>
                    <td className={`px-4 py-2 text-right font-semibold ${negative ? "text-red-600" : "text-[#28364b]"}`}>
                      {qty(consumed)}
                      {negative && <span className="ml-1 text-[10px] font-normal">check count</span>}
                    </td>
                  </tr>
                );
              })}
              {!lines.length && (
                <tr>
                  <td colSpan={7} className="px-4 py-16 text-center text-sm text-slate-400">
                    Nothing to count here. Untick the filter to see the whole catalogue.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
