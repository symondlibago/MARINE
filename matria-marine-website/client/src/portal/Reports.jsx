import { useState, useMemo, useEffect, Fragment } from "react";
import { Link } from "wouter";
import { useQuery } from "@tanstack/react-query";
import { motion, AnimatePresence } from "framer-motion";
import { TrendingUp, Users, GitBranch, Download, ShoppingCart, FileText, PiggyBank, Calculator, ChevronRight } from "lucide-react";
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer } from "recharts";
import { reportsAPI } from "@/pages/api";
import { PageLoader } from "./ui/Loading";
import DatePicker from "./ui/DatePicker";
import Select from "./ui/Select";
import { fetchRates } from "@/lib/fx";

/* ----------------------------- helpers ----------------------------- */

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

const PRESETS = [
  { key: "month", label: "This month" },
  { key: "quarter", label: "Last 3 months" },
  { key: "year", label: "This year" },
  { key: "all", label: "All time" },
];

function presetRange(key) {
  const today = new Date();
  const ymd = (d) => {
    const z = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
    return z.toISOString().slice(0, 10);
  };
  if (key === "month") return { from: ymd(new Date(today.getFullYear(), today.getMonth(), 1)), to: ymd(today) };
  if (key === "quarter") { const d = new Date(today); d.setMonth(d.getMonth() - 3); return { from: ymd(d), to: ymd(today) }; }
  if (key === "year") return { from: ymd(new Date(today.getFullYear(), 0, 1)), to: ymd(today) };
  return { from: "", to: "" };
}

function asParams(range) {
  const p = {};
  if (range.from) p.from = range.from;
  if (range.to) p.to = range.to;
  return p;
}

function monthLabel(s) {
  const [y, m] = s.split("-");
  return `${new Date(y, m - 1, 1).toLocaleString(undefined, { month: "short" })} '${y.slice(2)}`;
}

