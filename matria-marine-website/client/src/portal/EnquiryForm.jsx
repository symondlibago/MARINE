import { useState, useEffect } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { useLocation, Link } from "wouter";
import { motion } from "framer-motion";
import { Plus, Trash2, ArrowLeft, ShieldCheck } from "lucide-react";
import { toast } from "sonner";
import { rfqsAPI, customersAPI } from "@/pages/api";
import Select from "./ui/Select";
import EntityPicker from "./ui/EntityPicker";
import Combobox from "./ui/Combobox";
import DatePicker from "./ui/DatePicker";
import { Spinner } from "./ui/Loading";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

const PRIORITIES = [
  { value: "low", label: "Low" },
  { value: "normal", label: "Normal" },
  { value: "high", label: "High" },
  { value: "urgent", label: "Urgent" },
];

// Sourcing requirement tags. These ARE shown to vendors so they quote correctly.
const REQUIREMENTS = [
  "Genuine spares only",
  "IHM relevant",
  "PSD certificate",
  "MSDS approval",
  "Technical (TDS) file",
  "Type Approval / Class certificate",
  "SDOC required",
  "EU MED / Wheelmark",
];

const input =
  "w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";
// Same look, but no w-full — so flex sizing (w-24) is respected in the line-item row.
const cellInput =
  "rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

