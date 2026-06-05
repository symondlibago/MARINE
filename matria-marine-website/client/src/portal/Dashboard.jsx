import { useQuery } from "@tanstack/react-query";
import { Link } from "wouter";
import { motion } from "framer-motion";
import { Ship, FileText, ShoppingCart, Receipt, Send, FileCheck, Download, CheckCircle2 } from "lucide-react";
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, Cell } from "recharts";
import { rfqsAPI, vendorsAPI, purchaseOrdersAPI, purchaseInvoicesAPI } from "@/pages/api";

const ENQ_ORDER = ["draft", "sent", "quoting", "awarded", "closed"];
const ENQ_COLOR = { draft: "#94a3b8", sent: "#3b82f6", quoting: "#f59e0b", awarded: "#8b5cf6", closed: "#22c55e" };

const PO_STATUSES = [
  { key: "draft", label: "Draft", color: "#94a3b8" },
  { key: "issued", label: "Issued", color: "#3b82f6" },
  { key: "received", label: "Received", color: "#22c55e" },
  { key: "cancelled", label: "Cancelled", color: "#ef4444" },
];

const PO_BADGE = {
  draft: "bg-slate-100 text-slate-600",
  issued: "bg-blue-100 text-blue-700",
  received: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};
const INV_BADGE = {
  draft: "bg-slate-100 text-slate-600",
  approved: "bg-blue-100 text-blue-700",
  exported: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};
const ENQ_BADGE = {
  draft: "bg-slate-100 text-slate-600",
  sent: "bg-blue-100 text-blue-700",
  quoting: "bg-amber-100 text-amber-700",
  awarded: "bg-violet-100 text-violet-700",
  closed: "bg-green-100 text-green-700",
};