function downloadCsv(filename, headers, rows) {
  const esc = (v) => {
    const s = v == null ? "" : String(v);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const csv = [headers, ...rows].map((r) => r.map(esc).join(",")).join("\n");
  const url = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

const rateClass = (v) => (v == null ? "text-slate-300" : v >= 66 ? "text-green-600" : v >= 33 ? "text-amber-600" : "text-red-500");

/* --------------------------- shared bits --------------------------- */

function Card({ title, action, children, className = "" }) {
  return (
    <div className={`rounded-xl border border-slate-200 bg-white p-5 ${className}`}>
      <div className="mb-4 flex items-center justify-between gap-2">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">{title}</h2>
        {action}
      </div>
      {children}
    </div>
  );
}

function Kpi({ label, value, icon: Icon, accent }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-slate-500">{label}</span>
        {Icon && <div className="rounded-lg bg-[#28364b]/5 p-2 text-[#28364b]"><Icon className="h-5 w-5" /></div>}
      </div>
      <div className={`mt-3 text-3xl font-bold ${accent || "text-[#28364b]"}`}>{value}</div>
    </div>
  );
}

function BarRow({ label, value, max, currency, isMoney = true }) {
  const pct = max > 0 ? Math.max(3, Math.round((value / max) * 100)) : 0;
  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between text-sm">
        <span className="mr-2 truncate text-slate-600">{label}</span>
        <span className="shrink-0 font-medium text-[#28364b]">{isMoney ? money(value) : value}{currency ? ` ${currency}` : ""}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-slate-100">
        <div className="h-full rounded-full bg-[#28364b]" style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

const EmptyMsg = ({ children }) => <p className="py-8 text-center text-sm text-slate-400">{children}</p>;

const ExportBtn = ({ onClick }) => (
  <button onClick={onClick} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-500 transition-colors hover:bg-slate-50">
    <Download className="h-3.5 w-3.5" /> CSV
  </button>
);

/* ------------------------------ tabs ------------------------------- */

function SpendReport({ range }) {
  const { data, isLoading } = useQuery({
    queryKey: ["report-spend", range.from, range.to],
    queryFn: async () => (await reportsAPI.spend(asParams(range))).data.data,
  });
  if (isLoading) return <PageLoader />;
  if (!data) return null;

  const { base_currency, totals, by_vendor, by_vessel, monthly, multi_base } = data;
  const maxVendor = Math.max(1, ...by_vendor.map((v) => v.total));
  const maxVessel = Math.max(1, ...by_vessel.map((v) => v.total));

  return (
    <div className="space-y-5">
      {multi_base && (
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">Totals span multiple base currencies; shown converted to {base_currency}-equivalent.</p>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <Kpi label={`Ordered · ${base_currency}`} value={money(totals.ordered)} icon={ShoppingCart} />
        <Kpi label="Purchase orders" value={totals.po_count} icon={FileText} />
      </div>

      <Card title="Monthly ordered spend">
        {monthly.length === 0 ? (
          <EmptyMsg>No spend in this period.</EmptyMsg>
        ) : (
          <ResponsiveContainer width="100%" height={240}>
            <BarChart data={monthly} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <XAxis dataKey="month" tickFormatter={monthLabel} tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
              <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={48} tickFormatter={(v) => (v >= 1000 ? `${v / 1000}k` : v)} />
              <Tooltip cursor={{ fill: "#f1f5f9" }} formatter={(v) => [`${money(v)} ${base_currency}`, "Ordered"]} labelFormatter={monthLabel} />
              <Bar dataKey="ordered" radius={[6, 6, 0, 0]} fill="#28364b" />
            </BarChart>
          </ResponsiveContainer>
        )}
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card
          title="Top vendors by spend"
          action={by_vendor.length > 0 && <ExportBtn onClick={() => downloadCsv("spend-by-vendor.csv", ["Vendor", "Orders", `Total (${base_currency})`], by_vendor.map((v) => [v.vendor, v.orders, v.total]))} />}
        >
          {by_vendor.length === 0 ? <EmptyMsg>No spend yet.</EmptyMsg> : (
            <div className="space-y-3">{by_vendor.map((v) => <BarRow key={v.vendor} label={v.vendor} value={v.total} max={maxVendor} currency={base_currency} />)}</div>
          )}
        </Card>

        <Card
          title="Spend by vessel"
          action={by_vessel.length > 0 && <ExportBtn onClick={() => downloadCsv("spend-by-vessel.csv", ["Vessel", "Orders", `Total (${base_currency})`], by_vessel.map((v) => [v.vessel, v.orders, v.total]))} />}
        >
          {by_vessel.length === 0 ? <EmptyMsg>No spend yet.</EmptyMsg> : (
            <div className="space-y-3">{by_vessel.map((v) => <BarRow key={v.vessel} label={v.vessel} value={v.total} max={maxVessel} currency={base_currency} />)}</div>
          )}
        </Card>
      </div>
    </div>
  );
}

function VendorScorecard({ range }) {
  const { data, isLoading } = useQuery({
    queryKey: ["report-vendors", range.from, range.to],
    queryFn: async () => (await reportsAPI.vendors(asParams(range))).data.data,
  });
  if (isLoading) return <PageLoader />;
  if (!data) return null;

  const { rows, base_currency } = data;
  const cols = ["Vendor", "Invited", "Quoted", "Resp %", "Orders", "Win %", "Accept %", `Ordered (${base_currency})`];

  return (
    <Card
      title="Vendor scorecard"
      action={rows.length > 0 && <ExportBtn onClick={() => downloadCsv("vendor-scorecard.csv", cols, rows.map((r) => [r.vendor, r.sent, r.quoted, r.response_rate ?? "", r.orders, r.win_rate ?? "", r.accept_rate ?? "", r.ordered_value]))} />}
      className="overflow-x-auto"
    >
      {rows.length === 0 ? (
        <EmptyMsg>No vendor activity in this period.</EmptyMsg>
      ) : (
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-2 py-2 font-semibold">Vendor</th>
              <th className="px-2 py-2 text-right font-semibold">Invited</th>
              <th className="px-2 py-2 text-right font-semibold">Quoted</th>
              <th className="px-2 py-2 text-right font-semibold">Resp</th>
              <th className="px-2 py-2 text-right font-semibold">Orders</th>
              <th className="px-2 py-2 text-right font-semibold">Win</th>
              <th className="px-2 py-2 text-right font-semibold">Accept</th>
              <th className="px-2 py-2 text-right font-semibold">Ordered ({base_currency})</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.vendor} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                <td className="px-2 py-2.5">
                  <div className="font-medium text-[#28364b]">{r.vendor}</div>
                </td>
                <td className="px-2 py-2.5 text-right text-slate-600">{r.sent}</td>
                <td className="px-2 py-2.5 text-right text-slate-600">{r.quoted}</td>
                <td className={`px-2 py-2.5 text-right font-medium ${rateClass(r.response_rate)}`}>{r.response_rate == null ? "—" : `${r.response_rate}%`}</td>
                <td className="px-2 py-2.5 text-right text-slate-600">{r.orders}</td>
                <td className={`px-2 py-2.5 text-right font-medium ${rateClass(r.win_rate)}`}>{r.win_rate == null ? "—" : `${r.win_rate}%`}</td>
                <td className={`px-2 py-2.5 text-right font-medium ${rateClass(r.accept_rate)}`}>{r.accept_rate == null ? "—" : `${r.accept_rate}%`}</td>
                <td className="px-2 py-2.5 text-right font-semibold text-[#28364b]">{money(r.ordered_value)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {rows.length > 0 && <p className="mt-3 text-xs text-slate-400">Resp = quotes ÷ invitations · Win = orders ÷ quotes · Accept = accepted ÷ issued POs.</p>}
    </Card>
  );
}

function AgingCard({ title, buckets }) {
  const total = buckets.reduce((a, b) => a + b.count, 0);
  return (
    <Card title={title}>
      {total === 0 ? (
        <EmptyMsg>Nothing open — all clear.</EmptyMsg>
      ) : (
        <table className="w-full text-sm">
          <tbody>
            {buckets.map((b) => (
              <tr key={b.bucket} className="border-b border-slate-100 last:border-0">
                <td className="py-2 text-slate-600">{b.bucket}</td>
                <td className="py-2 text-right text-slate-500">{b.count} item{b.count === 1 ? "" : "s"}</td>
                <td className="py-2 text-right font-medium text-[#28364b]">{money(b.value)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  );
}

function PipelineReport({ range }) {
  const { data, isLoading } = useQuery({
    queryKey: ["report-pipeline", range.from, range.to],
    queryFn: async () => (await reportsAPI.pipeline(asParams(range))).data.data,
  });
  if (isLoading) return <PageLoader />;
  if (!data) return null;

  const { funnel, aging_pos, savings, base_currency } = data;
  const first = funnel[0]?.count || 0;

  return (
    <div className="space-y-5">
      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Procurement funnel" className="lg:col-span-2">
          {first === 0 ? (
            <EmptyMsg>No enquiries in this period.</EmptyMsg>
          ) : (
            <div className="space-y-3">
              {funnel.map((s, i) => {
                const pct = first ? Math.round((s.count / first) * 100) : 0;
                return (
                  <div key={s.stage}>
                    <div className="mb-1 flex items-center justify-between text-sm">
                      <span className="text-slate-600">{s.stage}</span>
                      <span className="font-medium text-[#28364b]">{s.count} <span className="text-xs font-normal text-slate-400">({pct}%)</span></span>
                    </div>
                    <div className="h-3 overflow-hidden rounded-full bg-slate-100">
                      <motion.div initial={{ width: 0 }} animate={{ width: `${Math.max(2, pct)}%` }} transition={{ duration: 0.4, delay: i * 0.05 }} className="h-full rounded-full bg-[#28364b]" style={{ opacity: 1 - i * 0.1 }} />
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </Card>

        <div className="rounded-xl border border-green-200 bg-gradient-to-br from-green-50 to-white p-5">
          <div className="flex items-center justify-between">
            <span className="text-sm font-semibold text-green-800">Sourcing savings</span>
            <div className="rounded-lg bg-green-100 p-2 text-green-700"><PiggyBank className="h-5 w-5" /></div>
          </div>
          <div className="mt-3 text-3xl font-bold text-green-700">{money(savings.total)}</div>
          <div className="text-xs text-green-700/70">{base_currency}</div>
          <p className="mt-3 text-xs text-slate-500">
            Estimated saving vs the average quote across <span className="font-medium">{savings.lines}</span> competitively-sourced line{savings.lines === 1 ? "" : "s"}.
          </p>
        </div>
      </div>

      <AgingCard title="Open purchase orders by age" buckets={aging_pos} />
    </div>
  );
}

// Invoice-driven P&L: revenue (customer invoices) → COGS (vendor POs) → job expenses
// = gross profit → overhead = net profit, plus A/R and A/P. Own from/to range.
function AccountingReport() {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [open, setOpen] = useState(null);
  const [showCur, setShowCur] = useState(""); // "" = report's own base currency
  const [fx, setFx] = useState({ factor: 1, date: "", loading: false, error: "" });

  const { data, isLoading } = useQuery({
    queryKey: ["report-accounting", from, to],
    queryFn: async () => (await reportsAPI.accounting({ ...(from ? { from } : {}), ...(to ? { to } : {}) })).data.data,
  });

  const rows = data?.rows ?? [];
  const t = data?.totals;
  const oh = data?.overhead_items ?? [];
  const base = data?.base_currency;               // the currency the report is computed in
  const cur = showCur || base;                    // the currency we display in
  const curOptions = base ? [base, ...CURRENCIES.filter((c) => c !== base)] : CURRENCIES;

  // Pull the base→display rate whenever the display currency changes.
  useEffect(() => {
    let cancelled = false;
    if (!base || !showCur || showCur === base) {
      setFx({ factor: 1, date: "", loading: false, error: "" });
      return;
    }
    setFx((s) => ({ ...s, loading: true, error: "" }));
    fetchRates(base)
      .then((r) => {
        if (cancelled) return;
        const f = Number(r.rates?.[showCur]); // units of display per 1 base
        if (!f || f <= 0) { setFx({ factor: 1, date: "", loading: false, error: `No live rate for ${showCur}` }); return; }
        setFx({ factor: f, date: r.date || "", loading: false, error: "" });
      })
      .catch(() => { if (!cancelled) setFx({ factor: 1, date: "", loading: false, error: "Rate lookup failed" }); });
    return () => { cancelled = true; };
  }, [showCur, base]);

  const conv = (n) => Number(n || 0) * fx.factor;  // base value → display currency
  const m = (n) => money(conv(n));

  const exportCsv = () =>
    downloadCsv(
      "accounting.csv",
      ["Invoice", "Job", "Customer", "Vessel", "Date", "Currency", `Revenue (${cur})`, `Vendor cost (${cur})`, `Expenses (${cur})`, `Markup (${cur})`, `Net (${cur})`, "Invoice paid", "POs paid"],
      rows.map((r) => [r.invoice_number, r.qtn, r.customer, r.vessel, r.date, r.currency, conv(r.gross), conv(r.vendor_cost), conv(r.expenses), conv(r.markup), conv(r.net), r.invoice_paid ? "paid" : "unpaid", `${r.pos_paid}/${r.po_count}`])
    );

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div>
          <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">From</label>
          <div className="mt-1 w-44"><DatePicker value={from} onChange={setFrom} placeholder="Start date" /></div>
        </div>
        <div>
          <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">To</label>
          <div className="mt-1 w-44"><DatePicker value={to} onChange={setTo} placeholder="End date" /></div>
        </div>
        {(from || to) && (
          <button onClick={() => { setFrom(""); setTo(""); }} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-500 hover:bg-slate-50">Clear</button>
        )}
        <div className="ml-auto">
          <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Show in</label>
          <div className="mt-1 w-28"><Select value={cur} onChange={setShowCur} options={curOptions} /></div>
          <div className="mt-1 h-3 text-[10px] leading-3 text-slate-400">
            {fx.loading ? "fetching rate…" : fx.error ? <span className="text-amber-600">{fx.error}</span> : showCur && showCur !== base ? `×${fx.factor.toFixed(4)}${fx.date ? ` · ${fx.date}` : ""}` : ""}
          </div>
        </div>
      </div>

      {isLoading ? (
        <PageLoader />
      ) : !data ? null : (
        <>
          {data.multi_base && (
            <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">Invoices span multiple currencies; totals are summed as {cur}-equivalent.</p>
          )}

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Kpi label={`Revenue · ${cur}`} value={m(t.revenue)} />
            <Kpi label={`Gross profit · ${cur}`} value={m(t.gross_profit)} />
            <Kpi label={`Overhead · ${cur}`} value={m(t.overhead)} />
            <Kpi label={`Net profit · ${cur}`} value={m(t.net_profit)} accent={t.net_profit >= 0 ? "text-green-700" : "text-red-600"} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <div className="rounded-xl border border-slate-200 bg-white p-5">
              <h3 className="mb-3 text-sm font-bold text-[#28364b]">Income statement ({cur})</h3>
              <dl className="space-y-1.5 text-sm">
                <PLine label="Revenue (ex-GST)" value={m(t.revenue)} />
                <PLine label="Cost of goods (vendors)" value={m(t.cogs)} neg />
                <PLine label="Job expenses" value={m(t.job_expenses)} neg />
                <PLine label="Gross profit" value={m(t.gross_profit)} strong divider />
                <PLine label="Overhead" value={m(t.overhead)} neg />
                <PLine label="Net profit" value={m(t.net_profit)} strong divider accent={t.net_profit >= 0 ? "text-green-700" : "text-red-600"} />
              </dl>
            </div>
            <div className="rounded-xl border border-slate-200 bg-white p-5">
              <h3 className="mb-3 text-sm font-bold text-[#28364b]">Cash position ({cur})</h3>
              <dl className="space-y-1.5 text-sm">
                <PLine label="Collected from customers" value={m(t.collected)} />
                <PLine label="Receivables outstanding (A/R)" value={m(t.receivables)} accent="text-amber-600" />
                <PLine label="Paid to vendors" value={m(t.cost_paid)} />
                <PLine label="Payables outstanding (A/P)" value={m(t.payables)} accent="text-amber-600" />
                <PLine label="Net cash (collected − paid)" value={m(t.collected - t.cost_paid)} strong divider />
              </dl>
            </div>
          </div>

          <Card title="Invoice-by-invoice profit & loss" action={rows.length > 0 && <ExportBtn onClick={exportCsv} />} className="overflow-x-auto">
            {rows.length === 0 ? (
              <EmptyMsg>No customer invoices in this period. Revenue is driven by invoices now — bill a job (or make a direct invoice) to see it here.</EmptyMsg>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th className="px-2 py-2 font-semibold">Invoice</th>
                    <th className="px-2 py-2 font-semibold">Customer</th>
                    <th className="px-2 py-2 text-right font-semibold">Revenue</th>
                    <th className="px-2 py-2 text-right font-semibold">Vendor cost</th>
                    <th className="px-2 py-2 text-right font-semibold">Expenses</th>
                    <th className="px-2 py-2 text-right font-semibold">Markup</th>
                    <th className="px-2 py-2 text-right font-semibold">Net</th>
                    <th className="px-2 py-2 text-center font-semibold">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r, i) => {
                    const provisional = r.po_count > 0 && r.received < r.po_count;
                    const expanded = open === i;
                    return (
                      <Fragment key={r.invoice_id}>
                        <tr onClick={() => setOpen(expanded ? null : i)} className="cursor-pointer border-b border-slate-100 hover:bg-slate-50">
                          <td className="px-2 py-2.5">
                            <div className="flex items-center gap-1 font-medium text-[#28364b]">
                              <ChevronRight className={`h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform ${expanded ? "rotate-90" : ""} ${r.vendors.length === 0 ? "opacity-0" : ""}`} />
                              {r.invoice_number || "—"}
                            </div>
                            <div className="pl-4 text-xs text-slate-400">{r.direct ? "Direct" : r.qtn}{r.date ? ` · ${r.date}` : ""}{r.vessel ? ` · ${r.vessel}` : ""}</div>
                          </td>
                          <td className="px-2 py-2.5 text-slate-600">{r.customer || "—"}</td>
                          <td className="px-2 py-2.5 text-right text-slate-700">{m(r.gross)}</td>
                          <td className="px-2 py-2.5 text-right text-slate-700">{m(r.vendor_cost)}</td>
                          <td className="px-2 py-2.5 text-right text-slate-700">{m(r.expenses)}</td>
                          <td className="px-2 py-2.5 text-right text-slate-700">{m(r.markup)}</td>
                          <td className={`px-2 py-2.5 text-right font-semibold ${provisional ? "italic text-slate-400" : r.net >= 0 ? "text-green-700" : "text-red-600"}`}>
                            {provisional ? "~" : ""}{m(r.net)}
                          </td>
                          <td className="px-2 py-2.5">
                            <div className="flex flex-col items-center gap-0.5">
                              <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium ${r.invoice_paid ? "bg-green-100 text-green-700" : "bg-amber-100 text-amber-700"}`}>{r.invoice_paid ? "Paid" : "Unpaid"}</span>
                              {r.po_count > 0 && <span className="text-[10px] text-slate-400">vendor {r.pos_paid}/{r.po_count} paid</span>}
                            </div>
                          </td>
                        </tr>
                        {expanded && r.vendors.map((v, vi) => (
                          <tr key={`${r.invoice_id}-v${vi}`} className="border-b border-slate-100 bg-slate-50/60 text-xs">
                            <td className="px-2 py-1.5 pl-8 text-slate-500">{v.po_number}</td>
                            <td className="px-2 py-1.5 text-slate-600">{v.vendor}</td>
                            <td className="px-2 py-1.5 text-right text-slate-400" title="Awarded cost">{m(v.awarded)}</td>
                            <td className="px-2 py-1.5 text-right text-slate-600">{m(v.cost)}</td>
                            <td className="px-2 py-1.5 text-right text-slate-600">{m(v.expenses)}</td>
                            <td className="px-2 py-1.5"></td>
                            <td className="px-2 py-1.5"></td>
                            <td className="px-2 py-1.5">
                              <div className="flex flex-col items-center gap-0.5">
                                <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium ${v.paid ? "bg-green-100 text-green-700" : "bg-amber-100 text-amber-700"}`}>{v.paid ? "paid" : "unpaid"}</span>
                                <span className={`text-[10px] ${v.has_receipt ? "text-green-600" : "text-slate-400"}`}>{v.has_receipt ? "receipt in" : "no receipt"}</span>
                              </div>
                            </td>
                          </tr>
                        ))}
                      </Fragment>
                    );
                  })}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-slate-200 bg-slate-50 font-semibold text-[#28364b]">
                    <td className="px-2 py-2.5" colSpan={2}>Totals ({cur}) · {t.jobs} invoice{t.jobs === 1 ? "" : "s"}</td>
                    <td className="px-2 py-2.5 text-right">{m(t.revenue)}</td>
                    <td className="px-2 py-2.5 text-right">{m(t.cogs)}</td>
                    <td className="px-2 py-2.5 text-right">{m(t.job_expenses)}</td>
                    <td className="px-2 py-2.5 text-right">{m(t.revenue - t.cogs)}</td>
                    <td className="px-2 py-2.5 text-right text-green-700">{m(t.gross_profit)}</td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            )}
          </Card>

          <Card title={`Operating expenses (overhead) · ${cur}`} action={<Link href="/operating-expenses" className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-500 transition-colors hover:bg-slate-50">Manage</Link>}>
            {oh.length === 0 ? (
              <EmptyMsg>No overhead in this period. Add rent, salaries, etc. in Operating Expenses to complete your net profit.</EmptyMsg>
            ) : (
              <table className="w-full text-sm">
                <tbody>
                  {oh.map((e) => (
                    <tr key={e.id} className="border-b border-slate-100 last:border-0">
                      <td className="px-2 py-2 font-medium text-[#28364b]">{e.name}</td>
                      <td className="px-2 py-2 text-slate-500">{e.period_start} → {e.period_end}</td>
                      <td className="px-2 py-2 text-right text-slate-700">{m(e.amount)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-slate-200 bg-slate-50 font-semibold text-[#28364b]">
                    <td className="px-2 py-2.5" colSpan={2}>Total overhead</td>
                    <td className="px-2 py-2.5 text-right">{m(t.overhead)}</td>
                  </tr>
                </tfoot>
              </table>
            )}
          </Card>

          <p className="text-xs text-slate-400">
            Revenue = customer invoice total (ex-GST) · Cost of goods = the vendor receipt where recorded, otherwise the awarded cost · Gross profit − Overhead = Net profit.
            A <span className="italic">~</span> net is provisional until every vendor's receipt is in. Click an invoice to see its vendors.
            {showCur && showCur !== base && (
              <> · Figures converted from {base} to {cur} at today's live rate (×{fx.factor.toFixed(4)}{fx.date ? `, ${fx.date}` : ""}).</>
            )}
          </p>
        </>
      )}
    </div>
  );
}

// One row in the Income-statement / Cash-position panels.
function PLine({ label, value, neg = false, strong = false, divider = false, accent = "" }) {
  return (
    <div className={`flex items-center justify-between ${divider ? "border-t border-slate-200 pt-1.5" : ""}`}>
      <dt className={strong ? "font-semibold text-[#28364b]" : "text-slate-500"}>{label}</dt>
      <dd className={`tabular-nums ${accent || (strong ? "font-semibold text-[#28364b]" : "text-slate-700")}`}>{neg ? "− " : ""}{value}</dd>
    </div>
  );
}

/* ------------------------------ shell ------------------------------ */

const TABS = [
  { key: "accounting", label: "Accounting", icon: Calculator, Component: AccountingReport },
  { key: "spend", label: "Spend", icon: TrendingUp, Component: SpendReport },
  { key: "vendors", label: "Vendor scorecard", icon: Users, Component: VendorScorecard },
  { key: "pipeline", label: "Pipeline", icon: GitBranch, Component: PipelineReport },
];

export default function Reports() {
  const [tab, setTab] = useState("spend");
  const [preset, setPreset] = useState("year");
  const range = useMemo(() => presetRange(preset), [preset]);
  const Active = TABS.find((t) => t.key === tab).Component;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Reports</h1>
          <p className="text-sm text-slate-500">Spend, vendor performance &amp; procurement pipeline.</p>
        </div>
        <div className="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1">
          {PRESETS.map((p) => (
            <button
              key={p.key}
              onClick={() => setPreset(p.key)}
              className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${preset === p.key ? "bg-[#28364b] text-white" : "text-slate-500 hover:bg-slate-100"}`}
            >
              {p.label}
            </button>
          ))}
        </div>
      </div>

      <div className="flex gap-1 border-b border-slate-200">
        {TABS.map((t) => {
          const Icon = t.icon;
          const active = tab === t.key;
          return (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`relative flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium transition-colors ${active ? "text-[#28364b]" : "text-slate-400 hover:text-slate-600"}`}
            >
              <Icon className="h-4 w-4" /> {t.label}
              {active && <motion.div layoutId="reports-tab" className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-[#28364b]" />}
            </button>
          );
        })}
      </div>

      <AnimatePresence mode="wait">
        <motion.div key={tab} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} transition={{ duration: 0.2 }}>
          <Active range={range} />
        </motion.div>
      </AnimatePresence>
    </div>
  );
}
