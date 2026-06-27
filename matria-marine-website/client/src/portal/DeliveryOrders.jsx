import { useQuery } from "@tanstack/react-query";
import { useLocation } from "wouter";
import { motion } from "framer-motion";
import { Truck } from "lucide-react";
import { deliveryOrdersAPI } from "@/pages/api";
import { TableSkeleton } from "./ui/Loading";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  confirmed: "bg-blue-100 text-blue-700",
  delivered: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};

export default function DeliveryOrders() {
  const [, setLocation] = useLocation();
  const { data, isLoading } = useQuery({
    queryKey: ["delivery-orders"],
    queryFn: async () => (await deliveryOrdersAPI.list()).data.data,
  });
  const rows = data ?? [];

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Delivery Orders</h1>
        <p className="text-sm text-slate-500">Confirmed customer orders with the delivery address.</p>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">DO</th>
              <th className="px-4 py-3 font-semibold">Customer</th>
              <th className="px-4 py-3 font-semibold">Enquiry</th>
              <th className="px-4 py-3 font-semibold">Prepared by</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="px-4 py-3 text-right font-semibold">Total</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <TableSkeleton cols={6} />
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={6} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center gap-2">
                    <Truck className="h-8 w-8 text-slate-300" />
                    No delivery orders yet — open an accepted offer and click <span className="font-medium">Create Delivery Order</span>.
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
                  <td className="px-4 py-3 font-medium text-[#28364b]">{r.do_number}</td>
                  <td className="px-4 py-3 text-slate-700">{r.customer?.name || r.customer_name || "—"}</td>
                  <td className="px-4 py-3 text-slate-500">{r.rfq?.reference || "—"}</td>
                  <td className="px-4 py-3 text-slate-500">{r.creator?.name || "—"}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[r.status] || "bg-slate-100"}`}>{r.status}</span>
                  </td>
                  <td className="px-4 py-3 text-right font-medium text-[#28364b]">
                    {Number(r.subtotal).toFixed(2)} <span className="text-xs font-normal text-slate-400">{r.currency}</span>
                  </td>
                </motion.tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </motion.div>
  );
}
