import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { motion } from "framer-motion";
import { Hash, Info, History, Check, Lock } from "lucide-react";
import { toast } from "sonner";
import { documentSeriesAPI } from "@/pages/api";
import { PageLoader, Spinner } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";

/**
 * Where each document series continues from.
 *
 * A number can only ever move FORWARD. The server is what enforces that — this
 * screen simply makes the rule visible, so nobody types a number that is going
 * to be rejected and wonders why.
 */
export default function DocumentSeries() {
  const qc = useQueryClient();
  const confirm = useConfirm();

  const { data, isLoading } = useQuery({
    queryKey: ["document-series"],
    queryFn: async () => (await documentSeriesAPI.list()).data.data,
  });

  if (isLoading || !data) return <PageLoader />;

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Document numbering</h1>
        <p className="mt-0.5 text-sm text-slate-500">
          Set where each series continues from. The year is stamped automatically and rolls over on its own each January.
        </p>
      </div>

      <div className="flex items-start gap-2.5 rounded-xl border border-blue-200 bg-blue-50 p-3.5 text-sm text-blue-900">
        <Info className="mt-0.5 h-4 w-4 shrink-0 text-blue-600" />
        <p>
          Numbers can only move <b>forward</b>. A series cannot be set back onto a number already printed on a document —
          that number is unique, and the next document would fail to save. Skipping ahead is fine; the skipped numbers are
          simply never used.
        </p>
      </div>

      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        {data.series.map((s) => (
          <SeriesCard key={s.key} series={s} confirm={confirm} qc={qc} />
        ))}
      </div>

      {data.history.length > 0 && (
        <div>
          <div className="mb-1.5 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-400">
            <History className="h-3.5 w-3.5" /> Recent changes
          </div>
          <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table className="w-full text-sm">
              <tbody>
                {data.history.map((h) => (
                  <tr key={h.id} className="border-b border-slate-100 last:border-0">
                    <td className="px-4 py-2.5 font-medium text-[#28364b]">{h.label}</td>
                    <td className="px-3 py-2.5 text-xs text-slate-500">
                      <span className="text-slate-400 line-through">{h.from}</span>
                      <span className="mx-1.5 text-slate-300">→</span>
                      <span className="font-semibold text-[#28364b]">{h.to}</span>
                    </td>
                    <td className="px-3 py-2.5 text-xs text-slate-500">{h.reason || <span className="text-slate-300">no reason given</span>}</td>
                    <td className="whitespace-nowrap px-4 py-2.5 text-right text-xs text-slate-400">
                      {h.by || "—"} · {h.at}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </motion.div>
  );
}

function SeriesCard({ series, confirm, qc }) {
  const [value, setValue] = useState("");
  const [reason, setReason] = useState("");

  const typed = parseInt(value, 10);
  const dirty = value !== "" && Number.isFinite(typed) && typed !== series.next_seq;
  const tooLow = value !== "" && Number.isFinite(typed) && typed < series.minimum;
  const skipping = dirty && !tooLow ? typed - series.next_seq : 0;

  const save = useMutation({
    mutationFn: () => documentSeriesAPI.setNext(series.key, { next_number: typed, reason: reason || null }),
    onSuccess: (res) => {
      toast.success(res.data.message);
      setValue("");
      setReason("");
      qc.invalidateQueries({ queryKey: ["document-series"] });
    },
    onError: (e) => {
      const errors = e?.response?.data?.errors;
      toast.error(errors?.next_number?.[0] || e?.response?.data?.message || "Could not change the number.");
    },
  });

  const apply = async () => {
    if (tooLow || !dirty) return;
    const ok = await confirm({
      title: `Change the ${series.label.toLowerCase()} number?`,
      message:
        `The next one will be ${series.prefix}-…-${String(typed).padStart(6, "0").slice(-6)} instead of ${series.next_number}.` +
        (skipping > 0 ? `\n\n${skipping.toLocaleString()} number${skipping === 1 ? "" : "s"} will be skipped and never used.` : "") +
        "\n\nThis cannot be undone — the series can never be moved back.",
      confirmText: "Change it",
      tone: "danger",
    });
    if (ok) save.mutate();
  };

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="flex items-center gap-1.5 text-sm font-semibold text-[#28364b]">
            <Hash className="h-3.5 w-3.5 text-slate-300" />
            {series.label}
          </div>
          <div className="mt-1 font-mono text-[13px] font-semibold text-[#28364b]">{series.next_number}</div>
          <div className="text-[11px] text-slate-400">next to be issued</div>
        </div>
        {series.highest_issued > 0 && (
          <div className="shrink-0 text-right">
            <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-300">Last used</div>
            <div className="font-mono text-[11px] text-slate-500">{series.last_number}</div>
          </div>
        )}
      </div>

      <div className="mt-3 border-t border-slate-100 pt-3">
        <label className="mb-1 block text-[11px] font-semibold text-slate-500">
          Continue from
          <span className="ml-1 font-normal text-slate-400">· {series.minimum.toLocaleString()} or higher</span>
        </label>
        <div className="flex gap-2">
          <input
            type="number"
            min={series.minimum}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder={String(series.next_seq)}
            className={`w-full rounded-lg border px-3 py-2 text-sm tabular-nums focus:outline-none focus:ring-1 ${
              tooLow
                ? "border-red-300 focus:border-red-400 focus:ring-red-300"
                : "border-slate-200 focus:border-[#28364b] focus:ring-[#28364b]"
            }`}
          />
          <button
            onClick={apply}
            disabled={!dirty || tooLow || save.isLoading}
            className="inline-flex shrink-0 items-center gap-1 rounded-lg bg-[#28364b] px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-40"
          >
            {save.isLoading ? <Spinner className="h-3.5 w-3.5" /> : <Check className="h-3.5 w-3.5" />} Set
          </button>
        </div>

        {dirty && !tooLow && (
          <input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Reason (optional) — kept in the change log"
            className="mt-2 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-xs focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
          />
        )}

        {tooLow ? (
          <p className="mt-1.5 flex items-start gap-1 text-[11px] font-medium text-red-600">
            <Lock className="mt-px h-3 w-3 shrink-0" />
            {series.minimum.toLocaleString()} is the lowest available — everything below it is already on a document.
          </p>
        ) : skipping > 0 ? (
          <p className="mt-1.5 text-[11px] text-amber-600">
            Skips {skipping.toLocaleString()} number{skipping === 1 ? "" : "s"}.
          </p>
        ) : series.behind ? (
          <p className="mt-1.5 text-[11px] text-slate-400">
            The counter was behind the documents already issued; it has been read forward for you.
          </p>
        ) : null}
      </div>
    </div>
  );
}
