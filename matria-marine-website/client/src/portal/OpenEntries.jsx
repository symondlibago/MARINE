import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { motion } from "framer-motion";
import {
  Download, FileDown, ChevronRight, Wallet, AlertCircle, Landmark, Search, History,
} from "lucide-react";
import { toast } from "sonner";
import { reportsAPI } from "@/pages/api";
import { Spinner } from "./ui/Loading";
import DatePicker from "./ui/DatePicker";
import LedgerEntries from "./LedgerEntries";

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const today = () => new Date().toISOString().slice(0, 10);

/**
 * Every customer (or vendor) carrying a balance on one chosen date.
 *
 * The single-party statement answers "what does this one owe"; this answers
 * "who owes us anything at all", which is the report Dru runs out of NAV to
 * plan collections.
 *
 * The date is an AS-OF date, not a filter: documents raised after it are
 * excluded and payments banked after it are not deducted, so the figure
 * reconciles against a bank statement for that day.
 */
/**
 * The all-parties view, in two modes.
 *
 * "Open items" answers what is still owed as at a date; "Full history" answers
 * what actually happened over a period. They are different enough to live in
 * different components, but they belong on the same screen — the question
 * "does this only show the open ones?" should be answerable by looking at it.
 */
export default function OpenEntries({ type }) {
  const [mode, setMode] = useState("open");

  return (
    <div className="space-y-4">
      <div className="inline-flex rounded-xl border border-slate-200 bg-white p-1">
        {[
          ["open", "Open items", Wallet],
          ["history", "Full history", History],
        ].map(([key, label, Icon]) => (
          <button
            key={key}
            onClick={() => setMode(key)}
            className={`inline-flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-xs font-semibold transition-colors ${
              mode === key ? "bg-[#28364b] text-white" : "text-slate-500 hover:text-[#28364b]"
            }`}
          >
            <Icon className="h-3.5 w-3.5" /> {label}
          </button>
        ))}
      </div>

      {mode === "open" ? <OpenItemsView type={type} /> : <LedgerEntries type={type} />}
    </div>
  );
}