export default function EnquiryForm({ params }) {
  const editId = params?.id;
  const [, setLocation] = useLocation();
  const [header, setHeader] = useState({
    customer_id: "",
    customer_reference: "",
    priority: "normal",
    requirements: [],
    ship_name: "",
    requested_by: "",
    delivery_port: "",
    received_date: "",
    base_currency: "USD",
    notes: "",
  });
  const [items, setItems] = useState([{ description: "", qty: "", unit: "" }]);

  // When editing, load the existing enquiry and prefill (items keep their id).
  const { data: existing } = useQuery({
    queryKey: ["rfq", editId],
    queryFn: async () => (await rfqsAPI.get(editId)).data.data,
    enabled: !!editId,
  });

  useEffect(() => {
    if (!existing) return;
    setHeader({
      customer_id: existing.customer_id ? String(existing.customer_id) : "",
      customer_reference: existing.customer_reference || "",
      priority: existing.priority || "normal",
      requirements: Array.isArray(existing.requirements) ? existing.requirements : [],
      ship_name: existing.ship_name || "",
      requested_by: existing.requested_by || "",
      delivery_port: existing.delivery_port || "",
      received_date: existing.received_date ? String(existing.received_date).slice(0, 10) : "",
      base_currency: existing.base_currency || "USD",
      notes: existing.notes || "",
    });
    const loaded = (existing.items || []).map((it) => ({ id: it.id, description: it.description, qty: Number(it.qty), unit: it.unit || "" }));
    setItems(loaded.length ? loaded : [{ description: "", qty: "", unit: "" }]);
  }, [existing]);

  const { data: suggestions } = useQuery({
    queryKey: ["item-suggestions"],
    queryFn: async () => (await rfqsAPI.itemSuggestions()).data.data,
  });

  const setH = (k, v) => setHeader((h) => ({ ...h, [k]: v }));
  const toggleReq = (req) =>
    setHeader((h) => ({
      ...h,
      requirements: h.requirements.includes(req) ? h.requirements.filter((r) => r !== req) : [...h.requirements, req],
    }));
  const setItem = (i, k, v) => setItems((its) => its.map((it, idx) => (idx === i ? { ...it, [k]: v } : it)));
  const addItem = () => setItems((its) => [...its, { description: "", qty: "", unit: "" }]);
  const removeItem = (i) => setItems((its) => its.filter((_, idx) => idx !== i));

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        ...header,
        customer_id: header.customer_id ? Number(header.customer_id) : null,
        received_date: header.received_date || null,
        items: items
          .filter((it) => it.description.trim())
          .map((it) => ({ id: it.id, description: it.description, qty: Number(it.qty) || 0, unit: it.unit })),
      };
      return editId ? rfqsAPI.update(editId, payload) : rfqsAPI.create(payload);
    },
    onSuccess: (res) => {
      toast.success(editId ? "Enquiry updated." : "Enquiry created.");
      setLocation(`/enquiries/${editId || res.data.data.id}`);
    },
    onError: (err) => toast.error(err.response?.data?.message || "Could not save enquiry."),
  });

  const submit = (e) => {
    e.preventDefault();
    if (!items.some((it) => it.description.trim())) {
      toast.error("Add at least one line item.");
      return;
    }
    save.mutate();
  };

  return (
    <motion.form initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} onSubmit={submit} className="space-y-6">
      <div>
        <Link href={editId ? `/enquiries/${editId}` : "/enquiries"} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
          <ArrowLeft className="h-4 w-4" /> Back
        </Link>
        <h1 className="mt-2 text-2xl font-bold text-[#28364b]">{editId ? "Edit Enquiry" : "New Enquiry"}</h1>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Customer">
            <EntityPicker api={customersAPI} queryKey="customers" value={header.customer_id} onChange={(v) => setH("customer_id", v)} placeholder="— Select customer —" />
          </Field>
          <Field label="Customer reference">
            <input className={input} value={header.customer_reference} onChange={(e) => setH("customer_reference", e.target.value)} placeholder="Their PO / ref no." />
          </Field>
          <Field label="Priority">
            <Select value={header.priority} onChange={(v) => setH("priority", v)} options={PRIORITIES} />
          </Field>
          <Field label="Ship / Vessel">
            <input className={input} value={header.ship_name} onChange={(e) => setH("ship_name", e.target.value)} />
          </Field>
          <Field label="Delivery port">
            <input className={input} value={header.delivery_port} onChange={(e) => setH("delivery_port", e.target.value)} />
          </Field>
          <Field label="Requested by">
            <input className={input} value={header.requested_by} onChange={(e) => setH("requested_by", e.target.value)} />
          </Field>
          <Field label="Received date">
            <DatePicker value={header.received_date} onChange={(v) => setH("received_date", v)} />
          </Field>
          <Field label="Base currency *">
            <Select value={header.base_currency} onChange={(v) => setH("base_currency", v)} options={CURRENCIES} />
          </Field>
          <Field label="Notes (internal)">
            <input className={input} value={header.notes} onChange={(e) => setH("notes", e.target.value)} />
          </Field>
        </div>
      </div>

      {/* Requirements — shown to vendors */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="flex items-center justify-between gap-2">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Requirements</h2>
            <p className="text-xs text-slate-400">Tap to add. These are shown to vendors so they quote correctly.</p>
          </div>
          <span className="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-500">
            <ShieldCheck className="h-3.5 w-3.5 text-green-600" /> Customer is never shown to vendors
          </span>
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          {REQUIREMENTS.map((req) => {
            const on = header.requirements.includes(req);
            return (
              <button
                type="button"
                key={req}
                onClick={() => toggleReq(req)}
                className={`rounded-full border px-3 py-1.5 text-sm transition-colors ${
                  on ? "border-[#28364b] bg-[#28364b] text-white" : "border-slate-200 text-slate-600 hover:border-[#28364b] hover:text-[#28364b]"
                }`}
              >
                {req}
              </button>
            );
          })}
        </div>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="mb-3 flex items-center justify-between">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Line items</h2>
            <p className="text-xs text-slate-400">Type any description — suggestions are just shortcuts from past enquiries.</p>
          </div>
          <button type="button" onClick={addItem} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50">
            <Plus className="h-4 w-4" /> Add line
          </button>
        </div>

        <div className="space-y-2">
          {items.map((it, i) => (
            <div key={it.id ?? `new-${i}`} className="flex gap-2">
              <Combobox
                className="flex-1"
                value={it.description}
                onChange={(v) => setItem(i, "description", v)}
                suggestions={suggestions || []}
                placeholder="Description (type anything)"
              />
              <input type="number" step="0.001" placeholder="Qty" className={cellInput + " w-24 shrink-0"} value={it.qty} onChange={(e) => setItem(i, "qty", e.target.value)} />
              <input placeholder="Unit" className={cellInput + " w-24 shrink-0"} value={it.unit} onChange={(e) => setItem(i, "unit", e.target.value)} />
              <button type="button" onClick={() => removeItem(i)} disabled={items.length === 1} className="rounded-lg px-2 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30">
                <Trash2 className="h-4 w-4" />
              </button>
            </div>
          ))}
        </div>
      </div>

      <div className="flex justify-end gap-2">
        <Link href={editId ? `/enquiries/${editId}` : "/enquiries"} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</Link>
        <button type="submit" disabled={save.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-5 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-70">
          {save.isLoading && <Spinner className="h-4 w-4" />}
          {editId ? "Save changes" : "Create enquiry"}
        </button>
      </div>
    </motion.form>
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
