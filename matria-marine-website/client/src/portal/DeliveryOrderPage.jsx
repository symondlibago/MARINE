import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Download, Save, MapPin, ShoppingCart } from "lucide-react";
import { toast } from "sonner";
import { deliveryOrdersAPI, purchaseOrdersAPI } from "@/pages/api";
import Select from "./ui/Select";
import DatePicker from "./ui/DatePicker";
import { Spinner, PageLoader } from "./ui/Loading";

const STATUSES = [
  { value: "draft", label: "Draft" },
  { value: "confirmed", label: "Confirmed" },
  { value: "delivered", label: "Delivered" },
  { value: "cancelled", label: "Cancelled" },
];
const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  confirmed: "bg-blue-100 text-blue-700",
  delivered: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};
const PO_BADGE = {
  draft: "bg-slate-100 text-slate-600",
  issued: "bg-blue-100 text-blue-700",
  received: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const ci = "w-full rounded border border-slate-200 px-2 py-1 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

export default function DeliveryOrderPage({ params }) {
  const id = params.id;

  const { data: doc, isLoading, refetch } = useQuery({
    queryKey: ["delivery-order", id],
    queryFn: async () => (await deliveryOrdersAPI.get(id)).data.data,
  });

  const [header, setHeader] = useState({ delivery_address: "", customer_reference: "", order_date: "", readiness_date: "", status: "draft", notes: "" });
  const [items, setItems] = useState([]);

  useEffect(() => {
    if (!doc) return;
    setHeader({
      delivery_address: doc.delivery_address || "",
      customer_reference: doc.customer_reference || "",
      order_date: doc.order_date ? String(doc.order_date).slice(0, 10) : "",
      readiness_date: doc.readiness_date ? String(doc.readiness_date).slice(0, 10) : "",
      status: doc.status || "draft",
      notes: doc.notes || "",
    });
    setItems((doc.items || []).map((it) => ({ id: it.id, description: it.description, code: it.code || "", unit: it.unit || "", qty: Number(it.qty), unit_price: Number(it.unit_price), discount_amount: Number(it.discount_amount) })));
  }, [doc]);

  const setH = (k, v) => setHeader((h) => ({ ...h, [k]: v }));
  const setItem = (idx, patch) => setItems((arr) => arr.map((it, i) => (i === idx ? { ...it, ...patch } : it)));

  const rows = items.map((it) => {
    const net = (Number(it.unit_price) || 0) - (Number(it.discount_amount) || 0);
    return { ...it, line_total: net * (Number(it.qty) || 0) };
  });
  const total = rows.reduce((s, r) => s + r.line_total, 0);

  const save = useMutation({
    mutationFn: () =>
      deliveryOrdersAPI.update(id, {
        delivery_address: header.delivery_address || null,
        customer_reference: header.customer_reference || null,
        order_date: header.order_date || null,
        readiness_date: header.readiness_date || null,
        status: header.status,
        notes: header.notes || null,
        items: items.map((it) => ({ id: it.id, qty: Number(it.qty) || 0 })),
      }),
    onSuccess: () => { toast.success("Delivery order saved."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save."),
  });

  const downloadBlob = async (fetcher, filename, errMsg) => {
    try {
      const res = await fetcher();
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = filename;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error(errMsg);
    }
  };
  const downloadPdf = () => downloadBlob(() => deliveryOrdersAPI.pdf(id), `${doc.do_number}.pdf`, "Could not download PDF.");
  const downloadProforma = () => downloadBlob(() => deliveryOrdersAPI.proforma(id), `proforma-${doc.do_number}.pdf`, "Could not download proforma invoice.");

  // Purchase orders for this enquiry.
  const { data: pos, refetch: refetchPos } = useQuery({
    queryKey: ["do-pos", doc?.rfq_id],
    queryFn: async () => (await purchaseOrdersAPI.list({ rfq_id: doc.rfq_id })).data.data,
    enabled: !!doc?.rfq_id,
  });

  const generatePos = useMutation({
    mutationFn: () => purchaseOrdersAPI.generate(doc.rfq_id, { delivery_address: header.delivery_address || null }),
    onSuccess: (res) => { toast.success(res.data.message || "Purchase orders generated."); refetchPos(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not generate purchase orders."),
  });

  if (isLoading || !doc) return <PageLoader />;

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <Link href={doc.offer_id ? `/offers/${doc.offer_id}` : "/delivery-orders"} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> {doc.offer_id ? "Back to offer" : "Back to delivery orders"}
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">
            {doc.do_number}
            <span className={`ml-3 inline-flex rounded-full px-2 py-0.5 align-middle text-xs font-medium ${STATUS_STYLES[header.status] || "bg-slate-100"}`}>{header.status}</span>
          </h1>
          <p className="mt-1 text-sm text-slate-500">Order for <span className="font-medium text-[#28364b]">{doc.customer_name || "—"}</span></p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button onClick={downloadPdf} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50" title="Quantities only — no prices">
            <Download className="h-4 w-4" /> Delivery Order PDF
          </button>
          <button onClick={downloadProforma} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50" title="Priced proforma invoice for this delivery order">
            <Download className="h-4 w-4" /> Proforma Invoice
          </button>
          <button onClick={() => save.mutate()} disabled={save.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
          </button>
        </div>
      </div>

      {/* Delivery address — the heart of the DO */}
      <div className="rounded-xl border border-[#28364b]/20 bg-[#28364b]/[0.03] p-5">
        <div className="mb-1.5 flex items-center gap-2">
          <MapPin className="h-4 w-4 text-[#28364b]" />
          <h2 className="text-sm font-semibold uppercase tracking-wide text-[#28364b]">Deliver to (customer address)</h2>
        </div>
        <p className="mb-2 text-xs text-slate-500">This is where the vendor delivers the goods. It carries onto the purchase order.</p>
        <textarea rows={3} value={header.delivery_address} onChange={(e) => setH("delivery_address", e.target.value)} placeholder="Vessel / company name, port, address…" className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]" />
      </div>

      {/* Order details */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Customer ref"><input className={ci.replace("px-2 py-1", "px-3 py-2") + " w-full"} value={header.customer_reference} onChange={(e) => setH("customer_reference", e.target.value)} /></Field>
          <Field label="Order date"><DatePicker value={header.order_date} onChange={(v) => setH("order_date", v)} /></Field>
          <Field label="Readiness date"><DatePicker value={header.readiness_date} onChange={(v) => setH("readiness_date", v)} /></Field>
          <Field label="Status"><Select value={header.status} onChange={(v) => setH("status", v)} options={STATUSES} /></Field>
        </div>
      </div>

      {/* Line items */}
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Description</th>
              <th className="w-24 px-2 py-3 font-semibold">Code</th>
              <th className="w-20 px-2 py-3 font-semibold">Unit</th>
              <th className="w-24 px-2 py-3 text-right font-semibold">Qty</th>
              <th className="w-28 px-2 py-3 text-right font-semibold">Unit Price</th>
              <th className="w-32 px-4 py-3 text-right font-semibold">Amount</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r, idx) => (
              <tr key={r.id} className="border-b border-slate-100 last:border-0">
                <td className="px-4 py-2 text-slate-700">{r.description}</td>
                <td className="px-2 py-2 text-slate-500">{r.code || "—"}</td>
                <td className="px-2 py-2 text-slate-500">{r.unit || "—"}</td>
                <td className="px-2 py-2"><input type="number" step="0.001" className={`${ci} text-right`} value={r.qty} onChange={(e) => setItem(idx, { qty: e.target.value })} /></td>
                <td className="px-2 py-2 text-right text-slate-600">{money(r.unit_price)}</td>
                <td className="px-4 py-2 text-right font-semibold text-[#28364b]">{money(r.line_total)}</td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={6} className="py-8 text-center text-slate-400">No line items.</td></tr>}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-slate-200 bg-slate-50">
              <td colSpan={5} className="px-4 py-3 text-right text-sm font-semibold text-slate-500">Total ({doc.currency})</td>
              <td className="px-4 py-3 text-right text-base font-bold text-[#28364b]">{money(total)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      {/* Notes */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <label className="text-xs font-bold uppercase tracking-wider text-slate-400">Notes</label>
        <textarea rows={2} value={header.notes} onChange={(e) => setH("notes", e.target.value)} className="mt-1.5 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
      </div>

      {/* Purchase orders to the vendor */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Purchase Orders to vendor</h2>
            <p className="text-xs text-slate-400">Creates one PO per awarded vendor, stamped with the delivery address above.</p>
          </div>
          <button onClick={() => generatePos.mutate()} disabled={generatePos.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
            {generatePos.isLoading ? <Spinner className="h-4 w-4" /> : <ShoppingCart className="h-4 w-4" />} Generate Purchase Orders
          </button>
        </div>
        {pos && pos.length > 0 ? (
          <div className="space-y-1.5">
            {pos.map((po) => (
              <Link key={po.id} href={`/purchase-orders/${po.id}`} className="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm transition-colors hover:bg-slate-50">
                <span className="font-medium text-[#28364b]">{po.po_number} · {po.vendor}</span>
                <span className="flex items-center gap-2 text-xs text-slate-500">
                  {Number(po.subtotal).toFixed(2)} {po.currency}
                  <span className={`rounded-full px-2 py-0.5 ${PO_BADGE[po.status] || "bg-slate-100"}`}>{po.status}</span>
                </span>
              </Link>
            ))}
          </div>
        ) : (
          <p className="text-sm text-slate-400">No purchase orders yet — set the delivery address, then generate.</p>
        )}
      </div>
    </motion.div>
  );
}

function Field({ label, children }) {
  return (
    <div className="space-y-1.5">
      <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">{label}</label>
      {children}
    </div>
  );
}
