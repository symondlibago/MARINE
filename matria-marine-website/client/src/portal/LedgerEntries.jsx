import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { motion } from "framer-motion";
import { Download, ChevronRight, Search, FileText, Landmark, Undo2, History } from "lucide-react";
import { toast } from "sonner";
import { reportsAPI } from "@/pages/api";
import { Spinner } from "./ui/Loading";
import DatePicker from "./ui/DatePicker";

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Everything that happened on every account, in date order, grouped by party.
 *
 * The counterpart to the open-entries view: that one answers "what is still
 * owed", this one answers "what took place" — invoices, credit notes and
 * payments alike, settled or not.
 */
export default function LedgerEntries({ type }) {
  const isCustomer = type === "customer";

  const [range, setRange] = useState({ from: "", to: "" });
  const [filter, setFilter] = useState("");
  const [collapsed, setCollapsed] = useState({});

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ["ledger-entries", type, range.from, range.to],
    queryFn: async () =>
      (await reportsAPI.ledgerEntries({
        type,
        ...(range.from ? { from: range.from } : {}),
        ...(range.to ? { to: range.to } : {}),
      })).data.data,
    keepPreviousData: true,
  });

  const parties = (data?.parties || []).filter((p) =>
    filter.trim() === "" ? true : p.name.toLowerCase().includes(filter.trim().toLowerCase())
  );

  const exportCsv = () => {
    const head = ["Party", "Date", "Type", "Document", "Reference", "Vessel", "Description",
      "Currency", "Amount", "Settled", "Remaining", "Due date", "Status", "Bank account", "Applied to"];
    const rows = [];
    for (const p of parties) {
      for (const e of p.entries) {
        rows.push([p.name, e.date || "", e.type, e.number || "", e.reference || "", e.vessel || "",
          e.description || "", e.currency, e.amount, e.settled || "", e.outstanding || "",
          e.due_date || "", e.status, e.bank_account || "", e.applied_to || ""]);
      }
      // A closing line per currency, so the spreadsheet reads like the NAV printout.
      for (const t of p.totals) {
        rows.push([p.name, "", "TOTAL", "", "", "", "", t.currency, t.billed, t.paid, t.outstanding, "", "", "", ""]);
      }
    }
    const csv = [head, ...rows]
      .map((r) => r.map((c) => `"${String(c ?? "").replace(/"/g, '""')}"`).join(","))
      .join("\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8;" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = `${isCustomer ? "Customer" : "Vendor"}-Ledger-Entries-${range.to || new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success("Ledger exported.");
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3">
        <label className="text-xs font-semibold text-slate-500">Period</label>
        <DatePicker value={range.from} onChange={(v) => setRange((r) => ({ ...r, from: v }))} placeholder="From" />
        <DatePicker value={range.to} onChange={(v) => setRange((r) => ({ ...r, to: v }))} placeholder="To" />
        {(range.from || range.to) && (
          <button onClick={() => setRange({ from: "", to: "" })} className="text-xs text-slate-400 hover:text-[#28364b] hover:underline">
            Clear
          </button>
        )}

        <div className="relative min-w-[180px] flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
          <input
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            placeholder="Narrow the list…"
            className="w-full rounded-lg border border-slate-200 py-2 pl-8 pr-3 text-xs focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
          />
        </div>

        <button
          onClick={exportCsv}
          disabled={parties.length === 0}
          className="inline-flex items-center gap-1.5 rounded-lg bg-[#28364b] px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-[#3c4a63] disabled:opacity-40"
        >
          <Download className="h-3.5 w-3.5" /> CSV
        </button>
      </div>

      <p className="text-[11px] text-slate-400">
        Every invoice, credit note and payment in date order — settled ones included.
        {range.from || range.to ? "" : " Showing all time; set a period to narrow it."}
      </p>

      {isLoading && !data ? (
        <div className="flex min-h-[240px] items-center justify-center"><Spinner className="h-6 w-6" /></div>
      ) : (data?.party_count || 0) === 0 ? (
        <div className="flex min-h-[240px] flex-col items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white p-10 text-center">
          <History className="h-8 w-8 text-slate-200" />
          <p className="text-sm text-slate-400">No activity in this period.</p>
        </div>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {(data?.grand_totals || []).map((g) => (
              <div key={g.currency} className="rounded-xl border border-slate-200 bg-white p-3.5">
                <div className="flex items-baseline justify-between">
                  <span className="text-xs font-bold uppercase tracking-wider text-slate-400">{g.currency}</span>
                  <span className="text-[11px] text-slate-400">{g.parties} {type}{g.parties === 1 ? "" : "s"}</span>
                </div>
                <div className="mt-1.5 text-2xl font-bold tabular-nums text-[#28364b]">{money(g.billed)}</div>
                <div className="text-[11px] font-medium text-slate-400">{isCustomer ? "billed" : "ordered"}</div>
                <div className="mt-2 flex flex-wrap justify-between gap-x-3 border-t border-slate-100 pt-2 text-[11px] text-slate-500">
                  <span>{isCustomer ? "Received" : "Paid"} <b className="tabular-nums text-green-700">{money(g.paid)}</b></span>
                  {g.credited > 0 && <span>Credited <b className="tabular-nums text-red-600">{money(g.credited)}</b></span>}
                  <span>Open <b className="tabular-nums text-[#28364b]">{money(g.outstanding)}</b></span>
                </div>
              </div>
            ))}
          </div>

          <div className="flex items-center justify-between text-xs text-slate-400">
            <span>
              {parties.length} {type}{parties.length === 1 ? "" : "s"}
              {filter && data?.party_count !== parties.length && <span className="text-slate-300"> of {data.party_count}</span>}
              {" · "}{data?.row_count} row{data?.row_count === 1 ? "" : "s"}
            </span>
            {isFetching && <Spinner className="h-3.5 w-3.5 text-slate-300" />}
          </div>

          {data?.truncated && (
            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
              This is a very large ledger ({data.row_count.toLocaleString()} rows). It will still export, but narrowing the
              period will make the spreadsheet far easier to work with.
            </p>
          )}

          <div className="space-y-2">
            {parties.map((p) => (
              <PartyLedger
                key={p.id}
                party={p}
                isCustomer={isCustomer}
                expanded={!collapsed[p.id]}
                onToggle={() => setCollapsed((c) => ({ ...c, [p.id]: !c[p.id] }))}
              />
            ))}
          </div>
        </>
      )}
    </div>
  );
}

/** Icon and colour per movement, so the eye can scan the column. */
const ROW_STYLE = {
  invoice: { Icon: FileText, tone: "text-[#28364b]", amount: "text-[#28364b]" },
  po: { Icon: FileText, tone: "text-[#28364b]", amount: "text-[#28364b]" },
  credit: { Icon: Undo2, tone: "text-red-600", amount: "text-red-600" },
  payment: { Icon: Landmark, tone: "text-green-700", amount: "text-green-700" },
};

function PartyLedger({ party, isCustomer, expanded, onToggle }) {
  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <button
        onClick={onToggle}
        className="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left transition-colors hover:bg-slate-50"
      >
        <span className="flex min-w-0 items-center gap-2">
          <ChevronRight className={`h-4 w-4 shrink-0 text-slate-300 transition-transform ${expanded ? "rotate-90" : ""}`} />
          <span className="truncate text-sm font-semibold text-[#28364b]">{party.name}</span>
          <span className="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">
            {party.entries.length}
          </span>
        </span>
        <span className="flex shrink-0 flex-wrap justify-end gap-1.5">
          {party.totals.map((t) => (
            <span
              key={t.currency}
              className={`rounded px-1.5 py-0.5 text-[11px] font-semibold ${
                t.outstanding > 0.005 ? "bg-amber-50 text-amber-700" : "bg-slate-100 text-slate-500"
              }`}
            >
              {t.currency} {money(t.outstanding)} open
            </span>
          ))}
        </span>
      </button>

      {expanded && (
        <div className="overflow-x-auto border-t border-slate-100">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50/70 text-left text-[10px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">Date</th>
                <th className="px-3 py-2 font-semibold">Type</th>
                <th className="px-3 py-2 font-semibold">Document</th>
                <th className="px-3 py-2 font-semibold">Reference</th>
                <th className="px-3 py-2 text-right font-semibold">Amount</th>
                <th className="px-3 py-2 text-right font-semibold">Remaining</th>
                <th className="px-4 py-2 font-semibold">Status</th>
              </tr>
            </thead>
            <tbody>
              {party.entries.map((e, i) => {
                const style = ROW_STYLE[e.kind] || ROW_STYLE.invoice;
                const Icon = style.Icon;
                return (
                  <tr key={`${e.kind}-${e.number}-${i}`} className="border-b border-slate-50 last:border-0">
                    <td className="whitespace-nowrap px-4 py-2 text-xs text-slate-500">{e.date || "—"}</td>
                    <td className={`whitespace-nowrap px-3 py-2 text-xs font-medium ${style.tone}`}>
                      <span className="inline-flex items-center gap-1.5">
                        <Icon className="h-3.5 w-3.5 opacity-50" /> {e.type}
                      </span>
                    </td>
                    <td className="px-3 py-2 font-medium text-[#28364b]">
                      {e.number || "—"}
                      {e.vessel && <div className="text-[11px] font-normal text-slate-400">{e.vessel}</div>}
                    </td>
                    <td className="px-3 py-2 text-xs text-slate-500">
                      {e.reference || e.applied_to || "—"}
                      {e.description && <div className="text-[11px] text-slate-400">{e.description}</div>}
                      {e.bank_account && <div className="text-[11px] text-slate-400">{e.bank_account}</div>}
                    </td>
                    <td className={`whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums ${style.amount}`}>
                      {e.currency} {money(e.amount)}
                    </td>
                    <td className="whitespace-nowrap px-3 py-2 text-right text-xs tabular-nums text-slate-500">
                      {e.outstanding > 0.005 ? money(e.outstanding) : <span className="text-slate-300">—</span>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2">
                      <StatusChip status={e.status} />
                    </td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              {party.totals.map((t) => (
                <tr key={t.currency} className="border-t border-slate-200 bg-slate-50/70 text-xs">
                  <td colSpan={4} className="px-4 py-2 text-right font-semibold text-slate-500">
                    {t.currency} — {isCustomer ? "billed" : "ordered"} {money(t.billed)}
                    {t.credited > 0 && `, credited ${money(t.credited)}`}
                    {`, ${isCustomer ? "received" : "paid"} ${money(t.paid)}`}
                  </td>
                  <td />
                  <td className="whitespace-nowrap px-3 py-2 text-right text-sm font-bold tabular-nums text-[#28364b]">
                    {money(t.outstanding)}
                  </td>
                  <td className="px-4 py-2 text-[11px] text-slate-400">still open</td>
                </tr>
              ))}
            </tfoot>
          </table>
        </div>
      )}
    </div>
  );
}

function StatusChip({ status }) {
  const tone =
    status === "Closed" || status === "Applied"
      ? "bg-slate-100 text-slate-500"
      : status === "Part-paid"
        ? "bg-blue-50 text-blue-700"
        : status === "Credited"
          ? "bg-red-50 text-red-700"
          : status === "On account"
            ? "bg-green-50 text-green-700"
            : "bg-amber-50 text-amber-700";

  return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${tone}`}>{status}</span>;
}
