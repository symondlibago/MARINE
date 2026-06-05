import { useState, useMemo } from "react";
import { useQuery } from "@tanstack/react-query";
import { motion, AnimatePresence } from "framer-motion";
import { TrendingUp, Users, GitBranch, Download, ShoppingCart, Receipt, FileText, FileCheck, PiggyBank } from "lucide-react";
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer } from "recharts";
import { reportsAPI } from "@/pages/api";
import { PageLoader } from "./ui/Loading";

/* ----------------------------- helpers ----------------------------- */

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

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

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label={`Ordered · ${base_currency}`} value={money(totals.ordered)} icon={ShoppingCart} />
        <Kpi label={`Invoiced · ${base_currency}`} value={money(totals.invoiced)} icon={Receipt} />
        <Kpi label="Purchase orders" value={totals.po_count} icon={FileText} />
        <Kpi label="Invoices" value={totals.invoice_count} icon={FileCheck} />
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
                  {r.nav_code && <div className="text-[10px] text-slate-400">{r.nav_code}</div>}
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

  const { funnel, aging_pos, aging_invoices, savings, base_currency } = data;
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

      <div className="grid gap-4 lg:grid-cols-2">
        <AgingCard title="Open purchase orders by age" buckets={aging_pos} />
        <AgingCard title="Open invoices by age" buckets={aging_invoices} />
      </div>
    </div>
  );
}

/* ------------------------------ shell ------------------------------ */

const TABS = [
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
