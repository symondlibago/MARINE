import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Plus, Trash2, Download, Mail, Save, Heading, CheckCircle2 } from "lucide-react";
import { toast } from "sonner";
import { invoicesAPI, customersAPI } from "@/pages/api";
import Select from "./ui/Select";
import EntityPicker from "./ui/EntityPicker";
import DatePicker from "./ui/DatePicker";
import { PageLoader, Spinner } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";

const CURRENCIES = ["SGD", "USD", "EUR", "AED", "PHP", "INR", "GBP", "JPY"];
const PAYMENT_TERMS = ["", "Prepayment", "Payable on receipt", "Net 7 days", "Net 14 days", "Net 30 days", "Net 60 days", "Net 90 days"];
const DELIVERY_TERMS = ["", "EXW — Ex Works", "FCA — Free Carrier", "FAS — Free Alongside Ship", "FOB — Free On Board", "CFR — Cost & Freight", "CIF — Cost, Insurance & Freight", "CPT — Carriage Paid To", "CIP — Carriage & Insurance Paid To", "DAP — Delivered At Place", "DDP — Delivered Duty Paid"];
const ORIGIN_TYPES = ["", "Genuine", "OEM", "Aftermarket", "Used", "Not Applicable"];
const STATUSES = [
  { value: "draft", label: "Draft" },
  { value: "sent", label: "Sent" },
  { value: "paid", label: "Paid" },
];

const ymd = (d) => (d ? String(d).slice(0, 10) : ""); // API dates come as ISO datetimes; the DatePicker wants yyyy-mm-dd

const input =
  "w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";
const cellInput =
  "rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

const blankLine = () => ({ is_heading: false, description: "", code: "", unit: "", qty: "", unit_price: "", remarks: "" });

