import { useState, useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { motion, AnimatePresence } from "framer-motion";
import {
  Search, Users, Ship, ArrowLeft, FileText, ShoppingCart, CheckCircle2,
  AlertCircle, Wallet, Download, Filter,
} from "lucide-react";
import { toast } from "sonner";
import { reportsAPI } from "@/pages/api";
import { Spinner } from "./ui/Loading";
import DatePicker from "./ui/DatePicker";

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Statement of account, one party at a time.
 *
 * Customers are billed through invoices; vendors are paid through purchase
 * orders. Both reduce to the same question — what was transacted and what is
 * still outstanding — so one screen answers it for either side.
 */
export default function Statements() {
  const [type, setType] = useState("customer");
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [onlyOutstanding, setOnlyOutstanding] = useState(false);
  const [selected, setSelected] = useState(null);
  const [range, setRange] = useState({ from: "", to: "" });

  // Debounced so typing a long customer name doesn't fire a query per keystroke.
  useEffect(() => {
    const t = setTimeout(() => setQuery(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  // Switching side clears the open statement — a customer id is not a vendor id.
  useEffect(() => { setSelected(null); }, [type]);

  const { data: list, isFetching } = useQuery({
    queryKey: ["statement-parties", type, query, onlyOutstanding],
    queryFn: async () =>
      (await reportsAPI.statementParties({ type, search: query || undefined, only_outstanding: onlyOutstanding || undefined })).data.data,
    keepPreviousData: true,
  });

  const { data: statement, isLoading: stLoading } = useQuery({
    queryKey: ["statement", type, selected?.id, range.from, range.to],
    queryFn: async () =>
      (await reportsAPI.statement(type, selected.id, {
        ...(range.from ? { from: range.from } : {}),
        ...(range.to ? { to: range.to } : {}),
      })).data.data,
    enabled: !!selected,
  });

  const parties = list?.parties || [];

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Statements of account</h1>
        <p className="mt-0.5 text-sm text-slate-500">
          Search a {type === "customer" ? "customer" : "vendor"} to see everything transacted with them and what is still outstanding.
        </p>
      </div>

      {/* Side switch — customers are billed, vendors are paid. */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="inline-flex rounded-xl border border-slate-200 bg-white p-1">
          {[
            ["customer", "Customers", Users],
            ["vendor", "Vendors", Ship],
          ].map(([key, label, Icon]) => (
            <button
              key={key}
              onClick={() => setType(key)}
              className={`inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
                type === key ? "bg-[#28364b] text-white" : "text-slate-500 hover:text-[#28364b]"
              }`}
            >
              <Icon className="h-4 w-4" /> {label}
            </button>
          ))}
        </div>

        <div className="relative min-w-[240px] flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={`Search ${type === "customer" ? "customers" : "vendors"} by name or email…`}
            className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-9 pr-9 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
          />
          {isFetching && <Spinner className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-300" />}
        </div>

        <button
          onClick={() => setOnlyOutstanding((v) => !v)}
          title="Show only parties with money still owed"
          className={`inline-flex items-center gap-1.5 rounded-xl border px-3 py-2.5 text-sm font-medium transition-colors ${
            onlyOutstanding ? "border-[#28364b] bg-[#28364b] text-white" : "border-slate-200 bg-white text-slate-500 hover:text-[#28364b]"
          }`}
        >
          <Filter className="h-4 w-4" /> Outstanding only
        </button>
      </div>

      <div className="grid gap-4 lg:grid-cols-[340px_1fr]">
        {/* ---------------- party list ---------------- */}
        <div className="rounded-xl border border-slate-200 bg-white">
          <div className="border-b border-slate-100 px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-slate-400">
            {parties.length} {type === "customer" ? "customer" : "vendor"}{parties.length === 1 ? "" : "s"}
            {list?.truncated && <span className="ml-1 font-normal normal-case tracking-normal text-slate-300">· keep typing to narrow</span>}
          </div>
          <div className="max-h-[70vh] overflow-y-auto p-2">
            {parties.length === 0 ? (
              <p className="px-2 py-8 text-center text-sm text-slate-400">
                {isFetching ? "Searching…" : query ? "No match." : "No records."}
              </p>
            ) : (
              parties.map((p) => {
                const owed = (p.totals || []).filter((t) => Math.abs(t.outstanding) > 0.005);
                const active = selected?.id === p.id;
                return (
                  <button
                    key={p.id}
                    onClick={() => setSelected(p)}
                    className={`mb-1 block w-full rounded-lg border px-3 py-2.5 text-left transition-colors ${
                      active ? "border-[#28364b] bg-[#28364b]/5" : "border-transparent hover:bg-slate-50"
                    }`}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <span className="text-sm font-medium text-[#28364b]">{p.name}</span>
                      {p.doc_count > 0 && (
                        <span className="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">
                          {p.doc_count}
                        </span>
                      )}
                    </div>
                    {owed.length > 0 ? (
                      <div className="mt-1 flex flex-wrap gap-1.5">
                        {owed.map((t) => (
                          <span key={t.currency} className="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700">
                            {t.currency} {money(t.outstanding)} due
                          </span>
                        ))}
                      </div>
                    ) : p.doc_count > 0 ? (
                      <div className="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-green-700">
                        <CheckCircle2 className="h-3 w-3" /> settled
                      </div>
                    ) : (
                      <div className="mt-1 text-[11px] text-slate-300">no transactions</div>
                    )}
                  </button>
                );
              })
            )}
          </div>
        </div>

        {/* ---------------- statement ---------------- */}
        <div className="rounded-xl border border-slate-200 bg-white">
          {!selected ? (
            <div className="flex h-full min-h-[300px] flex-col items-center justify-center gap-2 p-10 text-center">
              <Wallet className="h-8 w-8 text-slate-200" />
              <p className="text-sm text-slate-400">Pick a {type} on the left to open their statement.</p>
            </div>
          ) : stLoading || !statement ? (
            <div className="flex min-h-[300px] items-center justify-center"><Spinner className="h-6 w-6" /></div>
          ) : (
            <StatementPanel data={statement} type={type} range={range} setRange={setRange} onBack={() => setSelected(null)} />
          )}
        </div>
      </div>
    </motion.div>
  );
}

function StatementPanel({ data, type, range, setRange, onBack }) {
  const { party, lines, credits, totals, aging } = data;
  const isCustomer = type === "customer";

  const exportCsv = () => {
    const head = isCustomer
      ? ["Invoice", "Enquiry", "Vessel", "Date", "Due", "Currency", "Amount", "Status", "Days overdue"]
      : ["PO", "Enquiry", "Vessel", "Date", "Currency", "Ordered", "Payable", "Expenses", "Status"];
    const body = lines.map((l) =>
      isCustomer
        ? [l.number, l.reference || "", l.vessel || "", l.date || "", l.due_date || "", l.currency, l.amount, l.paid ? "Paid" : "Unpaid", l.overdue_days || 0]
        : [l.number, l.reference || "", l.vessel || "", l.date || "", l.currency, l.ordered, l.amount, l.expenses, l.paid ? "Paid" : "Unpaid"]
    );
    const csv = [head, ...body]
      .map((r) => r.map((c) => `"${String(c ?? "").replace(/"/g, '""')}"`).join(","))
      .join("\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8;" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = `Statement-${party.name.replace(/[^\w-]+/g, "-")}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success("Statement exported.");
  };

  return (
    <div className="p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <button onClick={onBack} className="mb-1 inline-flex items-center gap-1 text-xs text-slate-400 hover:text-[#28364b] lg:hidden">
            <ArrowLeft className="h-3 w-3" /> Back
          </button>
          <h2 className="text-lg font-bold text-[#28364b]">{party.name}</h2>
          <p className="text-xs text-slate-400">
            {party.email || "no email"} · {isCustomer ? "customer" : "vendor"} statement
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <DatePicker value={range.from} onChange={(v) => setRange((r) => ({ ...r, from: v }))} placeholder="From" />
          <DatePicker value={range.to} onChange={(v) => setRange((r) => ({ ...r, to: v }))} placeholder="To" />
          {(range.from || range.to) && (
            <button onClick={() => setRange({ from: "", to: "" })} className="text-xs text-slate-400 hover:text-[#28364b] hover:underline">
              Clear
            </button>
          )}
          <button
            onClick={exportCsv}
            disabled={lines.length === 0}
            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40"
          >
            <Download className="h-4 w-4" /> CSV
          </button>
        </div>
      </div>

      {/* Balances are kept per currency — a USD and an SGD balance are not
          addable, so they are never blended into one figure. */}
      <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {totals.length === 0 ? (
          <p className="text-sm text-slate-400">No transactions in this period.</p>
        ) : (
          totals.map((t) => (
            <div key={t.currency} className="rounded-xl border border-slate-200 p-3.5">
              <div className="flex items-baseline justify-between">
                <span className="text-xs font-bold uppercase tracking-wider text-slate-400">{t.currency}</span>
                <span className="text-[11px] text-slate-400">{t.count} doc{t.count === 1 ? "" : "s"}</span>
              </div>
              <div className="mt-1.5 text-2xl font-bold tabular-nums text-[#28364b]">{money(t.outstanding)}</div>
              <div className="text-[11px] font-medium text-slate-400">
                {isCustomer ? "receivable" : "payable"}
              </div>
              <div className="mt-2 flex justify-between border-t border-slate-100 pt-2 text-[11px] text-slate-500">
                <span>{isCustomer ? "Billed" : "Ordered"} <b className="tabular-nums text-slate-700">{money(t.billed)}</b></span>
                <span>{isCustomer ? "Collected" : "Paid"} <b className="tabular-nums text-green-700">{money(t.settled)}</b></span>
              </div>
            </div>
          ))
        )}
      </div>

      {isCustomer && lines.some((l) => !l.paid) && (
        <div className="mt-3 flex flex-wrap gap-2 text-xs">
          {[
            ["current", "Not yet due", "bg-slate-100 text-slate-600"],
            ["d30", "1–30 days", "bg-amber-50 text-amber-700"],
            ["d60", "31–60 days", "bg-orange-50 text-orange-700"],
            ["d90", "60+ days", "bg-red-50 text-red-700"],
          ].map(([k, label, cls]) =>
            aging[k] > 0 ? (
              <span key={k} className={`rounded-lg px-2.5 py-1 font-medium ${cls}`}>
                {label}: <b className="tabular-nums">{money(aging[k])}</b>
              </span>
            ) : null
          )}
        </div>
      )}

      <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
              <th className="px-4 py-2.5 font-semibold">{isCustomer ? "Invoice" : "Purchase order"}</th>
              <th className="px-3 py-2.5 font-semibold">Enquiry</th>
              <th className="px-3 py-2.5 font-semibold">Date</th>
              {isCustomer && <th className="px-3 py-2.5 font-semibold">Due</th>}
              {!isCustomer && <th className="px-3 py-2.5 text-right font-semibold">Ordered</th>}
              <th className="px-3 py-2.5 text-right font-semibold">Amount</th>
              <th className="px-4 py-2.5 font-semibold">Status</th>
            </tr>
          </thead>
          <tbody>
            {lines.length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-10 text-center text-sm text-slate-400">Nothing in this period.</td></tr>
            ) : (
              lines.map((l) => (
                <tr key={`${l.kind}-${l.id}`} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-2.5">
                    <span className="inline-flex items-center gap-1.5 font-medium text-[#28364b]">
                      {isCustomer ? <FileText className="h-3.5 w-3.5 text-slate-300" /> : <ShoppingCart className="h-3.5 w-3.5 text-slate-300" />}
                      {l.number}
                    </span>
                    {l.vessel && <div className="text-[11px] text-slate-400">{l.vessel}</div>}
                  </td>
                  <td className="px-3 py-2.5 text-xs text-slate-500">{l.reference || "—"}</td>
                  <td className="whitespace-nowrap px-3 py-2.5 text-xs text-slate-500">{l.date || "—"}</td>
                  {isCustomer && (
                    <td className="whitespace-nowrap px-3 py-2.5 text-xs text-slate-500">{l.due_date || "—"}</td>
                  )}
                  {!isCustomer && (
                    <td className="whitespace-nowrap px-3 py-2.5 text-right text-xs tabular-nums text-slate-500">{money(l.ordered)}</td>
                  )}
                  <td className="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums text-[#28364b]">
                    {l.currency} {money(l.amount)}
                    {!isCustomer && l.expenses > 0 && (
                      <div className="text-[11px] font-normal text-slate-400" title="Third-party costs on this order — not part of the vendor balance">
                        +{money(l.expenses)} {l.expense_currency} expenses
                      </div>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-4 py-2.5">
                    {l.paid ? (
                      <span className="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-[11px] font-semibold text-green-700">
                        <CheckCircle2 className="h-3 w-3" /> Paid{l.paid_at ? ` · ${l.paid_at}` : ""}
                      </span>
                    ) : l.overdue_days > 0 ? (
                      <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700">
                        <AlertCircle className="h-3 w-3" /> {l.overdue_days}d overdue
                      </span>
                    ) : (
                      <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700">Unpaid</span>
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <AnimatePresence>
        {credits.length > 0 && (
          <motion.div initial={{ opacity: 0, y: -4 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className="mt-4">
            <div className="mb-1.5 text-xs font-bold uppercase tracking-wider text-slate-400">
              Credit notes <span className="font-normal normal-case tracking-normal text-slate-300">· already deducted above</span>
            </div>
            <div className="overflow-hidden rounded-xl border border-slate-200">
              <table className="w-full text-sm">
                <tbody>
                  {credits.map((c) => (
                    <tr key={c.id} className="border-b border-slate-100 last:border-0">
                      <td className="px-4 py-2 font-medium text-[#28364b]">{c.number}</td>
                      <td className="px-3 py-2 text-xs text-slate-500">{c.date || "—"}</td>
                      <td className="px-3 py-2 text-xs text-slate-500">{c.reason || "—"}</td>
                      <td className="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-red-600">
                        −{c.currency} {money(c.amount)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
