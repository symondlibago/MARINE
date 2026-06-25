import { useQuery } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { Plus } from "lucide-react";
import { rfqsAPI } from "@/pages/api";
import { TableSkeleton } from "./ui/Loading";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  sent: "bg-blue-100 text-blue-700",
  quoting: "bg-amber-100 text-amber-700",
  awarded: "bg-violet-100 text-violet-700",
  closed: "bg-green-100 text-green-700",
};

export default function Enquiries() {
  const [, setLocation] = useLocation();
  const { data, isLoading } = useQuery({
    queryKey: ["rfqs"],
    queryFn: async () => (await rfqsAPI.list()).data.data,
  });
  const rows = data ?? [];

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Enquiries</h1>
          <p className="text-sm text-slate-500">{rows.length} enquir{rows.length === 1 ? "y" : "ies"}</p>
        </div>
        <Link href="/enquiries/new" className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63]">
          <Plus className="h-4 w-4" /> New Enquiry
        </Link>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Reference</th>
              <th className="px-4 py-3 font-semibold">Customer</th>
              <th className="px-4 py-3 font-semibold">Vessel</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="px-4 py-3 text-right font-semibold">Items</th>
              <th className="px-4 py-3 text-right font-semibold">Vendors</th>
              <th className="px-4 py-3 text-right font-semibold">Quotes</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <TableSkeleton cols={7} />
            ) : rows.length === 0 ? (
              <tr><td colSpan={7} className="py-10 text-center text-slate-400">No enquiries yet — create your first one.</td></tr>
            ) : (
              rows.map((r, i) => (
                <motion.tr
                  key={r.id}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: Math.min(i * 0.02, 0.3) }}
                  onClick={() => setLocation(`/enquiries/${r.id}`)}
                  className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-4 py-3 font-medium text-[#28364b]">{r.reference}</td>
                  <td className="px-4 py-3 text-slate-700">{r.customer?.name || "—"}</td>
                  <td className="px-4 py-3 text-slate-700">{r.ship_name || "—"}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[r.status] || "bg-slate-100"}`}>{r.status}</span>
                  </td>
                  <td className="px-4 py-3 text-right text-slate-600">{r.items_count}</td>
                  <td className="px-4 py-3 text-right text-slate-600">{r.rfq_vendors_count}</td>
                  <td className="px-4 py-3 text-right text-slate-600">{r.quotes_count}</td>
                </motion.tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </motion.div>
  );
}
