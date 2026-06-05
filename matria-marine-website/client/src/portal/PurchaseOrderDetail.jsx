import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Download, Mail, Trash2, Plus, X, Save, Send, PackageCheck, Ban, Copy, CheckCircle2, Eye, Receipt } from "lucide-react";
import { toast } from "sonner";
import { purchaseOrdersAPI, purchaseInvoicesAPI } from "@/pages/api";
import { Spinner, PageLoader } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";
import DatePicker from "./ui/DatePicker";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  issued: "bg-blue-100 text-blue-700",
  received: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};

const cellInput = "w-full rounded border border-slate-200 px-2 py-1 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

export default function PurchaseOrderDetail({ params }) {
  const id = params.id;
  const confirm = useConfirm();
  const [, setLocation] = useLocation();

  const { data: po, isLoading, refetch } = useQuery({
    queryKey: ["purchase-order", id],
    queryFn: async () => (await purchaseOrdersAPI.get(id)).data.data,
  });

  const [items, setItems] = useState([]);
  const [notes, setNotes] = useState("");
  const [expected, setExpected] = useState("");

  useEffect(() => {
    if (!po) return;
    setItems((po.items || []).map((it) => ({
      id: it.id,
      description: it.description,
      unit: it.unit || "",
      qty: String(Number(it.qty)),
      unit_cost: String(Number(it.unit_cost)),
    })));
    setNotes(po.notes || "");
    setExpected(po.expected_date ? String(po.expected_date).slice(0, 10) : "");
  }, [po]);

  const isDraft = po?.status === "draft";
  const isClosed = po?.status === "received" || po?.status === "cancelled";

  const save = useMutation({
    mutationFn: () =>
      purchaseOrdersAPI.update(id, {
        notes,
        expected_date: expected || null,
        ...(isDraft
          ? {
              items: items.map((it) => ({
                id: it.id,
                description: it.description,
                unit: it.unit || null,
                qty: Number(it.qty) || 0,
                unit_cost: Number(it.unit_cost) || 0,
              })),
            }
          : {}),
      }),
    onSuccess: () => { toast.success("Purchase order saved."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save."),
  });

  const setStatus = useMutation({
    mutationFn: (status) => purchaseOrdersAPI.update(id, { status }),
    onSuccess: () => { toast.success("Status updated."); refetch(); },
    onError: () => toast.error("Could not update status."),
  });

  const emailPo = useMutation({
    mutationFn: () => purchaseOrdersAPI.email(id),
    onSuccess: (res) => { toast.success(res.data.message || "Emailed to vendor."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Email failed."),
  });

  const del = useMutation({
    mutationFn: () => purchaseOrdersAPI.remove(id),
    onSuccess: () => { toast.success("Purchase order deleted."); setLocation("/purchase-orders"); },
    onError: (e) => toast.error(e?.response?.data?.message || "Delete failed."),
  });

  const createInvoice = useMutation({
    mutationFn: () => purchaseInvoicesAPI.createFromPo(id),
    onSuccess: (res) => { toast.success("Draft invoice created from this PO."); setLocation(`/invoices/${res.data.data.id}`); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not create invoice."),
  });

  const downloadPdf = async () => {
    try {
      const res = await purchaseOrdersAPI.pdf(id);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${po.po_number}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download PDF.");
    }
  };

  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(`${window.location.origin}/po/${po.token}`);
      toast.success("Vendor acceptance link copied.");
    } catch {
      toast.error("Could not copy link.");
    }
  };

  const fmt = (iso) => {
    if (!iso) return "";
    try {
      return new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
    } catch {
      return iso;
    }
  };

  const handleEmail = async () => {
    const ok = await confirm({
      title: `Email ${po.po_number}?`,
      message: `Sends this order with a PDF to ${po.vendor?.email || "the vendor"}${isDraft ? " and marks it issued" : ""}.`,
      confirmText: "Send",
    });
    if (ok) emailPo.mutate();
  };
  const handleCancel = async () => {
    const ok = await confirm({ title: `Cancel ${po.po_number}?`, message: "The order will be marked cancelled.", confirmText: "Cancel order", tone: "danger" });
    if (ok) setStatus.mutate("cancelled");
  };
  const handleDelete = async () => {
    const ok = await confirm({ title: `Delete ${po.po_number}?`, message: "This permanently removes the draft purchase order.", confirmText: "Delete", tone: "danger" });
    if (ok) del.mutate();
  };

  if (isLoading || !po) return <PageLoader />;

  const grandTotal = items.reduce((s, it) => s + (Number(it.qty) || 0) * (Number(it.unit_cost) || 0), 0);
  const setItem = (idx, patch) => setItems((arr) => arr.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  const addItem = () => setItems((arr) => [...arr, { description: "", unit: "", qty: "1", unit_cost: "0" }]);
  const removeItem = (idx) => setItems((arr) => arr.filter((_, i) => i !== idx));

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <Link href="/purchase-orders" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> Back to purchase orders
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">
            {po.po_number}
            <span className={`ml-3 inline-flex rounded-full px-2 py-0.5 align-middle text-xs font-medium ${STATUS_STYLES[po.status] || "bg-slate-100"}`}>{po.status}</span>
          </h1>
          {po.rfq && (
            <p className="mt-1 text-sm text-slate-500">
              From enquiry{" "}
              <Link href={`/enquiries/${po.rfq_id}`} className="font-medium text-[#28364b] underline">{po.rfq.reference}</Link>
            </p>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          {(po.status === "issued" || po.status === "received") && (
            <button onClick={() => createInvoice.mutate()} disabled={createInvoice.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
              {createInvoice.isLoading ? <Spinner className="h-4 w-4" /> : <Receipt className="h-4 w-4" />} Create invoice
            </button>
          )}
          <button onClick={downloadPdf} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50">
            <Download className="h-4 w-4" /> PDF
          </button>
          <button onClick={handleEmail} disabled={emailPo.isLoading} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-60">
            {emailPo.isLoading ? <Spinner className="h-4 w-4" /> : <Mail className="h-4 w-4" />} Email vendor
          </button>
          <button onClick={copyLink} disabled={!po.token} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-60" title="Copy the vendor's acceptance link">
            <Copy className="h-4 w-4" /> Copy link
          </button>
          {isDraft && (
            <button onClick={handleDelete} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600">
              <Trash2 className="h-4 w-4" />
            </button>
          )}
        </div>
      </div>

      {po.accepted_at ? (
        <div className="flex items-start gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm">
          <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
          <div>
            <span className="font-semibold text-green-800">Accepted by vendor</span>
            <span className="text-green-700"> — {po.accepted_by_name || po.vendor?.name} on {fmt(po.accepted_at)}</span>
            {po.acceptance_note && <div className="mt-0.5 text-xs italic text-green-700">“{po.acceptance_note}”</div>}
          </div>
        </div>
      ) : po.opened_at ? (
        <div className="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Eye className="h-4 w-4 shrink-0" /> Vendor viewed this order on {fmt(po.opened_at)} — not yet accepted.
        </div>
      ) : (po.status === "issued" || po.status === "received") ? (
        <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500">
          <Eye className="h-4 w-4 shrink-0" /> Sent — the vendor hasn't opened the acceptance link yet.
        </div>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-2 text-xs font-bold uppercase tracking-wider text-slate-400">Supplier</h2>
          <div className="text-sm font-semibold text-[#28364b]">{po.vendor?.name}</div>
          {po.vendor?.contact_name && <div className="text-sm text-slate-600">{po.vendor.contact_name}</div>}
          {po.vendor?.email && <div className="text-sm text-slate-500">{po.vendor.email}</div>}
          {po.vendor?.phone && <div className="text-sm text-slate-500">{po.vendor.phone}</div>}
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-2">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Info label="Vessel" value={po.ship_name} />
            <Info label="Delivery port" value={po.delivery_port} />
            <Info label="Currency" value={`${po.currency}${po.currency !== po.base_currency ? ` · ×${Number(po.exchange_rate)} → ${po.base_currency}` : ""}`} />
            <Info label="Issued" value={po.issued_date ? String(po.issued_date).slice(0, 10) : "—"} />
            <div>
              <div className="text-xs font-bold uppercase tracking-wider text-slate-400">Expected delivery</div>
              <div className="mt-1">
                {isClosed ? (
                  <div className="text-sm text-[#28364b]">{expected || "—"}</div>
                ) : (
                  <DatePicker value={expected} onChange={setExpected} placeholder="Set date" />
                )}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Description</th>
              <th className="w-24 px-3 py-3 font-semibold">Unit</th>
              <th className="w-28 px-3 py-3 text-right font-semibold">Qty</th>
              <th className="w-32 px-3 py-3 text-right font-semibold">Unit cost</th>
              <th className="w-32 px-3 py-3 text-right font-semibold">Line total</th>
              {isDraft && <th className="w-10 px-2 py-3"></th>}
            </tr>
          </thead>
          <tbody>
            {items.map((it, idx) => (
              <tr key={it.id ?? `new-${idx}`} className="border-b border-slate-100 last:border-0">
                <td className="px-4 py-2">
                  {isDraft ? (
                    <input className={cellInput} value={it.description} onChange={(e) => setItem(idx, { description: e.target.value })} placeholder="Item description" />
                  ) : (
                    <span className="text-slate-700">{it.description}</span>
                  )}
                </td>
                <td className="px-3 py-2">
                  {isDraft ? (
                    <input className={cellInput} value={it.unit} onChange={(e) => setItem(idx, { unit: e.target.value })} placeholder="kg" />
                  ) : (
                    <span className="text-slate-600">{it.unit || "—"}</span>
                  )}
                </td>
                <td className="px-3 py-2 text-right">
                  {isDraft ? (
                    <input type="number" step="0.001" className={`${cellInput} text-right`} value={it.qty} onChange={(e) => setItem(idx, { qty: e.target.value })} />
                  ) : (
                    <span className="text-slate-600">{Number(it.qty)}</span>
                  )}
                </td>
                <td className="px-3 py-2 text-right">
                  {isDraft ? (
                    <input type="number" step="0.0001" className={`${cellInput} text-right`} value={it.unit_cost} onChange={(e) => setItem(idx, { unit_cost: e.target.value })} />
                  ) : (
                    <span className="text-slate-600">{Number(it.unit_cost).toFixed(2)}</span>
                  )}
                </td>
                <td className="px-3 py-2 text-right font-medium text-[#28364b]">
                  {((Number(it.qty) || 0) * (Number(it.unit_cost) || 0)).toFixed(2)}
                </td>
                {isDraft && (
                  <td className="px-2 py-2 text-center">
                    <button onClick={() => removeItem(idx)} className="text-slate-300 transition-colors hover:text-red-500" title="Remove line">
                      <X className="h-4 w-4" />
                    </button>
                  </td>
                )}
              </tr>
            ))}
            {items.length === 0 && (
              <tr><td colSpan={isDraft ? 6 : 5} className="py-8 text-center text-slate-400">No line items.</td></tr>
            )}
          </tbody>
          <tfoot>
            <tr className="border-t border-slate-200">
              <td colSpan={isDraft ? 4 : 4} className="px-4 py-3 text-right text-sm font-semibold text-slate-500">Total ({po.currency})</td>
              <td className="px-3 py-3 text-right text-base font-bold text-[#28364b]">{grandTotal.toFixed(2)}</td>
              {isDraft && <td></td>}
            </tr>
          </tfoot>
        </table>
        {isDraft && (
          <div className="border-t border-slate-100 p-3">
            <button onClick={addItem} className="inline-flex items-center gap-1 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-500 transition-colors hover:border-[#28364b] hover:text-[#28364b]">
              <Plus className="h-4 w-4" /> Add line
            </button>
          </div>
        )}
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <label className="text-xs font-bold uppercase tracking-wider text-slate-400">Notes</label>
        <textarea
          rows={2}
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          disabled={isClosed}
          placeholder="Internal notes or instructions for the vendor…"
          className="mt-1.5 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:bg-slate-50"
        />
      </div>

      {/* Action bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div className="flex flex-wrap gap-2">
          {po.status === "draft" && (
            <button onClick={() => setStatus.mutate("issued")} disabled={setStatus.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
              <Send className="h-4 w-4" /> Mark issued
            </button>
          )}
          {po.status === "issued" && (
            <button onClick={() => setStatus.mutate("received")} disabled={setStatus.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-green-700 disabled:opacity-70">
              <PackageCheck className="h-4 w-4" /> Mark received
            </button>
          )}
          {(po.status === "draft" || po.status === "issued") && (
            <button onClick={handleCancel} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600">
              <Ban className="h-4 w-4" /> Cancel order
            </button>
          )}
        </div>
        {!isClosed && (
          <button onClick={() => save.mutate()} disabled={save.isLoading} className="inline-flex items-center gap-1 rounded-lg border border-[#28364b] px-4 py-2 text-sm font-semibold text-[#28364b] transition-colors hover:bg-slate-50 disabled:opacity-70">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save changes
          </button>
        )}
      </div>
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