export default function InvoicePage({ params }) {
  const id = params.id;
  const confirm = useConfirm();
  const [, setLocation] = useLocation();

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["invoice", id],
    queryFn: async () => (await invoicesAPI.get(id)).data.data,
  });

  const [form, setForm] = useState(null);
  const [lines, setLines] = useState([]);

  useEffect(() => {
    if (!data) return;
    setForm({
      customer_id: data.customer_id ? String(data.customer_id) : "",
      customer_name: data.customer_name ?? "",
      customer_address: data.customer_address ?? "",
      customer_reference: data.customer_reference ?? "",
      currency: data.currency ?? "SGD",
      status: data.status ?? "draft",
      issue_date: ymd(data.issue_date),
      due_date: ymd(data.due_date),
      payment_terms: data.payment_terms ?? "",
      delivery_terms: data.delivery_terms ?? "",
      origin_type: data.origin_type ?? "",
      packing_cost: data.packing_cost ?? 0,
      transportation_cost: data.transportation_cost ?? 0,
      tax_amount: data.tax_amount ?? 0,
      notes: data.notes ?? "",
    });
    setLines((data.items || []).map((it) => ({
      id: it.id,
      is_heading: !!it.is_heading,
      description: it.description ?? "",
      code: it.code ?? "",
      unit: it.unit ?? "",
      qty: it.is_heading ? "" : it.qty ?? "",
      unit_price: it.is_heading ? "" : it.unit_price ?? "",
      remarks: it.remarks ?? "",
    })));
  }, [data]);

  const save = useMutation({
    mutationFn: () => invoicesAPI.update(id, { ...form, customer_id: form.customer_id || null, items: lines }),
    onSuccess: () => { toast.success("Invoice saved."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the invoice."),
  });

  const emailIt = useMutation({
    mutationFn: () => invoicesAPI.email(id),
    onSuccess: (res) => { toast.success(res.data.message || "Invoice emailed."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not email the invoice."),
  });

  // Record (or undo) the customer's payment — this is what clears the invoice from A/R.
  const markPaid = useMutation({
    mutationFn: (status) => invoicesAPI.update(id, { status }),
    onSuccess: (_res, status) => { toast.success(status === "paid" ? "Marked as paid — cleared from receivables." : "Marked as unpaid."); refetch(); },
    onError: () => toast.error("Could not update the payment status."),
  });

  const del = useMutation({
    mutationFn: () => invoicesAPI.remove(id),
    onSuccess: () => { toast.success("Invoice deleted."); setLocation("/invoices"); },
    onError: () => toast.error("Could not delete."),
  });

  if (isLoading || !form) return <PageLoader />;

  const setField = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const setLine = (i, k, v) => setLines((ls) => ls.map((l, idx) => (idx === i ? { ...l, [k]: v } : l)));
  const addLine = (heading = false) => setLines((ls) => [...ls, { ...blankLine(), is_heading: heading }]);
  const removeLine = (i) => setLines((ls) => ls.filter((_, idx) => idx !== i));

  // Pick an existing customer → snapshot their name + address into the bill-to fields.
  const pickCustomer = (cid, entity) => {
    setForm((f) => ({
      ...f,
      customer_id: cid,
      customer_name: entity ? entity.name : f.customer_name,
      customer_address: entity && entity.address != null ? entity.address : f.customer_address,
    }));
  };

  const subtotal = lines.reduce((s, l) => (l.is_heading ? s : s + (Number(l.qty) || 0) * (Number(l.unit_price) || 0)), 0);
  const grand = subtotal + (Number(form.packing_cost) || 0) + (Number(form.transportation_cost) || 0) + (Number(form.tax_amount) || 0);
  const cur = form.currency;

  const downloadPdf = async () => {
    try {
      const res = await invoicesAPI.pdf(id);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${data.invoice_number}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download the PDF.");
    }
  };

  const handleDelete = async () => {
    if (await confirm({ title: "Delete this invoice?", message: "This cannot be undone.", confirmText: "Delete" })) del.mutate();
  };

  const refs = data.references || {};

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-6">
      <Link href="/invoices" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> Back to invoices
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Invoice {data.invoice_number}</h1>
          <div className="mt-1 flex flex-wrap gap-1 text-[11px] text-slate-500">
            {refs.qtn && <span className="rounded bg-slate-100 px-1.5 py-0.5">QTN {refs.qtn}</span>}
            {refs.do && <span className="rounded bg-slate-100 px-1.5 py-0.5">DO {refs.do}</span>}
            {refs.proforma && <span className="rounded bg-slate-100 px-1.5 py-0.5">ProINV {refs.proforma}</span>}
            {(refs.po || []).map((po) => <span key={po} className="rounded bg-slate-100 px-1.5 py-0.5">PO {po}</span>)}
            {!refs.qtn && <span className="rounded bg-amber-100 px-1.5 py-0.5 text-amber-700">Direct invoice</span>}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {data.status === "paid" ? (
            <button onClick={() => markPaid.mutate("sent")} disabled={markPaid.isLoading} title="Undo — mark as unpaid" className="inline-flex items-center gap-1 rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-100 disabled:opacity-70">
              {markPaid.isLoading ? <Spinner className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />} Paid{data.paid_at ? ` · ${ymd(data.paid_at)}` : ""}
            </button>
          ) : (
            <button onClick={() => markPaid.mutate("paid")} disabled={markPaid.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-70">
              {markPaid.isLoading ? <Spinner className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />} Mark as paid
            </button>
          )}
          <button onClick={() => save.mutate()} disabled={save.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-70">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
          </button>
          <button onClick={downloadPdf} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
            <Download className="h-4 w-4" /> PDF
          </button>
          <button onClick={() => emailIt.mutate()} disabled={emailIt.isLoading} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-70">
            {emailIt.isLoading ? <Spinner className="h-4 w-4" /> : <Mail className="h-4 w-4" />} Email customer
          </button>
          <button onClick={handleDelete} className="inline-flex items-center gap-1 rounded-lg border border-red-200 px-3 py-2 text-sm text-red-600 hover:bg-red-50">
            <Trash2 className="h-4 w-4" />
          </button>
        </div>
      </div>

      {/* Header fields */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Customer">
            <EntityPicker api={customersAPI} queryKey="customers" value={form.customer_id} onChange={pickCustomer} placeholder="— Select customer —" />
          </Field>
          <Field label="Bill-to name">
            <input className={input} value={form.customer_name} onChange={(e) => setField("customer_name", e.target.value)} placeholder="Customer / company name" />
          </Field>
          <Field label="Order no.">
            <input className={input} value={form.customer_reference} onChange={(e) => setField("customer_reference", e.target.value)} placeholder="Their PO / ref no." />
          </Field>
          <Field label="Bill-to address">
            <textarea className={input} rows={2} value={form.customer_address} onChange={(e) => setField("customer_address", e.target.value)} />
          </Field>
          <Field label="Currency">
            <Select value={form.currency} onChange={(v) => setField("currency", v)} options={CURRENCIES} />
          </Field>
          <Field label="Status">
            <Select value={form.status} onChange={(v) => setField("status", v)} options={STATUSES} />
          </Field>
          <Field label="Issue date">
            <DatePicker value={form.issue_date} onChange={(v) => setField("issue_date", v)} />
          </Field>
          <Field label="Due date">
            <DatePicker value={form.due_date} onChange={(v) => setField("due_date", v)} />
          </Field>
          <Field label="Payment terms">
            <Select value={form.payment_terms} onChange={(v) => setField("payment_terms", v)} options={PAYMENT_TERMS} placeholder="—" />
          </Field>
          <Field label="Delivery terms (Incoterms)">
            <Select value={form.delivery_terms} onChange={(v) => setField("delivery_terms", v)} options={DELIVERY_TERMS} placeholder="—" />
          </Field>
          <Field label="Origin">
            <Select value={form.origin_type} onChange={(v) => setField("origin_type", v)} options={ORIGIN_TYPES} placeholder="—" />
          </Field>
        </div>
      </div>

      {/* Line items */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="mb-3 flex items-center justify-between">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Line items</h2>
            <p className="text-xs text-slate-400">Add the goods/services you're billing. Use a heading to group them (e.g. "Lashing Materials").</p>
          </div>
          <div className="flex gap-2">
            <button type="button" onClick={() => addLine(false)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50"><Plus className="h-4 w-4" /> Add line</button>
            <button type="button" onClick={() => addLine(true)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50"><Heading className="h-4 w-4" /> Add heading</button>
          </div>
        </div>

        {lines.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-400">No lines yet — add an item or a section heading above.</p>
        ) : (
          <div className="space-y-2">
            {lines.map((l, i) => (
              <div key={l.id ?? `new-${i}`}>
                <div className="flex gap-2">
                  <input
                    className={cellInput + " flex-1 " + (l.is_heading ? "font-semibold text-[#28364b]" : "")}
                    value={l.description}
                    onChange={(e) => setLine(i, "description", e.target.value)}
                    placeholder={l.is_heading ? "Section heading" : "Description (type anything)"}
                  />
                  {!l.is_heading && (
                    <>
                      <input type="number" step="0.001" placeholder="Qty" className={cellInput + " w-24 shrink-0 text-right"} value={l.qty} onChange={(e) => setLine(i, "qty", e.target.value)} />
                      <input placeholder="Unit" className={cellInput + " w-20 shrink-0"} value={l.unit} onChange={(e) => setLine(i, "unit", e.target.value)} />
                      <input type="number" step="0.01" placeholder="Unit price" className={cellInput + " w-28 shrink-0 text-right"} value={l.unit_price} onChange={(e) => setLine(i, "unit_price", e.target.value)} />
                      <div className="flex w-28 shrink-0 items-center justify-end px-2 text-sm text-slate-600">
                        {((Number(l.qty) || 0) * (Number(l.unit_price) || 0)).toFixed(2)}
                      </div>
                    </>
                  )}
                  <button type="button" onClick={() => removeLine(i)} className="rounded-lg px-2 text-slate-400 hover:bg-red-50 hover:text-red-600">
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
                {!l.is_heading && (
                  <div className="mt-1 flex gap-2 pl-1">
                    <input value={l.code} onChange={(e) => setLine(i, "code", e.target.value)} placeholder="Part-No. (optional)" className={cellInput + " w-1/3 !py-1 text-[11px]"} />
                    <input value={l.remarks} onChange={(e) => setLine(i, "remarks", e.target.value)} placeholder="Remark — prints below the item (optional)" className={cellInput + " flex-1 !py-1 text-[11px]"} />
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Totals */}
      <div className="ml-auto max-w-sm space-y-2 rounded-xl border border-slate-200 bg-white p-5">
        <div className="flex items-center justify-between text-sm">
          <span className="text-slate-500">Subtotal</span>
          <span className="font-medium">{subtotal.toFixed(2)} {cur}</span>
        </div>
        <div className="flex items-center justify-between text-sm">
          <label className="text-slate-500">Packing</label>
          <input type="number" step="0.01" value={form.packing_cost} onChange={(e) => setField("packing_cost", e.target.value)} className={cellInput + " w-28 text-right"} />
        </div>
        <div className="flex items-center justify-between text-sm">
          <label className="text-slate-500">Transportation</label>
          <input type="number" step="0.01" value={form.transportation_cost} onChange={(e) => setField("transportation_cost", e.target.value)} className={cellInput + " w-28 text-right"} />
        </div>
        <div className="flex items-center justify-between text-sm">
          <label className="text-slate-500">GST amount</label>
          <input type="number" step="0.01" value={form.tax_amount} onChange={(e) => setField("tax_amount", e.target.value)} className={cellInput + " w-28 text-right"} />
        </div>
        <div className="flex items-center justify-between border-t border-slate-200 pt-2 text-base font-bold text-[#28364b]">
          <span>Total</span>
          <span>{grand.toFixed(2)} {cur}</span>
        </div>
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
