import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Download, Trash2, Plus, X, Save, CheckCircle2, Ban, AlertTriangle, RotateCcw } from "lucide-react";
import { toast } from "sonner";
import { purchaseInvoicesAPI } from "@/pages/api";
import { Spinner, PageLoader } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";
import DatePicker from "./ui/DatePicker";
import Select from "./ui/Select";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  approved: "bg-blue-100 text-blue-700",
  exported: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};

const cellInput = "w-full rounded border border-slate-200 px-2 py-1 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

async function blobErrorMessage(e, fallback) {
  try {
    const text = await e?.response?.data?.text?.();
    if (text) return JSON.parse(text).message || fallback;
  } catch {
    // ignore
  }
  return fallback;
}

export default function PurchaseInvoiceDetail({ params }) {
  const id = params.id;
  const confirm = useConfirm();
  const [, setLocation] = useLocation();

  const { data: inv, isLoading, refetch } = useQuery({
    queryKey: ["purchase-invoice", id],
    queryFn: async () => (await purchaseInvoicesAPI.get(id)).data.data,
  });

  const [items, setItems] = useState([]);
  const [hdr, setHdr] = useState({ vendor_invoice_no: "", invoice_date: "", due_date: "", currency: "USD", exchange_rate: "1", other_charges: "0", notes: "" });
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    if (!inv) return;
    setItems((inv.items || []).map((it) => ({
      id: it.id,
      description: it.description,
      unit: it.unit || "",
      qty: String(Number(it.qty)),
      unit_cost: String(Number(it.unit_cost)),
      account_code: it.account_code || "",
      po_qty: it.po_qty == null ? null : Number(it.po_qty),
      po_unit_cost: it.po_unit_cost == null ? null : Number(it.po_unit_cost),
    })));
    setHdr({
      vendor_invoice_no: inv.vendor_invoice_no || "",
      invoice_date: inv.invoice_date ? String(inv.invoice_date).slice(0, 10) : "",
      due_date: inv.due_date ? String(inv.due_date).slice(0, 10) : "",
      currency: inv.currency || "USD",
      exchange_rate: String(Number(inv.exchange_rate ?? 1)),
      other_charges: String(Number(inv.other_charges ?? 0)),
      notes: inv.notes || "",
    });
  }, [inv]);

  const locked = inv?.status === "exported" || inv?.status === "cancelled";
  const setH = (k, v) => setHdr((h) => ({ ...h, [k]: v }));

  const save = useMutation({
    mutationFn: () =>
      purchaseInvoicesAPI.update(id, {
        vendor_invoice_no: hdr.vendor_invoice_no || null,
        invoice_date: hdr.invoice_date || null,
        due_date: hdr.due_date || null,
        currency: hdr.currency,
        exchange_rate: Number(hdr.exchange_rate) || 0,
        other_charges: Number(hdr.other_charges) || 0,
        notes: hdr.notes || null,
        items: items.map((it) => ({
          id: it.id,
          description: it.description,
          unit: it.unit || null,
          qty: Number(it.qty) || 0,
          unit_cost: Number(it.unit_cost) || 0,
          account_code: it.account_code || null,
        })),
      }),
    onSuccess: () => { toast.success("Invoice saved."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save."),
  });

  const setStatus = useMutation({
    mutationFn: (status) => purchaseInvoicesAPI.update(id, { status }),
    onSuccess: () => { toast.success("Status updated."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not update status."),
  });

  const del = useMutation({
    mutationFn: () => purchaseInvoicesAPI.remove(id),
    onSuccess: () => { toast.success("Invoice deleted."); setLocation("/invoices"); },
    onError: (e) => toast.error(e?.response?.data?.message || "Delete failed."),
  });

  const exportThis = async () => {
    setExporting(true);
    try {
      const res = await purchaseInvoicesAPI.export({ ids: [Number(id)] });
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${inv.reference}-navision.csv`;
      a.click();
      URL.revokeObjectURL(url);
      toast.success("Exported to Navision CSV.");
      refetch();
    } catch (e) {
      toast.error(await blobErrorMessage(e, "Export failed."));
    } finally {
      setExporting(false);
    }
  };

  const handleApprove = async () => {
    const ok = await confirm({ title: `Approve ${inv.reference}?`, message: "Approved invoices are ready to export to Navision.", confirmText: "Approve" });
    if (ok) setStatus.mutate("approved");
  };
  const handleCancel = async () => {
    const ok = await confirm({ title: `Cancel ${inv.reference}?`, message: "The invoice will be marked cancelled.", confirmText: "Cancel invoice", tone: "danger" });
    if (ok) setStatus.mutate("cancelled");
  };
  const handleDelete = async () => {
    const ok = await confirm({ title: `Delete ${inv.reference}?`, message: "This permanently removes the invoice.", confirmText: "Delete", tone: "danger" });
    if (ok) del.mutate();
  };

  if (isLoading || !inv) return <PageLoader />;

  const subtotal = items.reduce((s, it) => s + (Number(it.qty) || 0) * (Number(it.unit_cost) || 0), 0);
  const total = subtotal + (Number(hdr.other_charges) || 0);
  const po = inv.purchase_order;
  const poTotal = po ? Number(po.subtotal) : null;
  const variance = poTotal == null ? null : total - poTotal;
  const hasVariance = variance != null && Math.abs(variance) > 0.005;

  const setItem = (idx, patch) => setItems((arr) => arr.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  const addItem = () => setItems((arr) => [...arr, { description: "", unit: "", qty: "1", unit_cost: "0", account_code: "", po_qty: null, po_unit_cost: null }]);
  const removeItem = (idx) => setItems((arr) => arr.filter((_, i) => i !== idx));

  const qtyMismatch = (it) => it.po_qty != null && Number(it.qty) !== it.po_qty;
  const costMismatch = (it) => it.po_unit_cost != null && Number(it.unit_cost) !== it.po_unit_cost;

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <Link href="/invoices" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> Back to invoices
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">
            {inv.reference}
            <span className={`ml-3 inline-flex rounded-full px-2 py-0.5 align-middle text-xs font-medium ${STATUS_STYLES[inv.status] || "bg-slate-100"}`}>{inv.status}</span>
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            {po && <>From <Link href={`/purchase-orders/${inv.purchase_order_id}`} className="font-medium text-[#28364b] underline">{po.po_number}</Link></>}
            {inv.rfq && <> · enquiry <Link href={`/enquiries/${inv.rfq_id}`} className="font-medium text-[#28364b] underline">{inv.rfq.reference}</Link></>}
            {inv.exported_at && <> · exported {String(inv.exported_at).slice(0, 10)}</>}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {(inv.status === "approved" || inv.status === "exported") && (
            <button onClick={exportThis} disabled={exporting} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-60">
              {exporting ? <Spinner className="h-4 w-4" /> : <Download className="h-4 w-4" />} {inv.status === "exported" ? "Re-export CSV" : "Export CSV"}
            </button>
          )}
          {inv.status !== "exported" && (
            <button onClick={handleDelete} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600">
              <Trash2 className="h-4 w-4" />
            </button>
          )}
        </div>
      </div>

      {/* Vendor + invoice header */}
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-2 text-xs font-bold uppercase tracking-wider text-slate-400">Vendor</h2>
          <div className="text-sm font-semibold text-[#28364b]">{inv.vendor?.name}</div>
          {inv.vendor?.contact_name && <div className="text-sm text-slate-600">{inv.vendor.contact_name}</div>}
          <div className="mt-1 text-xs text-slate-500">
            Navision No.: <span className={inv.vendor?.nav_code ? "font-medium text-[#28364b]" : "text-amber-600"}>{inv.vendor?.nav_code || "not set"}</span>
          </div>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-5 lg:col-span-2">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Labeled label="Vendor invoice no.">
              {locked ? <Val>{hdr.vendor_invoice_no}</Val> : <input className={cellInput} value={hdr.vendor_invoice_no} onChange={(e) => setH("vendor_invoice_no", e.target.value)} placeholder="their no." />}
            </Labeled>
            <Labeled label="Currency">
              {locked ? <Val>{hdr.currency}</Val> : <Select value={hdr.currency} onChange={(v) => setH("currency", v)} options={CURRENCIES} />}
            </Labeled>
            <Labeled label={`Exch. rate → ${inv.base_currency}`}>
              {locked ? <Val>{hdr.exchange_rate}</Val> : <input type="number" step="0.0001" className={cellInput} value={hdr.exchange_rate} onChange={(e) => setH("exchange_rate", e.target.value)} />}
            </Labeled>
            <Labeled label="Invoice date">
              {locked ? <Val>{hdr.invoice_date || "—"}</Val> : <DatePicker value={hdr.invoice_date} onChange={(v) => setH("invoice_date", v)} placeholder="Set date" />}
            </Labeled>
            <Labeled label="Due date">
              {locked ? <Val>{hdr.due_date || "—"}</Val> : <DatePicker value={hdr.due_date} onChange={(v) => setH("due_date", v)} placeholder="Set date" />}
            </Labeled>
            <Labeled label="Other charges">
              {locked ? <Val>{Number(hdr.other_charges).toFixed(2)}</Val> : <input type="number" step="0.01" className={cellInput} value={hdr.other_charges} onChange={(e) => setH("other_charges", e.target.value)} />}
            </Labeled>
          </div>
        </div>
      </div>

      {/* 3-way match banner */}
      {poTotal != null && (
        hasVariance ? (
          <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
            <div>
              <span className="font-semibold">Doesn't match the purchase order.</span> Invoice total {total.toFixed(2)} {inv.currency} vs PO {poTotal.toFixed(2)} {po.currency} — difference <span className="font-semibold">{variance > 0 ? "+" : ""}{variance.toFixed(2)}</span>. Check the highlighted lines before approving.
            </div>
          </div>
        ) : (
          <div className="flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            <CheckCircle2 className="h-4 w-4 shrink-0" /> Matches the purchase order total ({poTotal.toFixed(2)} {po.currency}).
          </div>
        )
      )}

      {/* Lines */}
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Description</th>
              <th className="w-20 px-3 py-3 font-semibold">Unit</th>
              <th className="w-28 px-3 py-3 text-right font-semibold">Qty</th>
              <th className="w-32 px-3 py-3 text-right font-semibold">Unit cost</th>
              <th className="w-28 px-3 py-3 font-semibold">G/L acct</th>
              <th className="w-32 px-3 py-3 text-right font-semibold">Line total</th>
              {!locked && <th className="w-10 px-2 py-3"></th>}
            </tr>
          </thead>
          <tbody>
            {items.map((it, idx) => (
              <tr key={it.id ?? `new-${idx}`} className="border-b border-slate-100 last:border-0 align-top">
                <td className="px-4 py-2">
                  {locked ? <span className="text-slate-700">{it.description}</span> : <input className={cellInput} value={it.description} onChange={(e) => setItem(idx, { description: e.target.value })} placeholder="Item description" />}
                </td>
                <td className="px-3 py-2">
                  {locked ? <span className="text-slate-600">{it.unit || "—"}</span> : <input className={cellInput} value={it.unit} onChange={(e) => setItem(idx, { unit: e.target.value })} placeholder="kg" />}
                </td>
                <td className="px-3 py-2 text-right">
                  {locked ? (
                    <span className="text-slate-600">{Number(it.qty)}</span>
                  ) : (
                    <input type="number" step="0.001" className={`${cellInput} text-right ${qtyMismatch(it) ? "border-amber-400 bg-amber-50" : ""}`} value={it.qty} onChange={(e) => setItem(idx, { qty: e.target.value })} />
                  )}
                  {qtyMismatch(it) && <div className="mt-0.5 text-[10px] text-amber-600">PO: {it.po_qty}</div>}
                </td>
                <td className="px-3 py-2 text-right">
                  {locked ? (
                    <span className="text-slate-600">{Number(it.unit_cost).toFixed(2)}</span>
                  ) : (
                    <input type="number" step="0.0001" className={`${cellInput} text-right ${costMismatch(it) ? "border-amber-400 bg-amber-50" : ""}`} value={it.unit_cost} onChange={(e) => setItem(idx, { unit_cost: e.target.value })} />
                  )}
                  {costMismatch(it) && <div className="mt-0.5 text-[10px] text-amber-600">PO: {it.po_unit_cost.toFixed(2)}</div>}
                </td>
                <td className="px-3 py-2">
                  {locked ? <span className="text-slate-500">{it.account_code || "—"}</span> : <input className={cellInput} value={it.account_code} onChange={(e) => setItem(idx, { account_code: e.target.value })} placeholder="default" />}
                </td>
                <td className="px-3 py-2 text-right font-medium text-[#28364b]">{((Number(it.qty) || 0) * (Number(it.unit_cost) || 0)).toFixed(2)}</td>
                {!locked && (
                  <td className="px-2 py-2 text-center">
                    <button onClick={() => removeItem(idx)} className="text-slate-300 transition-colors hover:text-red-500" title="Remove line"><X className="h-4 w-4" /></button>
                  </td>
                )}
              </tr>
            ))}
            {items.length === 0 && (
              <tr><td colSpan={locked ? 6 : 7} className="py-8 text-center text-slate-400">No line items.</td></tr>
            )}
          </tbody>
        </table>
        {!locked && (
          <div className="border-t border-slate-100 p-3">
            <button onClick={addItem} className="inline-flex items-center gap-1 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-500 transition-colors hover:border-[#28364b] hover:text-[#28364b]">
              <Plus className="h-4 w-4" /> Add line
            </button>
          </div>
        )}
      </div>

      {/* Totals */}
      <div className="flex justify-end">
        <div className="w-full max-w-xs space-y-1.5 rounded-xl border border-slate-200 bg-white p-4 text-sm">
          <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{subtotal.toFixed(2)} {inv.currency}</span></div>
          <div className="flex justify-between text-slate-500"><span>Other charges</span><span>{(Number(hdr.other_charges) || 0).toFixed(2)} {inv.currency}</span></div>
          <div className="flex justify-between border-t border-slate-200 pt-1.5 text-base font-bold text-[#28364b]"><span>Total</span><span>{total.toFixed(2)} {inv.currency}</span></div>
        </div>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <label className="text-xs font-bold uppercase tracking-wider text-slate-400">Notes</label>
        <textarea rows={2} value={hdr.notes} onChange={(e) => setH("notes", e.target.value)} disabled={locked} placeholder="Internal notes…" className="mt-1.5 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:bg-slate-50" />
      </div>

      {/* Action bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div className="flex flex-wrap gap-2">
          {inv.status === "draft" && (
            <button onClick={handleApprove} disabled={setStatus.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
              <CheckCircle2 className="h-4 w-4" /> Approve
            </button>
          )}
          {inv.status === "approved" && (
            <>
              <button onClick={exportThis} disabled={exporting} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
                {exporting ? <Spinner className="h-4 w-4" /> : <Download className="h-4 w-4" />} Export to Navision
              </button>
              <button onClick={() => setStatus.mutate("draft")} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50">
                <RotateCcw className="h-4 w-4" /> Revert to draft
              </button>
            </>
          )}
          {(inv.status === "draft" || inv.status === "approved") && (
            <button onClick={handleCancel} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600">
              <Ban className="h-4 w-4" /> Cancel
            </button>
          )}
        </div>
        {!locked && (
          <button onClick={() => save.mutate()} disabled={save.isLoading} className="inline-flex items-center gap-1 rounded-lg border border-[#28364b] px-4 py-2 text-sm font-semibold text-[#28364b] transition-colors hover:bg-slate-50 disabled:opacity-70">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save changes
          </button>
        )}
      </div>
    </motion.div>
  );
}

function Labeled({ label, children }) {
  return (
    <div className="space-y-1">
      <div className="text-xs font-bold uppercase tracking-wider text-slate-400">{label}</div>
      {children}
    </div>
  );
}

function Val({ children }) {
  return <div className="text-sm text-[#28364b]">{children || "—"}</div>;
}
