import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useLocation } from "wouter";
import { motion } from "framer-motion";
import { Truck } from "lucide-react";
import { deliveryOrdersAPI } from "@/pages/api";
import { TableSkeleton } from "./ui/Loading";
import ListToolbar from "./ui/ListToolbar";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  confirmed: "bg-blue-100 text-blue-700",
  delivered: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "draft", label: "Draft" },
  { value: "confirmed", label: "Confirmed" },
  { value: "delivered", label: "Delivered" },
  { value: "cancelled", label: "Cancelled" },
];

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const shortDate = (d) =>
  d ? new Date(d).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

export default function DeliveryOrders() {
  const [, setLocation] = useLocation();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["delivery-orders"],
    queryFn: async () => (await deliveryOrdersAPI.list()).data.data,
  });

  const rows = useMemo(() => {
    const all = data ?? [];
    const q = search.trim().toLowerCase();

    return all.filter((r) => {
      if (status && r.status !== status) return false;
      if (!q) return true;

      return [r.do_number, r.customer?.name || r.customer_name, r.rfq?.reference, r.creator?.name]
        .some((v) => String(v || "").toLowerCase().includes(q));
    });
  }, [data, search, status]);

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Delivery Orders</h1>
        <p className="text-sm text-slate-500">Confirmed customer orders with the delivery address.</p>
      </div>

      <ListToolbar
        search={search}
        onSearch={setSearch}
        placeholder="Search DO #, customer, enquiry or who prepared it…"
        filters={[{ value: status, onChange: setStatus, options: STATUS_OPTIONS, title: "Filter by status" }]}
      />

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-3 py-3 font-semibold">DO</th>
              <th className="px-3 py-3 font-semibold">Customer</th>
              <th className="px-3 py-3 font-semibold">Customer ref.</th>
              <th className="px-3 py-3 font-semibold">Vessel</th>
              <th className="px-3 py-3 font-semibold">Enquiry</th>
              <th className="px-3 py-3 font-semibold">Proforma</th>
              <th className="px-3 py-3 font-semibold">Order date</th>
              <th className="px-3 py-3 font-semibold">Readiness</th>
              <th className="px-3 py-3 font-semibold">Prepared by</th>
              <th className="px-3 py-3 font-semibold">Status</th>
              <th className="px-3 py-3 text-right font-semibold">Total</th>
              <th className="px-3 py-3 font-semibold">Cur.</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <TableSkeleton cols={12} />
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={12} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center gap-2">
                    <Truck className="h-8 w-8 text-slate-300" />
                    {search || status ? (
                      "No delivery orders match that."
                    ) : (
                      <>
                        No delivery orders yet — open an accepted offer and click <span className="font-medium">Create Delivery Order</span>.
                      </>
                    )}
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
                  onClick={() => setLocation(`/delivery-orders/${r.id}`)}
                  className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-3 py-3 font-medium text-[#28364b] whitespace-nowrap">{r.do_number}</td>
                  <td className="px-3 py-3 text-slate-700">{r.customer?.name || r.customer_name || "—"}</td>
                  <td className="px-3 py-3 text-slate-500">{r.customer_reference || "—"}</td>
                  <td className="px-3 py-3 text-slate-700">{r.rfq?.ship_name || "—"}</td>
                  <td className="px-3 py-3 text-slate-500 whitespace-nowrap">{r.rfq?.reference || "—"}</td>
                  {/* Blank until someone downloads one — which is also how you
                      tell at a glance whether the customer has been billed ahead. */}
                  <td className="px-3 py-3 text-slate-500 whitespace-nowrap">
                    {r.proforma_number || <span className="text-slate-300">—</span>}
                  </td>
                  <td className="px-3 py-3 text-slate-500 whitespace-nowrap">{shortDate(r.order_date)}</td>
                  <td className="px-3 py-3 text-slate-500 whitespace-nowrap">{shortDate(r.readiness_date)}</td>
                  <td className="px-3 py-3 text-slate-500">{r.creator?.name || "—"}</td>
                  <td className="px-3 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[r.status] || "bg-slate-100"}`}>{r.status}</span>
                  </td>
                  <td className="px-3 py-3 text-right font-medium text-[#28364b] whitespace-nowrap">{money(r.subtotal)}</td>
                  <td className="px-3 py-3 text-xs text-slate-500">{r.currency}</td>
                </motion.tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </motion.div>
  );
}
