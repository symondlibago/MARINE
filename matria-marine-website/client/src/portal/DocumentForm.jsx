import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Plus, X, Save, Download, Type as TypeIcon } from "lucide-react";
import { toast } from "sonner";
import { documentsAPI, customersAPI, vendorsAPI } from "@/pages/api";
import { Spinner, PageLoader } from "./ui/Loading";
import Select from "./ui/Select";
import UnitSelect from "./ui/UnitSelect";
import DatePicker from "./ui/DatePicker";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];
const UNITS = ["pcs", "pc", "set", "mtrs", "ltrs", "ltr", "kg", "can", "barrels", "lth", "hrs", "drum", "roll", "box", "unit", "lot", "day(s)"];

const TYPE_OPTIONS = [
  { value: "invoice", label: "Invoice" },
  { value: "quotation", label: "Quotation" },
  { value: "enquiry", label: "Enquiry" },
  { value: "delivery_note", label: "Delivery Note" },
];
const TYPE_LABEL = { invoice: "Invoice", quotation: "Quotation", enquiry: "Enquiry", delivery_note: "Delivery Note" };

const cellInput = "w-full rounded border border-slate-200 px-2 py-1 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";
const fieldInput = "w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

const todayYMD = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

export default function DocumentForm({ params }) {
  const id = params?.id;
  const isEdit = !!id;
  const [, setLocation] = useLocation();
  const [docId, setDocId] = useState(id || null);

  const [form, setForm] = useState({
    type: "invoice",
    number: "",
    customer_id: "",
    vendor_id: "",
    party_name: "",
    party_address: "",
    party_email: "",
    date: todayYMD(),
    currency: "USD",
    order_number: "",
    terms: "",
    gst_rate: "0",
    notes: "",
  });
  const [items, setItems] = useState([{ is_heading: false, description: "", unit: "", qty: "1", unit_price: "0" }]);
  const setF = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  const partyKind = form.type === "enquiry" ? "vendor" : "customer";

  const { data: customers } = useQuery({ queryKey: ["customers", ""], queryFn: async () => (await customersAPI.list()).data.data });
  const { data: vendors } = useQuery({ queryKey: ["vendors", ""], queryFn: async () => (await vendorsAPI.list()).data.data });
  const recipientList = (partyKind === "vendor" ? vendors : customers) ?? [];

  // Edit: load the existing document.
  const { data: existing, isLoading } = useQuery({
    queryKey: ["document", id],
    queryFn: async () => (await documentsAPI.get(id)).data.data,
    enabled: isEdit,
  });

  // New: suggest the next number for the chosen type.
  const { data: nextNum } = useQuery({
    queryKey: ["doc-next", form.type],
    queryFn: async () => (await documentsAPI.nextNumber(form.type)).data.data.number,
    enabled: !isEdit,
  });

  useEffect(() => {
    if (!isEdit && nextNum) setForm((f) => ({ ...f, number: nextNum }));
  }, [nextNum, isEdit]);

  useEffect(() => {
    if (!existing) return;
    setForm({
      type: existing.type,
      number: existing.number || "",
      customer_id: existing.customer_id ? String(existing.customer_id) : "",
      vendor_id: existing.vendor_id ? String(existing.vendor_id) : "",
      party_name: existing.party_name || "",
      party_address: existing.party_address || "",
      party_email: existing.party_email || "",
      date: existing.date ? String(existing.date).slice(0, 10) : todayYMD(),
      currency: existing.currency || "USD",
      order_number: existing.order_number || "",
      terms: existing.terms || "",
      gst_rate: String(Number(existing.gst_rate ?? 0)),
      notes: existing.notes || "",
    });
    setItems(
      (existing.items || []).map((it) => ({
        is_heading: !!it.is_heading,
        description: it.description || "",
        unit: it.unit || "",
        qty: it.qty == null ? "" : String(Number(it.qty)),
        unit_price: it.unit_price == null ? "" : String(Number(it.unit_price)),
      }))
    );
  }, [existing]);

  const onTypeChange = (t) => setForm((f) => ({ ...f, type: t, customer_id: "", vendor_id: "" }));

  const pickRecipient = (idStr) => {
    const ent = recipientList.find((e) => String(e.id) === String(idStr));
    setForm((f) => ({
      ...f,
      customer_id: partyKind === "customer" ? idStr : "",
      vendor_id: partyKind === "vendor" ? idStr : "",
      party_name: ent?.name ?? f.party_name,
      party_address: ent?.address ?? f.party_address,
      party_email: ent?.email ?? f.party_email,
    }));
  };

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        type: form.type,
        number: form.number || null,
        party_kind: partyKind,
        customer_id: partyKind === "customer" && form.customer_id ? Number(form.customer_id) : null,
        vendor_id: partyKind === "vendor" && form.vendor_id ? Number(form.vendor_id) : null,
        party_name: form.party_name || null,
        party_address: form.party_address || null,
        party_email: form.party_email || null,
        date: form.date || null,
        currency: form.currency,
        order_number: form.order_number || null,
        terms: form.terms || null,
        gst_rate: Number(form.gst_rate) || 0,
        notes: form.notes || null,
        items: items
          .filter((it) => it.is_heading || it.description.trim())
          .map((it) => ({
            is_heading: it.is_heading,
            description: it.description,
            unit: it.is_heading ? null : it.unit || null,
            qty: it.is_heading ? null : Number(it.qty) || 0,
            unit_price: it.is_heading ? null : Number(it.unit_price) || 0,
          })),
      };
      return docId ? documentsAPI.update(docId, payload) : documentsAPI.create(payload);
    },
    onSuccess: (res) => {
      const saved = res.data.data;
      toast.success("Document saved.");
      if (!docId) {
        setDocId(saved.id);
        setLocation(`/documents/${saved.id}`);
      }
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save. Check the required fields."),
  });

  const downloadPdf = async () => {
    try {
      const res = await documentsAPI.pdf(docId);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${form.number || "document"}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download PDF.");
    }
  };

  if (isEdit && isLoading) return <PageLoader />;

  const setItem = (idx, patch) => setItems((arr) => arr.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  const addItem = () => setItems((arr) => [...arr, { is_heading: false, description: "", unit: "", qty: "1", unit_price: "0" }]);
  const addHeading = () => setItems((arr) => [...arr, { is_heading: true, description: "", unit: "", qty: "", unit_price: "" }]);
  const removeItem = (idx) => setItems((arr) => arr.filter((_, i) => i !== idx));

  const subtotal = items.reduce((s, it) => (it.is_heading ? s : s + (Number(it.qty) || 0) * (Number(it.unit_price) || 0)), 0);
  const gst = subtotal * (Number(form.gst_rate) || 0) / 100;
  const total = subtotal + gst;
  const recipientLabel = partyKind === "vendor" ? "Vendor" : "Customer";

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <Link href="/documents" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> Back to documents
      </Link>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-[#28364b]">{docId ? form.number : `New ${TYPE_LABEL[form.type]}`}</h1>
        <div className="flex gap-2">
          {docId && (
            <button onClick={downloadPdf} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50">
              <Download className="h-4 w-4" /> PDF
            </button>
          )}
          <button onClick={() => save.mutate()} disabled={save.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
          </button>
        </div>
      </div>

      {/* Top fields */}
      <div className="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Document type">
          <Select value={form.type} onChange={onTypeChange} options={TYPE_OPTIONS} />
        </Field>
        <Field label="Number">
          <input className={fieldInput} value={form.number} onChange={(e) => setF("number", e.target.value)} placeholder="auto" />
        </Field>
        <Field label="Date">
          <DatePicker value={form.date} onChange={(v) => setF("date", v)} placeholder="Set date" />
        </Field>
        <Field label="Currency">
          <Select value={form.currency} onChange={(v) => setF("currency", v)} options={CURRENCIES} />
        </Field>
      </div>

      {/* Recipient */}
      <div className="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 lg:grid-cols-2">
        <div className="space-y-3">
          <Field label={recipientLabel}>
            {recipientList.length === 0 ? (
              <p className="text-sm text-slate-400">
                No {recipientLabel.toLowerCase()}s yet —{" "}
                <Link href={partyKind === "vendor" ? "/vendors" : "/customers"} className="text-[#28364b] underline">add one</Link>, or just type the details below.
              </p>
            ) : (
              <Select
                value={partyKind === "vendor" ? form.vendor_id : form.customer_id}
                onChange={pickRecipient}
                options={recipientList.map((e) => ({ value: String(e.id), label: e.name }))}
                placeholder={`Choose ${recipientLabel.toLowerCase()}`}
              />
            )}
          </Field>
          <Field label="Name (shown on document)">
            <input className={fieldInput} value={form.party_name} onChange={(e) => setF("party_name", e.target.value)} />
          </Field>
          <Field label="Address">
            <textarea rows={3} className={fieldInput} value={form.party_address} onChange={(e) => setF("party_address", e.target.value)} placeholder="Company / c-o / street / city / country" />
          </Field>
          <Field label="Email">
            <input className={fieldInput} value={form.party_email} onChange={(e) => setF("party_email", e.target.value)} />
          </Field>
        </div>
        <div className="space-y-3">
          <Field label="Order number">
            <input className={fieldInput} value={form.order_number} onChange={(e) => setF("order_number", e.target.value)} placeholder="e.g. PO60041/26/175" />
          </Field>
          <Field label="Terms">
            <input className={fieldInput} value={form.terms} onChange={(e) => setF("terms", e.target.value)} placeholder="e.g. 30 DAYS / UPON RECEIPT" />
          </Field>
          <Field label="GST %">
            <input type="number" step="0.01" min="0" className={fieldInput} value={form.gst_rate} onChange={(e) => setF("gst_rate", e.target.value)} />
            <p className="mt-1 text-xs text-slate-400">Leave at 0 for zero-rated (ship supplies).</p>
          </Field>
        </div>
      </div>

      {/* Line items */}
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Description</th>
              <th className="w-28 px-2 py-3 font-semibold">Unit</th>
              <th className="w-24 px-2 py-3 text-right font-semibold">Qty</th>
              <th className="w-32 px-2 py-3 text-right font-semibold">Unit price</th>
              <th className="w-32 px-2 py-3 text-right font-semibold">Amount</th>
              <th className="w-10 px-2 py-3"></th>
            </tr>
          </thead>
          <tbody>
            {items.map((it, idx) =>
              it.is_heading ? (
                <tr key={idx} className="border-b border-slate-100 bg-slate-50/60 last:border-0">
                  <td className="px-4 py-2" colSpan={5}>
                    <div className="flex items-center gap-2">
                      <span className="inline-flex items-center gap-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-medium uppercase text-slate-500"><TypeIcon className="h-3 w-3" /> Heading</span>
                      <input className={`${cellInput} font-semibold`} value={it.description} onChange={(e) => setItem(idx, { description: e.target.value })} placeholder="Section title" />
                    </div>
                  </td>
                  <td className="px-2 py-2 text-center">
                    <button onClick={() => removeItem(idx)} className="text-slate-300 hover:text-red-500" title="Remove"><X className="h-4 w-4" /></button>
                  </td>
                </tr>
              ) : (
                <tr key={idx} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-2">
                    <input className={cellInput} value={it.description} onChange={(e) => setItem(idx, { description: e.target.value })} placeholder="Item description" />
                  </td>
                  <td className="px-2 py-2">
                    <UnitSelect value={it.unit} onChange={(v) => setItem(idx, { unit: v })} options={UNITS} />
                  </td>
                  <td className="px-2 py-2 text-right">
                    <input type="number" step="0.001" className={`${cellInput} text-right`} value={it.qty} onChange={(e) => setItem(idx, { qty: e.target.value })} />
                  </td>
                  <td className="px-2 py-2 text-right">
                    <input type="number" step="0.01" className={`${cellInput} text-right`} value={it.unit_price} onChange={(e) => setItem(idx, { unit_price: e.target.value })} />
                  </td>
                  <td className="px-2 py-2 text-right font-medium text-[#28364b]">{((Number(it.qty) || 0) * (Number(it.unit_price) || 0)).toFixed(2)}</td>
                  <td className="px-2 py-2 text-center">
                    <button onClick={() => removeItem(idx)} className="text-slate-300 hover:text-red-500" title="Remove"><X className="h-4 w-4" /></button>
                  </td>
                </tr>
              )
            )}
          </tbody>
        </table>
        <div className="flex gap-2 border-t border-slate-100 p-3">
          <button onClick={addItem} className="inline-flex items-center gap-1 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-500 transition-colors hover:border-[#28364b] hover:text-[#28364b]">
            <Plus className="h-4 w-4" /> Add line
          </button>
          <button onClick={addHeading} className="inline-flex items-center gap-1 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-500 transition-colors hover:border-[#28364b] hover:text-[#28364b]">
            <TypeIcon className="h-4 w-4" /> Add heading
          </button>
        </div>
      </div>

      {/* Totals */}
      <div className="flex justify-end">
        <div className="w-full max-w-xs space-y-1.5 rounded-xl border border-slate-200 bg-white p-4 text-sm">
          <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{subtotal.toFixed(2)} {form.currency}</span></div>
          <div className="flex justify-between text-slate-500"><span>GST ({Number(form.gst_rate) || 0}%)</span><span>{gst.toFixed(2)} {form.currency}</span></div>
          <div className="flex justify-between border-t border-slate-200 pt-1.5 text-base font-bold text-[#28364b]"><span>Total</span><span>{total.toFixed(2)} {form.currency}</span></div>
        </div>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <label className="text-xs font-bold uppercase tracking-wider text-slate-400">Notes</label>
        <textarea rows={2} value={form.notes} onChange={(e) => setF("notes", e.target.value)} placeholder="Optional notes shown on the document…" className="mt-1.5 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
      </div>
    </motion.div>
  );
}

function Field({ label, children }) {
  return (
    <div className="space-y-1.5">
      <label className="text-xs font-bold uppercase tracking-wider text-slate-400">{label}</label>
      {children}
    </div>
  );
}