function OpenItemsView({ type }) {
  const isCustomer = type === "customer";

  const [asOf, setAsOf] = useState(today());
  const [includeUnapplied, setIncludeUnapplied] = useState(true);
  const [newPagePerParty, setNewPagePerParty] = useState(false);
  const [filter, setFilter] = useState("");
  const [open, setOpen] = useState({});      // { partyId: true } — expanded rows
  const [pdfBusy, setPdfBusy] = useState(false);

  const params = { type, as_of: asOf, include_unapplied: includeUnapplied };

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ["open-entries", type, asOf, includeUnapplied],
    queryFn: async () => (await reportsAPI.openEntries(params)).data.data,
    keepPreviousData: true,
  });

  const parties = (data?.parties || []).filter((p) =>
    filter.trim() === "" ? true : p.name.toLowerCase().includes(filter.trim().toLowerCase())
  );

  const exportCsv = () => {
    const head = ["Party", "Document", "Type", "Reference", "Vessel", "Date", "Due date", "Currency", "Amount", "Settled", "Remaining", "Days late"];
    const rows = [];
    for (const p of parties) {
      for (const e of p.entries) {
        rows.push([p.name, e.number, isCustomer ? "Invoice" : "Purchase order", e.reference || "", e.vessel || "",
          e.date || "", e.due_date || "", e.currency, e.amount, e.settled, e.outstanding, e.overdue_days || 0]);
      }
      for (const u of p.unapplied) {
        rows.push([p.name, u.reference || u.number, "Payment on account", "", "",
          u.date || "", "", u.currency, -u.amount, "", -u.amount, ""]);
      }
    }
    const csv = [head, ...rows]
      .map((r) => r.map((c) => `"${String(c ?? "").replace(/"/g, '""')}"`).join(","))
      .join("\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8;" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = `${isCustomer ? "Customer" : "Vendor"}-Open-Entries-${asOf}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success("Open entries exported.");
  };

  const downloadPdf = async () => {
    setPdfBusy(true);
    try {
      const res = await reportsAPI.openEntriesPdf({ ...params, new_page_per_party: newPagePerParty });
      const url = URL.createObjectURL(new Blob([res.data], { type: "application/pdf" }));
      const a = document.createElement("a");
      a.href = url;
      a.download = `${isCustomer ? "Customer" : "Vendor"}-Open-Entries-${asOf}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
      toast.success("Open entries PDF downloaded.");
    } catch (e) {
      // The server refuses oversized reports with a readable reason; a blob
      // response has to be read back as text before that message is visible.
      let message = "Could not build the PDF.";
      try {
        const parsed = JSON.parse(await e?.response?.data?.text?.());
        if (parsed?.message) message = parsed.message;
      } catch { /* keep the fallback */ }
      toast.error(message);
    } finally {
      setPdfBusy(false);
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2 }} className="space-y-4">
      {/* ---------------- controls ---------------- */}
      <div className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3">
        <label className="text-xs font-semibold text-slate-500">Balance on</label>
        <DatePicker value={asOf} onChange={(v) => setAsOf(v || today())} placeholder="Date" />

        <button
          onClick={() => setIncludeUnapplied((v) => !v)}
          title="Show payments received that are not yet matched to an invoice"
          className={`rounded-lg border px-3 py-2 text-xs font-medium transition-colors ${
            includeUnapplied ? "border-[#28364b] bg-[#28364b] text-white" : "border-slate-200 text-slate-500 hover:text-[#28364b]"
          }`}
        >
          Include money on account
        </button>

        <button
          onClick={() => setNewPagePerParty((v) => !v)}
          title="PDF only: start each party on a fresh page"
          className={`rounded-lg border px-3 py-2 text-xs font-medium transition-colors ${
            newPagePerParty ? "border-[#28364b] bg-[#28364b] text-white" : "border-slate-200 text-slate-500 hover:text-[#28364b]"
          }`}
        >
          New page per {type}
        </button>

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
          className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40"
        >
          <Download className="h-3.5 w-3.5" /> CSV
        </button>
        <button
          onClick={downloadPdf}
          disabled={pdfBusy || (data?.party_count || 0) === 0}
          className="inline-flex items-center gap-1.5 rounded-lg bg-[#28364b] px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-[#3c4a63] disabled:opacity-50"
        >
          {pdfBusy ? <Spinner className="h-3.5 w-3.5" /> : <FileDown className="h-3.5 w-3.5" />} PDF
        </button>
      </div>

      <p className="text-[11px] text-slate-400">
        Stated as at <b className="text-slate-500">{asOf}</b> — documents raised after that date are excluded, and payments
        banked after it are not deducted. An invoice paid later still shows here as open.
      </p>

      {/* ---------------- grand totals ---------------- */}
      {isLoading && !data ? (
        <div className="flex min-h-[240px] items-center justify-center"><Spinner className="h-6 w-6" /></div>
      ) : (data?.party_count || 0) === 0 ? (
        <EmptyState data={data} asOf={asOf} type={type} isCustomer={isCustomer} />
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {(data?.grand_totals || []).map((g) => (
              <div key={g.currency} className="rounded-xl border border-slate-200 bg-white p-3.5">
                <div className="flex items-baseline justify-between">
                  <span className="text-xs font-bold uppercase tracking-wider text-slate-400">{g.currency}</span>
                  <span className="text-[11px] text-slate-400">
                    {g.parties} {type}{g.parties === 1 ? "" : "s"}
                  </span>
                </div>
                <div className="mt-1.5 text-2xl font-bold tabular-nums text-[#28364b]">{money(g.balance)}</div>
                <div className="text-[11px] font-medium text-slate-400">{isCustomer ? "receivable" : "payable"}</div>
                {g.unapplied > 0.005 && (
                  <div className="mt-2 border-t border-slate-100 pt-2 text-[11px] text-slate-500">
                    {money(g.outstanding)} owed less <b className="text-green-700">{money(g.unapplied)}</b> on account
                  </div>
                )}
              </div>
            ))}
          </div>

          <div className="flex items-center justify-between text-xs text-slate-400">
            <span>
              {parties.length} {type}{parties.length === 1 ? "" : "s"}
              {filter && data?.party_count !== parties.length && <span className="text-slate-300"> of {data.party_count}</span>}
              {" · "}{data?.entry_count} entr{data?.entry_count === 1 ? "y" : "ies"}
            </span>
            {isFetching && <Spinner className="h-3.5 w-3.5 text-slate-300" />}
          </div>

          {/* ---------------- per-party sections ---------------- */}
          <div className="space-y-2">
            {parties.map((p) => (
              <PartyBlock
                key={p.id}
                party={p}
                isCustomer={isCustomer}
                expanded={open[p.id] !== false}
                onToggle={() => setOpen((o) => ({ ...o, [p.id]: o[p.id] === false }))}
              />
            ))}
          </div>
        </>
      )}
    </motion.div>
  );
}

/**
 * Nothing to show — but say WHY.
 *
 * "Everyone has paid" and "there was nothing here to begin with" look identical
 * on screen and mean opposite things. Reporting the first when the second is
 * true reads as a clean bill of health on accounts nobody has billed yet.
 */
function EmptyState({ data, asOf, type, isCustomer }) {
  const seen = data?.documents_seen ?? 0;
  const drafts = data?.drafts_excluded ?? 0;
  const noun = isCustomer ? "invoice" : "purchase order";

  return (
    <div className="flex min-h-[240px] flex-col items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white p-10 text-center">
      <Wallet className="h-8 w-8 text-slate-200" />
      {seen > 0 ? (
        <p className="text-sm text-slate-400">
          Nothing outstanding on {asOf} — every {type} was settled.
        </p>
      ) : (
        <>
          <p className="text-sm font-medium text-slate-500">
            No issued {noun}s dated on or before {asOf}.
          </p>
          <p className="max-w-md text-xs text-slate-400">
            Nothing has been settled or written off — there is simply nothing to report at this date.
            {drafts === 0 && " Try moving the date forward."}
          </p>
        </>
      )}
      {drafts > 0 && (
        <p className="mt-1 max-w-md rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
          {drafts} {isCustomer ? "draft" : "cancelled"} {noun}{drafts === 1 ? "" : "s"} {drafts === 1 ? "was" : "were"} left out.
          {isCustomer && " A draft has not been issued to the customer yet, so it is not money owed."}
        </p>
      )}
    </div>
  );
}