export default function Dashboard() {
  const { data: rfqs } = useQuery({ queryKey: ["rfqs"], queryFn: async () => (await rfqsAPI.list()).data.data });
  const { data: vendors } = useQuery({ queryKey: ["vendors", ""], queryFn: async () => (await vendorsAPI.list()).data.data });
  const { data: pos } = useQuery({ queryKey: ["dashboard-pos"], queryFn: async () => (await purchaseOrdersAPI.list()).data.data });
  const { data: invs } = useQuery({ queryKey: ["dashboard-invoices"], queryFn: async () => (await purchaseInvoicesAPI.list()).data.data });

  const rfqList = rfqs ?? [];
  const poList = pos ?? [];
  const invList = invs ?? [];
  const vendorList = vendors ?? [];

  const openEnq = rfqList.filter((r) => r.status !== "closed").length;
  const quotes = rfqList.reduce((a, r) => a + (r.quotes_count || 0), 0);
  const poAwaiting = poList.filter((p) => p.status === "issued" && !p.accepted_at).length;
  const invToApprove = invList.filter((i) => i.status === "draft").length;
  const invToExport = invList.filter((i) => i.status === "approved").length;

  const enqChart = ENQ_ORDER.map((s) => ({ status: s, count: rfqList.filter((r) => r.status === s).length })).filter((d) => d.count > 0);

  const stats = [
    { label: "Enquiries", value: rfqList.length, sub: `${openEnq} open · ${quotes} quotes`, icon: FileText, to: "/enquiries" },
    { label: "Purchase Orders", value: poList.length, sub: `${poAwaiting} awaiting acceptance`, icon: ShoppingCart, to: "/purchase-orders" },
    { label: "Invoices", value: invList.length, sub: `${invToExport} ready to export`, icon: Receipt, to: "/invoices" },
    { label: "Vendors", value: vendorList.length, sub: `${vendorList.filter((v) => v.is_active).length} active`, icon: Ship, to: "/vendors" },
  ];

  const actions = [
    { label: "POs awaiting vendor acceptance", count: poAwaiting, icon: Send, to: "/purchase-orders" },
    { label: "Invoices to approve", count: invToApprove, icon: FileCheck, to: "/invoices" },
    { label: "Invoices ready to export", count: invToExport, icon: Download, to: "/invoices" },
  ];
  const allClear = actions.every((a) => a.count === 0);

  const today = new Date().toLocaleDateString(undefined, { weekday: "long", day: "numeric", month: "long", year: "numeric" });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Dashboard</h1>
        <p className="text-sm text-slate-500">{today}</p>
      </div>

      {/* Primary stats */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {stats.map((s, i) => {
          const Icon = s.icon;
          return (
            <Link key={s.label} href={s.to}>
              <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: i * 0.05 }}
                className="h-full rounded-xl border border-slate-200 bg-white p-5 transition-shadow hover:shadow-md"
              >
                <div className="flex items-center justify-between">
                  <span className="text-sm font-semibold text-slate-500">{s.label}</span>
                  <div className="rounded-lg bg-[#28364b]/5 p-2 text-[#28364b]"><Icon className="h-5 w-5" /></div>
                </div>
                <div className="mt-3 text-3xl font-bold text-[#28364b]">{s.value}</div>
                <div className="mt-1 text-xs text-slate-400">{s.sub}</div>
              </motion.div>
            </Link>
          );
        })}
      </div>

      {/* Needs attention */}
      <div>
        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Needs attention</h2>
        {allClear ? (
          <div className="flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle2 className="h-4 w-4" /> All caught up — nothing waiting on you.
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-3">
            {actions.map((a) => {
              const Icon = a.icon;
              const active = a.count > 0;
              return (
                <Link
                  key={a.label}
                  href={a.to}
                  className={`flex items-center gap-3 rounded-xl border p-4 transition-colors ${active ? "border-amber-200 bg-amber-50 hover:bg-amber-100" : "border-slate-200 bg-white hover:bg-slate-50"}`}
                >
                  <div className={`rounded-lg p-2 ${active ? "bg-amber-100 text-amber-700" : "bg-slate-100 text-slate-400"}`}><Icon className="h-5 w-5" /></div>
                  <div>
                    <div className={`text-2xl font-bold ${active ? "text-amber-700" : "text-slate-300"}`}>{a.count}</div>
                    <div className="text-xs text-slate-500">{a.label}</div>
                  </div>
                </Link>
              );
            })}
          </div>
        )}
      </div>

      {/* Enquiries chart + recent enquiries */}
      <div className="grid gap-4 lg:grid-cols-3">
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.1 }} className="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-2">
          <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Enquiries by status</h2>
          {enqChart.length === 0 ? (
            <p className="py-10 text-center text-sm text-slate-400">No enquiries yet.</p>
          ) : (
            <ResponsiveContainer width="100%" height={220}>
              <BarChart data={enqChart}>
                <XAxis dataKey="status" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={28} />
                <Tooltip cursor={{ fill: "#f1f5f9" }} />
                <Bar dataKey="count" radius={[6, 6, 0, 0]}>
                  {enqChart.map((d) => <Cell key={d.status} fill={ENQ_COLOR[d.status] || "#28364b"} />)}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          )}
        </motion.div>

        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.15 }} className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Purchase orders by status</h2>
          {poList.length === 0 ? (
            <p className="py-8 text-center text-sm text-slate-400">No purchase orders yet.</p>
          ) : (
            <div className="space-y-2">
              {PO_STATUSES.map((s) => {
                const count = poList.filter((p) => p.status === s.key).length;
                const pct = poList.length ? Math.round((count / poList.length) * 100) : 0;
                return (
                  <div key={s.key}>
                    <div className="mb-1 flex items-center justify-between text-xs">
                      <span className="flex items-center gap-1.5 text-slate-600"><span className="h-2.5 w-2.5 rounded-full" style={{ background: s.color }} /> {s.label}</span>
                      <span className="font-medium text-slate-500">{count}</span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                      <div className="h-full rounded-full" style={{ width: `${pct}%`, background: s.color }} />
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </motion.div>
      </div>

      {/* Recent activity */}
      <div className="grid gap-4 lg:grid-cols-3">
        <RecentCard
          title="Recent enquiries"
          to="/enquiries"
          rows={rfqList.slice(0, 6)}
          empty="No enquiries yet."
          render={(r) => (
            <Link key={r.id} href={`/enquiries/${r.id}`} className="flex items-center justify-between rounded-lg px-2 py-2 text-sm transition-colors hover:bg-slate-50">
              <span className="font-medium text-[#28364b]">{r.reference} <span className="font-normal text-slate-400">· {r.ship_name || "—"}</span></span>
              <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${ENQ_BADGE[r.status] || "bg-slate-100"}`}>{r.status}</span>
            </Link>
          )}
        />
        <RecentCard
          title="Recent purchase orders"
          to="/purchase-orders"
          rows={poList.slice(0, 6)}
          empty="No purchase orders yet."
          render={(p) => (
            <Link key={p.id} href={`/purchase-orders/${p.id}`} className="flex items-center justify-between rounded-lg px-2 py-2 text-sm transition-colors hover:bg-slate-50">
              <span className="font-medium text-[#28364b]">{p.po_number} <span className="font-normal text-slate-400">· {p.vendor}</span></span>
              <span className="flex items-center gap-2">
                <span className="text-xs text-slate-400">{Number(p.subtotal).toFixed(2)} {p.currency}</span>
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${PO_BADGE[p.status] || "bg-slate-100"}`}>{p.status}</span>
              </span>
            </Link>
          )}
        />
        <RecentCard
          title="Recent invoices"
          to="/invoices"
          rows={invList.slice(0, 6)}
          empty="No invoices yet."
          render={(inv) => (
            <Link key={inv.id} href={`/invoices/${inv.id}`} className="flex items-center justify-between rounded-lg px-2 py-2 text-sm transition-colors hover:bg-slate-50">
              <span className="font-medium text-[#28364b]">{inv.reference} <span className="font-normal text-slate-400">· {inv.vendor}</span></span>
              <span className="flex items-center gap-2">
                <span className="text-xs text-slate-400">{Number(inv.total).toFixed(2)} {inv.currency}</span>
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${INV_BADGE[inv.status] || "bg-slate-100"}`}>{inv.status}</span>
              </span>
            </Link>
          )}
        />
      </div>
    </div>
  );
}

function RecentCard({ title, to, rows, render, empty }) {
  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.2 }} className="rounded-xl border border-slate-200 bg-white p-5">
      <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">{title}</h2>
      <div className="space-y-1">
        {rows.length === 0 ? <p className="py-6 text-center text-sm text-slate-400">{empty}</p> : rows.map(render)}
      </div>
      {rows.length > 0 && <Link href={to} className="mt-2 block text-center text-xs font-medium text-[#28364b] hover:underline">View all →</Link>}
    </motion.div>
  );
}
