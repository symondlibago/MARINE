import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useLocation } from "wouter";
import { motion } from "framer-motion";
import { Search, ShoppingCart, CheckCircle2 } from "lucide-react";
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

export default function PurchaseOrders() {
  const [, setLocation] = useLocation();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");

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

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Purchase Orders</h1>
        <p className="text-sm text-slate-500">{rows.length} order{rows.length === 1 ? "" : "s"}</p>
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
              <th className="px-4 py-3 font-semibold">PO #</th>
              <th className="px-4 py-3 font-semibold">Vendor</th>
              <th className="px-4 py-3 font-semibold">Enquiry</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="px-4 py-3 text-right font-semibold">Items</th>
              <th className="px-4 py-3 text-right font-semibold">Total</th>
              <th className="px-4 py-3 text-right font-semibold">Issued</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <TableSkeleton cols={7} />
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={7} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center gap-2">
                    <ShoppingCart className="h-8 w-8 text-slate-300" />
                    No purchase orders yet — generate them from a finished enquiry's <span className="font-medium">Compare &amp; Award</span> screen.
                  </div>
                </td>
              </tr>
            ) : (
              rows.map((r, i) => (
                <motion.tr
                  key={r.id}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: Math.min(i * 0.02, 0.3) }}
                  onClick={() => setLocation(`/purchase-orders/${r.id}`)}
                  className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-4 py-3 font-medium text-[#28364b]">{r.po_number}</td>
                  <td className="px-4 py-3 text-slate-700">{r.vendor || "—"}</td>
                  <td className="px-4 py-3 text-slate-500">{r.reference || "—"}</td>
                  <td className="px-4 py-3">
                    <span className="inline-flex items-center gap-1.5">
                      <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[r.status] || "bg-slate-100"}`}>{r.status}</span>
                      {r.accepted_at && <CheckCircle2 className="h-4 w-4 text-green-600" title="Accepted by vendor" />}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right text-slate-600">{r.items_count}</td>
                  <td className="px-4 py-3 text-right font-medium text-[#28364b]">
                    {Number(r.subtotal).toFixed(2)} <span className="text-xs font-normal text-slate-400">{r.currency}</span>
                  </td>
                  <td className="px-4 py-3 text-right text-slate-500">{r.issued_date || "—"}</td>
                </motion.tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </motion.div>
  );
}
