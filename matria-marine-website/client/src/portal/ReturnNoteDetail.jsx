import { useMutation, useQuery } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Download, Mail, Trash2, Undo2, CheckCircle2 } from "lucide-react";
import { toast } from "sonner";
import { returnNotesAPI } from "@/pages/api";
import { Spinner, PageLoader } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  issued: "bg-blue-100 text-blue-700",
};

export default function ReturnNoteDetail({ params }) {
  const id = params.id;
  const confirm = useConfirm();
  const [, setLocation] = useLocation();

  const { data: rn, isLoading, refetch } = useQuery({
    queryKey: ["return-note", id],
    queryFn: async () => (await returnNotesAPI.get(id)).data.data,
  });

  const setStatus = useMutation({
    mutationFn: (status) => returnNotesAPI.update(id, { status }),
    onSuccess: () => { toast.success("Return note updated."); refetch(); },
    onError: () => toast.error("Could not update."),
  });

  const emailRn = useMutation({
    mutationFn: () => returnNotesAPI.email(id),
    onSuccess: (res) => { toast.success(res.data.message || "Emailed to vendor."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Email failed."),
  });

  const del = useMutation({
    mutationFn: () => returnNotesAPI.remove(id),
    onSuccess: () => { toast.success("Return note deleted."); setLocation("/return-notes"); },
    onError: (e) => toast.error(e?.response?.data?.message || "Delete failed."),
  });

  const downloadPdf = async () => {
    try {
      const res = await returnNotesAPI.pdf(id);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${rn.rtn_number}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download PDF.");
    }
  };

  const handleEmail = async () => {
    const ok = await confirm({
      title: `Email ${rn.rtn_number}?`,
      message: `Sends this return note with a PDF to ${rn.vendor?.email || "the vendor"}${rn.status === "draft" ? " and marks it issued" : ""}.`,
      confirmText: "Send",
    });
    if (ok) emailRn.mutate();
  };
  const handleDelete = async () => {
    const ok = await confirm({ title: `Delete ${rn.rtn_number}?`, message: "This permanently removes the draft return note.", confirmText: "Delete", tone: "danger" });
    if (ok) del.mutate();
  };

  if (isLoading || !rn) return <PageLoader />;

  const isDraft = rn.status === "draft";

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <Link href="/return-notes" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> Back to return notes
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">
            {rn.rtn_number}
            <span className={`ml-3 inline-flex rounded-full px-2 py-0.5 align-middle text-xs font-medium ${STATUS_STYLES[rn.status] || "bg-slate-100"}`}>{rn.status}</span>
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Against PO{" "}
            {rn.purchase_order_id ? (
              <Link href={`/purchase-orders/${rn.purchase_order_id}`} className="font-medium text-[#28364b] underline">{rn.purchase_order?.po_number || "—"}</Link>
            ) : "—"}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button onClick={downloadPdf} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50">
            <Download className="h-4 w-4" /> PDF
          </button>
          <button onClick={handleEmail} disabled={emailRn.isLoading} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-60">
            {emailRn.isLoading ? <Spinner className="h-4 w-4" /> : <Mail className="h-4 w-4" />} Email vendor
          </button>
          {isDraft && (
            <>
              <button onClick={() => setStatus.mutate("issued")} disabled={setStatus.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
                <CheckCircle2 className="h-4 w-4" /> Mark issued
              </button>
              <button onClick={handleDelete} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600">
                <Trash2 className="h-4 w-4" />
              </button>
            </>
          )}
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-2 text-xs font-bold uppercase tracking-wider text-slate-400">Supplier</h2>
          <div className="text-sm font-semibold text-[#28364b]">{rn.vendor_name || rn.vendor?.name}</div>
          {rn.vendor?.email && <div className="text-sm text-slate-500">{rn.vendor.email}</div>}
          {rn.vendor?.phone && <div className="text-sm text-slate-500">{rn.vendor.phone}</div>}
        </div>
        <div className="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-2">
          <div className="grid gap-4 sm:grid-cols-3">
            <Info label="Return date" value={rn.return_date ? String(rn.return_date).slice(0, 10) : "—"} />
            <Info label="Currency" value={rn.currency} />
            <Info label="Total credit" value={`${Number(rn.subtotal).toFixed(2)} ${rn.currency}`} />
          </div>
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Description</th>
              <th className="px-4 py-3 font-semibold">Reason</th>
              <th className="w-24 px-3 py-3 text-right font-semibold">Qty</th>
              <th className="w-32 px-3 py-3 text-right font-semibold">Unit cost</th>
              <th className="w-32 px-3 py-3 text-right font-semibold">Credit</th>
            </tr>
          </thead>
          <tbody>
            {(rn.items || []).map((it) => (
              <tr key={it.id} className="border-b border-slate-100 last:border-0">
                <td className="px-4 py-2.5 text-slate-700">{it.description}{it.unit ? <span className="text-slate-400"> ({it.unit})</span> : null}</td>
                <td className="px-4 py-2.5 text-slate-600">{it.reason || "—"}</td>
                <td className="px-3 py-2.5 text-right text-slate-600">{Number(it.qty)}</td>
                <td className="px-3 py-2.5 text-right text-slate-600">{Number(it.unit_cost).toFixed(2)}</td>
                <td className="px-3 py-2.5 text-right font-medium text-amber-700">{Number(it.line_total).toFixed(2)}</td>
              </tr>
            ))}
            {(rn.items || []).length === 0 && (
              <tr><td colSpan={5} className="py-8 text-center text-slate-400">No returned items.</td></tr>
            )}
          </tbody>
          <tfoot>
            <tr className="border-t border-slate-200">
              <td colSpan={4} className="px-4 py-3 text-right text-sm font-semibold text-slate-500">Total credit ({rn.currency})</td>
              <td className="px-3 py-3 text-right text-base font-bold text-amber-700">{Number(rn.subtotal).toFixed(2)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      {rn.notes && (
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <div className="text-xs font-bold uppercase tracking-wider text-slate-400">Notes</div>
          <p className="mt-1 text-sm text-slate-700">{rn.notes}</p>
        </div>
      )}

      <p className="flex items-center gap-1.5 text-xs text-slate-400">
        <Undo2 className="h-3.5 w-3.5" /> Editing the returned quantities is done on the purchase order, then re-saved here.
      </p>
    </motion.div>
  );
}

function Info({ label, value }) {
  return (
    <div>
      <div className="text-xs font-bold uppercase tracking-wider text-slate-400">{label}</div>
      <div className="mt-1 text-sm text-[#28364b]">{value || "—"}</div>
    </div>
  );
}
