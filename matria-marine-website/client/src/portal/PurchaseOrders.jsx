import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useLocation } from "wouter";
import { motion } from "framer-motion";
import { Search, ShoppingCart, CheckCircle2, ChevronRight, ChevronDown } from "lucide-react";
import { purchaseOrdersAPI } from "@/pages/api";
import { TableSkeleton } from "./ui/Loading";
import Select from "./ui/Select";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  issued: "bg-blue-100 text-blue-700",
  received: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "draft", label: "Draft" },
  { value: "issued", label: "Issued" },
  { value: "received", label: "Received" },
  { value: "cancelled", label: "Cancelled" },
];

function StatusBadge({ status }) {
  return (
    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[status] || "bg-slate-100"}`}>{status}</span>
  );
}

export default function PurchaseOrders() {
  const [, setLocation] = useLocation();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [open, setOpen] = useState({});

  const { data, isLoading } = useQuery({
    queryKey: ["purchase-orders", status],
    queryFn: async () => (await purchaseOrdersAPI.list(status ? { status } : {})).data.data,
  });

  const all = data ?? [];
  const rows = all.filter(
    (r) =>
      !search ||
      (r.po_number || "").toLowerCase().includes(search.toLowerCase()) ||
      (r.vendor || "").toLowerCase().includes(search.toLowerCase()) ||
      (r.reference || "").toLowerCase().includes(search.toLowerCase())
  );

  // One row per enquiry; the individual vendor POs are nested underneath.
  const groups = useMemo(() => {
    const map = new Map();
    for (const r of rows) {
      const key = r.rfq_id ?? `solo-${r.id}`;
      if (!map.has(key)) {
        map.set(key, { key, rfq_id: r.rfq_id, reference: r.reference, pos: [] });
      }
      map.get(key).pos.push(r);
    }
    return Array.from(map.values()).map((g) => {
      const currencies = new Set(g.pos.map((p) => p.currency));
      const statusCounts = g.pos.reduce((m, p) => ({ ...m, [p.status]: (m[p.status] || 0) + 1 }), {});
      const issuedDates = g.pos.map((p) => p.issued_date).filter(Boolean).sort();
      return {
        ...g,
        total: g.pos.reduce((s, p) => s + Number(p.subtotal || 0), 0),
        currency: currencies.size === 1 ? [...currencies][0] : null,
        items: g.pos.reduce((s, p) => s + Number(p.items_count || 0), 0),
        statusCounts,
        latestIssued: issuedDates.length ? issuedDates[issuedDates.length - 1] : null,
      };
    });
  }, [rows]);

  const toggle = (key) => setOpen((o) => ({ ...o, [key]: !o[key] }));

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Purchase Orders</h1>
        <p className="text-sm text-slate-500">
          {groups.length} enquir{groups.length === 1 ? "y" : "ies"} · {rows.length} order{rows.length === 1 ? "" : "s"}
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-[220px] flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search PO #, vendor, enquiry…"
            className="w-full rounded-lg border border-slate-200 px-3 py-2 pl-9 text-sm"
          />
        </div>
        <div className="w-44">
          <Select value={status} onChange={setStatus} options={STATUS_OPTIONS} />
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Enquiry / PO</th>
              <th className="px-4 py-3 font-semibold">Vendor</th>
              <th className="px-4 py-3 font-semibold">Prepared by</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="px-4 py-3 text-right font-semibold">Items</th>
              <th className="px-4 py-3 text-right font-semibold">Total</th>
              <th className="px-4 py-3 text-right font-semibold">Issued</th>
            </tr>
          </thead>
          {isLoading ? (
            <tbody><TableSkeleton cols={6} /></tbody>
          ) : groups.length === 0 ? (
            <tbody>
              <tr>
                <td colSpan={7} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center gap-2">
                    <ShoppingCart className="h-8 w-8 text-slate-300" />
                    No purchase orders yet — generate them from a finished enquiry's <span className="font-medium">Delivery Order</span>.
                  </div>
                </td>
              </tr>
            </tbody>
          ) : (
              groups.map((g, i) => {
                const isOpen = !!open[g.key];
                return (
                  <motion.tbody
                    key={g.key}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: Math.min(i * 0.02, 0.3) }}
                  >
                    {/* Enquiry group header */}
                    <tr
                      onClick={() => toggle(g.key)}
                      className="cursor-pointer border-b border-slate-100 bg-slate-50/60 hover:bg-slate-100/70"
                    >
                      <td className="px-4 py-3 font-semibold text-[#28364b]">
                        <span className="inline-flex items-center gap-1.5">
                          {isOpen ? <ChevronDown className="h-4 w-4 text-slate-400" /> : <ChevronRight className="h-4 w-4 text-slate-400" />}
                          {g.reference || "—"}
                          <span className="ml-1 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600">
                            {g.pos.length} PO{g.pos.length === 1 ? "" : "s"}
                          </span>
                        </span>
                      </td>
                      <td className="px-4 py-3 text-slate-500">
                        {g.pos.length} vendor{g.pos.length === 1 ? "" : "s"}
                      </td>
                      <td className="px-4 py-3"></td>
                      <td className="px-4 py-3">
                        <span className="flex flex-wrap gap-1">
                          {Object.entries(g.statusCounts).map(([s, n]) => (
                            <span key={s} className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[s] || "bg-slate-100"}`}>
                              {n} {s}
                            </span>
                          ))}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-right text-slate-600">{g.items}</td>
                      <td className="px-4 py-3 text-right font-semibold text-[#28364b]">
                        {g.currency ? (
                          <>
                            {g.total.toFixed(2)} <span className="text-xs font-normal text-slate-400">{g.currency}</span>
                          </>
                        ) : (
                          <span className="text-xs font-normal text-slate-400">mixed</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right text-slate-500">{g.latestIssued || "—"}</td>
                    </tr>

                    {/* Individual vendor POs */}
                    {isOpen &&
                      g.pos.map((r) => (
                        <tr
                          key={r.id}
                          onClick={() => setLocation(`/purchase-orders/${r.id}`)}
                          className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-blue-50/40"
                        >
                          <td className="px-4 py-2.5 pl-11 font-medium text-[#28364b]">{r.po_number}</td>
                          <td className="px-4 py-2.5 text-slate-700">{r.vendor || "—"}</td>
                          <td className="px-4 py-2.5 text-slate-500">{r.prepared_by || "—"}</td>
                          <td className="px-4 py-2.5">
                            <span className="inline-flex items-center gap-1.5">
                              <StatusBadge status={r.status} />
                              {r.accepted_at && <CheckCircle2 className="h-4 w-4 text-green-600" title="Accepted by vendor" />}
                            </span>
                          </td>
                          <td className="px-4 py-2.5 text-right text-slate-600">{r.items_count}</td>
                          <td className="px-4 py-2.5 text-right font-medium text-[#28364b]">
                            {Number(r.subtotal).toFixed(2)} <span className="text-xs font-normal text-slate-400">{r.currency}</span>
                          </td>
                          <td className="px-4 py-2.5 text-right text-slate-500">{r.issued_date || "—"}</td>
                        </tr>
                      ))}
                  </motion.tbody>
                );
              })
            )}
        </table>
      </div>
    </motion.div>
  );
}