function PartyBlock({ party, isCustomer, expanded, onToggle }) {
  const rows = party.entries.length + party.unapplied.length;

  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <button
        onClick={onToggle}
        className="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left transition-colors hover:bg-slate-50"
      >
        <span className="flex min-w-0 items-center gap-2">
          <ChevronRight className={`h-4 w-4 shrink-0 text-slate-300 transition-transform ${expanded ? "rotate-90" : ""}`} />
          <span className="truncate text-sm font-semibold text-[#28364b]">{party.name}</span>
          <span className="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">{rows}</span>
        </span>
        <span className="flex shrink-0 flex-wrap justify-end gap-1.5">
          {party.totals.map((t) => (
            <span
              key={t.currency}
              className={`rounded px-1.5 py-0.5 text-[11px] font-semibold ${
                t.balance < 0 ? "bg-green-50 text-green-700" : "bg-amber-50 text-amber-700"
              }`}
            >
              {t.currency} {money(t.balance)}
            </span>
          ))}
        </span>
      </button>

      {expanded && (
        <div className="overflow-x-auto border-t border-slate-100">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50/70 text-left text-[10px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">{isCustomer ? "Invoice" : "Purchase order"}</th>
                <th className="px-3 py-2 font-semibold">Reference</th>
                <th className="px-3 py-2 font-semibold">Date</th>
                <th className="px-3 py-2 font-semibold">Due</th>
                <th className="px-3 py-2 text-right font-semibold">Amount</th>
                <th className="px-3 py-2 text-right font-semibold">Settled</th>
                <th className="px-4 py-2 text-right font-semibold">Remaining</th>
              </tr>
            </thead>
            <tbody>
              {party.entries.map((e) => (
                <tr key={`e-${e.id}`} className="border-b border-slate-50 last:border-0">
                  <td className="px-4 py-2 font-medium text-[#28364b]">{e.number}</td>
                  <td className="px-3 py-2 text-xs text-slate-500">
                    {e.reference || "—"}
                    {e.vessel && <div className="text-[11px] text-slate-400">{e.vessel}</div>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{e.date || "—"}</td>
                  <td className="whitespace-nowrap px-3 py-2 text-xs text-slate-500">
                    {e.due_date || "—"}
                    {e.overdue_days > 0 && (
                      <div className="inline-flex items-center gap-0.5 text-[11px] font-semibold text-red-600">
                        <AlertCircle className="h-3 w-3" /> {e.overdue_days}d
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-right text-xs tabular-nums text-slate-500">
                    {e.currency} {money(e.amount)}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-right text-xs tabular-nums text-green-700">
                    {e.settled > 0 ? money(e.settled) : <span className="text-slate-300">—</span>}
                  </td>
                  <td className="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-[#28364b]">
                    {money(e.outstanding)}
                  </td>
                </tr>
              ))}

              {party.unapplied.map((u, i) => (
                <tr key={`u-${i}`} className="border-b border-slate-50 bg-green-50/40 last:border-0">
                  <td className="px-4 py-2">
                    <span className="inline-flex items-center gap-1.5 font-medium text-green-800">
                      <Landmark className="h-3.5 w-3.5 text-green-600" />
                      {u.reference || u.number}
                    </span>
                  </td>
                  <td className="px-3 py-2 text-xs text-green-700">
                    {isCustomer ? "Payment received" : "Payment made"} — on account
                    {u.method && <div className="text-[11px] text-green-600/70">{u.method}</div>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{u.date}</td>
                  <td className="px-3 py-2 text-xs text-slate-300">—</td>
                  <td className="whitespace-nowrap px-3 py-2 text-right text-xs tabular-nums text-green-700">
                    −{u.currency} {money(u.amount)}
                  </td>
                  <td className="px-3 py-2 text-xs text-slate-300">—</td>
                  <td className="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-green-700">
                    −{money(u.amount)}
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              {party.totals.map((t) => (
                <tr key={t.currency} className="border-t border-slate-200 bg-slate-50/70">
                  <td colSpan={6} className="px-4 py-2 text-right text-xs font-semibold text-slate-500">
                    Balance — {t.currency}
                    {t.unapplied > 0.005 && (
                      <span className="ml-1 font-normal text-slate-400">
                        ({money(t.outstanding)} owed less {money(t.unapplied)} on account)
                      </span>
                    )}
                  </td>
                  <td className={`px-4 py-2 text-right text-sm font-bold tabular-nums ${t.balance < 0 ? "text-green-700" : "text-[#28364b]"}`}>
                    {money(t.balance)}
                  </td>
                </tr>
              ))}
            </tfoot>
          </table>
        </div>
      )}
    </div>
  );
}
