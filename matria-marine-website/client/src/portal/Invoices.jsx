import { useQuery, useMutation } from "@tanstack/react-query";
import { useLocation } from "wouter";
import { motion } from "framer-motion";
import { Receipt, Plus, CheckCircle2 } from "lucide-react";
import { toast } from "sonner";
import { invoicesAPI } from "@/pages/api";
import { TableSkeleton } from "./ui/Loading";
import { Spinner } from "./ui/Loading";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  sent: "bg-blue-100 text-blue-700",
  paid: "bg-green-100 text-green-700",
};

// The linked document numbers for a job (QTN / DO / ProINV / PO / INV).
function RefTrail({ refs }) {
  if (!refs) return <span className="text-slate-300">—</span>;
  const chips = [];
  if (refs.qtn) chips.push(["QTN", refs.qtn]);
  if (refs.do) chips.push(["DO", refs.do]);
  if (refs.proforma) chips.push(["ProINV", refs.proforma]);
  (refs.po || []).forEach((po) => chips.push(["PO", po]));
  if (chips.length === 0) return <span className="text-slate-300">Direct — no enquiry</span>;
  return (
    <div className="flex flex-wrap gap-1">
      {chips.map(([label, val], i) => (
        <span key={i} className="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600" title={`${label}: ${val}`}>
          <span className="font-semibold text-slate-400">{label}</span> {val}
        </span>
      ))}
    </div>
  );
}

export default function Invoices() {
  const [, setLocation] = useLocation();
  const { data, isLoading, refetch } = useQuery({
    queryKey: ["invoices"],
    queryFn: async () => (await invoicesAPI.list()).data.data,
  });
  const rows = data ?? [];

  const createDirect = useMutation({
    mutationFn: () => invoicesAPI.createDirect(),
    onSuccess: (res) => {
      toast.success("Draft invoice created.");
      setLocation(`/invoices/${res.data.data.id}`);
    },
    onError: () => toast.error("Could not create the invoice."),
  });

  const markPaid = useMutation({
    mutationFn: (invId) => invoicesAPI.update(invId, { status: "paid" }),
    onSuccess: () => { toast.success("Marked as paid."); refetch(); },
    onError: () => toast.error("Could not update the payment status."),
  });

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Invoices</h1>
          <p className="text-sm text-slate-500">Customer invoices and the full document trail for each job. Create a direct invoice when Matria supplies the goods.</p>
        </div>
        <button
          onClick={() => createDirect.mutate()}
          disabled={createDirect.isLoading}
          className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70"
        >
          {createDirect.isLoading ? <Spinner className="h-4 w-4" /> : <Plus className="h-4 w-4" />} New Direct Invoice
        </button>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Invoice No.</th>
              <th className="px-4 py-3 font-semibold">Customer</th>
              <th className="px-4 py-3 font-semibold">Reference trail</th>
              <th className="px-4 py-3 font-semibold">Date</th>
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
                    <Receipt className="h-8 w-8 text-slate-300" />
                    No invoices yet — click <span className="font-medium">New Direct Invoice</span>, or create one from an accepted Offer.
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
                  onClick={() => setLocation(`/invoices/${r.id}`)}
                  className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-4 py-3 font-medium text-[#28364b]">
                    {r.invoice_number}
                    {r.is_direct && <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">Direct</span>}
                  </td>
                  <td className="px-4 py-3 text-slate-700">{r.customer_name || "—"}</td>
                  <td className="px-4 py-3"><RefTrail refs={r.references} /></td>
                  <td className="px-4 py-3 text-slate-500">{r.issue_date || "—"}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[r.status] || "bg-slate-100"}`}>{r.status}</span>
                      {r.status !== "paid" && (
                        <button
                          onClick={(e) => { e.stopPropagation(); markPaid.mutate(r.id); }}
                          disabled={markPaid.isLoading}
                          title="Customer paid — mark as paid"
                          className="inline-flex items-center gap-1 rounded-md border border-green-300 bg-green-50 px-2 py-0.5 text-[11px] font-medium text-green-700 transition-colors hover:bg-green-100 disabled:opacity-60"
                        >
                          <CheckCircle2 className="h-3.5 w-3.5" /> Mark paid
                        </button>
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right font-medium text-[#28364b]">
                    {Number(r.grand_total).toFixed(2)} <span className="text-xs font-normal text-slate-400">{r.currency}</span>
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
