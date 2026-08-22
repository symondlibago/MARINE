import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Download, Save, TrendingUp, Lock, Truck, Send, CheckCircle2, Receipt, RefreshCw, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { offersAPI, customersAPI, invoicesAPI } from "@/pages/api";
import Select from "./ui/Select";
import EntityPicker from "./ui/EntityPicker";
import DatePicker from "./ui/DatePicker";
import { gridKeyDown } from "./ui/gridKeys";
import { Spinner, PageLoader } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];
const PAYMENT_TERMS = ["", "Prepayment", "Payable on receipt", "Net 7 days", "Net 14 days", "Net 30 days", "Net 60 days", "Net 90 days"];
const DELIVERY_TERMS = ["", "EXW — Ex Works", "FCA — Free Carrier", "FAS — Free Alongside Ship", "FOB — Free On Board", "CFR — Cost & Freight", "CIF — Cost, Insurance & Freight", "CPT — Carriage Paid To", "CIP — Carriage & Insurance Paid To", "DAP — Delivered At Place", "DDP — Delivered Duty Paid"];
const ORIGIN_TYPES = ["", "Genuine", "OEM", "Aftermarket", "Used", "Not Applicable"];
const STATUSES = [
  { value: "draft", label: "Draft" },
  { value: "sent", label: "Sent to customer" },
  { value: "accepted", label: "Accepted" },
  { value: "declined", label: "Declined" },
];

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  sent: "bg-blue-100 text-blue-700",
  accepted: "bg-green-100 text-green-700",
  declined: "bg-red-100 text-red-700",
};

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const ci = "w-full rounded border border-slate-200 px-2 py-1 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

export default function OfferPage({ params }) {
  const id = params.id;
  const [, setLocation] = useLocation();
  const confirm = useConfirm();

  const { data: offer, isLoading, refetch } = useQuery({
    queryKey: ["offer", id],
    queryFn: async () => (await offersAPI.get(id)).data.data,
  });

  const [header, setHeader] = useState({ customer_id: "", currency: "USD", valid_until: "", payment_terms: "", delivery_terms: "", origin_type: "", status: "draft", notes: "", packing_cost: "", transportation_cost: "", tax_rate: "" });
  const [items, setItems] = useState([]);
  const [bulk, setBulk] = useState("");
  const [pickedCustomerName, setPickedCustomerName] = useState(null);
  const [bulkLead, setBulkLead] = useState("");

  useEffect(() => {
    if (!offer) return;
    setHeader({
      customer_id: offer.customer_id ? String(offer.customer_id) : "",
      currency: offer.currency || "USD",
      valid_until: offer.valid_until ? String(offer.valid_until).slice(0, 10) : "",
      payment_terms: offer.payment_terms || "",
      delivery_terms: offer.delivery_terms || "",
      origin_type: offer.origin_type || "",
      status: offer.status || "draft",
      notes: offer.notes || "",
      packing_cost: offer.packing_cost != null ? String(offer.packing_cost) : "",
      transportation_cost: offer.transportation_cost != null ? String(offer.transportation_cost) : "",
      tax_rate: offer.tax_rate != null && Number(offer.tax_rate) > 0 ? String(Number(offer.tax_rate)) : "",
    });
    setItems(
      (offer.items || [])
        .filter((it) => !it.is_heading)
        .map((it) => ({
          id: it.id,
          // No enquiry line behind it = Matria's own charge, priced by hand.
          manual: it.rfq_item_id == null,
          description: it.description || "",
          code: it.code || "",
          customs_code: it.customs_code || "",
          accounting_code: it.accounting_code || "",
          unit: it.unit || "",
          qty: Number(it.qty),
          base_price: Number(it.base_price),
          unit_price: Number(it.unit_price || 0),
          // Read-only note of whose price this line was built from.
          base_source: it.base_source || "",
          markup_pct: Number(it.markup_pct),
          discount_pct: Number(it.discount_pct || 0),
          lead_time: it.lead_time || "",
          delivery_location: it.delivery_location || "",
          remarks: it.remarks || "",
        }))
    );
  }, [offer]);

  const setH = (k, v) => setHeader((h) => ({ ...h, [k]: v }));
  const setItem = (idx, patch) => setItems((arr) => arr.map((it, i) => (i === idx ? { ...it, ...patch } : it)));

  // Ids of saved manual lines the user has removed; sent on save so the server
  // deletes them. Enquiry lines are never in here — the server refuses those.
  const [removedIds, setRemovedIds] = useState([]);

  /** A charge of Matria's own — agency fee, handling, a service we perform. */
  const addManualLine = () =>
    setItems((arr) => [
      ...arr,
      {
        id: null, manual: true, description: "", code: "", customs_code: "", accounting_code: "", unit: "",
        qty: 1, base_price: 0, unit_price: 0, base_source: "Added by Matria",
        markup_pct: 0, discount_pct: 0, lead_time: "", delivery_location: "", remarks: "",
      },
    ]);

  const removeLine = (idx) =>
    setItems((arr) => {
      const row = arr[idx];
      if (!row?.manual) return arr;           // enquiry lines belong to the enquiry
      if (row.id) setRemovedIds((ids) => [...ids, row.id]);
      return arr.filter((_, i) => i !== idx);
    });


  /**
   * A wheel over a focused number input scrolls its VALUE, so scrolling the
   * page after clicking a markup box would quietly change it. Dropping focus
   * lets the page scroll instead and leaves the figure untouched.
   */
  const gridWheel = (e) => {
    const el = e.target;
    if (el?.type === "number" && el === document.activeElement) el.blur();
  };
  const applyBulk = () => {
    const m = Number(bulk);
    if (Number.isNaN(m) || bulk === "") return;
    setItems((arr) => arr.map((it) => ({ ...it, markup_pct: m })));
  };
  const applyBulkLead = () => {
    if (bulkLead.trim() === "") return;
    setItems((arr) => arr.map((it) => ({ ...it, lead_time: bulkLead.trim() })));
  };

  // Live maths — must mirror OfferController::lineMaths() exactly.
  //
  // The discount is the VENDOR'S: they knock 10% off their price, so we buy at
  // 585, still sell at 650, and the 65 is profit. It lowers our cost, never the
  // customer's price.
  const rows = items.map((it) => {
    const base = Number(it.base_price) || 0;
    const qty = Number(it.qty) || 0;

    // A line Matria added itself: nothing was bought, so the typed price IS the
    // price and every cent of it is profit.
    if (it.manual) {
      const unit = Number(it.unit_price) || 0;
      const amount = unit * qty;
      return { ...it, unit_price: unit, disc_amount: 0, cost_line: 0, line_total: amount, markup_amount: amount, base_line: 0 };
    }

    const unit = base * (1 + (Number(it.markup_pct) || 0) / 100);   // customer pays
    const cost = base * (1 - (Number(it.discount_pct) || 0) / 100); // we pay
    const amount = unit * qty;
    return {
      ...it,
      unit_price: unit,
      disc_amount: base - cost,          // the vendor's discount per unit — ours
      cost_line: cost * qty,
      line_total: amount,
      markup_amount: (unit - cost) * qty,
      base_line: cost * qty,             // what the line actually costs us
    };
  });
  const baseTotal = rows.reduce((s, r) => s + r.base_line, 0);
  const custTotal = rows.reduce((s, r) => s + r.line_total, 0);
  const profit = custTotal - baseTotal;
  const profitPct = baseTotal > 0 ? (profit / baseTotal) * 100 : 0;
  const packing = Number(header.packing_cost) || 0;
  const transportation = Number(header.transportation_cost) || 0;
  const deliveryTotal = packing + transportation;
  const taxRate = Number(header.tax_rate) || 0;
  const taxAmount = ((custTotal + deliveryTotal) * taxRate) / 100;
  const grandTotal = custTotal + deliveryTotal + taxAmount;

  const custName = header.customer_id ? pickedCustomerName ?? offer?.customer_name ?? null : null;

  // The offer stores its own copy of each description; this pulls across any
  // edits made to the enquiry after the offer was generated.
  const syncEnquiry = useMutation({
    mutationFn: () => offersAPI.syncEnquiry(id),
    onSuccess: (res) => {
      toast.success(res.data.message);
      refetch();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not refresh from the enquiry."),
  });

  // Refreshing now moves money, not just wording, so it asks first.
  const handleRefresh = async () => {
    const ok = await confirm({
      title: "Re-read from the enquiry?",
      message:
        "Base costs are re-read from whichever vendor is selected on Compare & Award right now, and descriptions are refreshed.\n\nYour markup, discount, lead time and remarks are kept. Any base price you typed in by hand will be replaced.",
      confirmText: "Refresh",
    });
    if (ok) syncEnquiry.mutate();
  };

  const save = useMutation({
    mutationFn: () =>
      offersAPI.update(id, {
        customer_id: header.customer_id ? Number(header.customer_id) : null,
        currency: header.currency,
        valid_until: header.valid_until || null,
        payment_terms: header.payment_terms || null,
        delivery_terms: header.delivery_terms || null,
        origin_type: header.origin_type || null,
        packing_cost: Number(header.packing_cost) || 0,
        transportation_cost: Number(header.transportation_cost) || 0,
        tax_rate: Number(header.tax_rate) || 0,
        status: header.status,
        notes: header.notes || null,
        remove_item_ids: removedIds,
        items: items.map((it, i) => ({
          id: it.id,
          description: it.description,
          code: it.code || null,
          customs_code: it.customs_code || null,
          accounting_code: it.accounting_code || null,
          unit: it.unit || null,
          qty: Number(it.qty) || 0,
          base_price: Number(it.base_price) || 0,
          // Only meaningful on a manual line; ignored for enquiry lines, whose
          // price is derived from the vendor cost.
          unit_price: Number(it.unit_price) || 0,
          markup_pct: Number(it.markup_pct) || 0,
          discount_pct: Number(it.discount_pct) || 0,
          lead_time: it.lead_time || null,
          delivery_location: it.delivery_location || null,
          remarks: it.remarks || null,
          sort: i,
        })),
      }),
    onSuccess: () => { toast.success("Offer saved."); setRemovedIds([]); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save."),
  });

  const makeInvoice = useMutation({
    mutationFn: () => invoicesAPI.fromOffer(id),
    onSuccess: (res) => { toast.success(res.data.message || "Invoice ready."); setLocation(`/invoices/${res.data.data.id}`); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not create the invoice."),
  });
  // Save the markup first, then turn the offer into a customer invoice.
  const createInvoice = async () => {
    try { await save.mutateAsync(); } catch { return; }
    makeInvoice.mutate();
  };

  // Save the markup first, then email the quotation + acceptance link to the customer.
  const sendEmail = useMutation({
    mutationFn: () => offersAPI.email(id),
    onSuccess: (res) => { toast.success(res.data.message || "Quotation sent to the customer."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not send the quotation."),
  });
  const sendToCustomer = async () => {
    try { await save.mutateAsync(); } catch { return; }
    sendEmail.mutate();
  };

  const downloadPdf = async () => {
    try {
      const res = await offersAPI.pdf(id);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${offer.offer_number}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download PDF.");
    }
  };

  if (isLoading || !offer) return <PageLoader />;

  const th = "px-1.5 py-2.5 font-semibold whitespace-nowrap";
  // Hide the number-input spinner arrows — they steal ~20px inside each field.
  const num = "[appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none";
  const internal = "bg-amber-50/50"; // tint for internal (cost/markup) columns

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <Link href={`/enquiries/${offer.rfq_id}`} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> Back to enquiry
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">
            {offer.offer_number}
            <span className={`ml-3 inline-flex rounded-full px-2 py-0.5 align-middle text-xs font-medium ${STATUS_STYLES[header.status] || "bg-slate-100"}`}>{header.status}</span>
          </h1>
          <p className="mt-1 text-sm text-slate-500">Quotation for <span className="font-medium text-[#28364b]">{custName || offer.customer_name || "—"}</span></p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button onClick={downloadPdf} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 transition-colors hover:bg-slate-50">
            <Download className="h-4 w-4" /> Quotation PDF
          </button>
          <button onClick={sendToCustomer} disabled={save.isLoading || sendEmail.isLoading} className="inline-flex items-center gap-1 rounded-lg border border-[#28364b] px-3 py-2 text-sm font-semibold text-[#28364b] transition-colors hover:bg-slate-50 disabled:opacity-70" title="Email the quotation + an online acceptance link to the customer">
            {sendEmail.isLoading ? <Spinner className="h-4 w-4" /> : <Send className="h-4 w-4" />} Send to customer
          </button>
          <button onClick={() => save.mutate()} disabled={save.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
          </button>
          <button onClick={createInvoice} disabled={save.isLoading || makeInvoice.isLoading} className="inline-flex items-center gap-1 rounded-lg border border-[#28364b] px-3 py-2 text-sm font-semibold text-[#28364b] transition-colors hover:bg-slate-50 disabled:opacity-70" title="Save & create a customer invoice from this offer">
            {makeInvoice.isLoading ? <Spinner className="h-4 w-4" /> : <Receipt className="h-4 w-4" />} Create Invoice
          </button>
        </div>
      </div>

      {offer.accepted_at && (
        <div className="flex items-start gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
          <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" />
          <span>
            <span className="font-semibold">Accepted by the customer</span>
            {offer.accepted_by_name ? ` (${offer.accepted_by_name})` : ""} on {new Date(offer.accepted_at).toLocaleString()}.
            {offer.acceptance_note ? <em className="block text-green-700">“{offer.acceptance_note}”</em> : null}
            {" "}Generate the purchase orders in Compare &amp; Award, then create each PO's delivery order from its page.
          </span>
        </div>
      )}

      {/* Profit summary */}
      <div className="grid gap-4 sm:grid-cols-3">
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Vendor cost <span className="font-normal normal-case text-slate-300">· internal</span></div>
          <div className="mt-1 text-2xl font-bold text-slate-500">{money(baseTotal)} <span className="text-sm font-normal text-slate-400">{header.currency}</span></div>
        </div>
        <div className="rounded-xl border border-green-200 bg-gradient-to-br from-green-50 to-white p-4">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wide text-green-800">Markup (profit)</span>
            <TrendingUp className="h-4 w-4 text-green-600" />
          </div>
          <div className="mt-1 text-2xl font-bold text-green-700">{money(profit)} <span className="text-sm font-normal text-green-600/70">{header.currency} · {profitPct.toFixed(1)}%</span></div>
        </div>
        <div className="rounded-xl border border-[#28364b] bg-[#28364b] p-4 text-white">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-300">Customer total</div>
          <div className="mt-1 text-2xl font-bold">{money(grandTotal)} <span className="text-sm font-normal text-slate-300">{header.currency}</span></div>
          {(deliveryTotal > 0 || taxAmount > 0) && (
            <div className="mt-1 text-xs text-slate-300">
              {money(custTotal)} items{deliveryTotal > 0 ? ` + ${money(deliveryTotal)} delivery` : ""}{taxAmount > 0 ? ` + ${money(taxAmount)} GST` : ""}
            </div>
          )}
        </div>
      </div>

      {/* Commercial terms */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Customer"><EntityPicker api={customersAPI} queryKey="customers" value={header.customer_id} onChange={(v, ent) => { setH("customer_id", v); setPickedCustomerName(ent?.name ?? null); }} placeholder="— Select customer —" /></Field>
          <Field label="Currency"><Select value={header.currency} onChange={(v) => setH("currency", v)} options={CURRENCIES} /></Field>
          <Field label="Valid until"><DatePicker value={header.valid_until} onChange={(v) => setH("valid_until", v)} /></Field>
          <Field label="Status"><Select value={header.status} onChange={(v) => setH("status", v)} options={STATUSES} /></Field>
          <Field label="Payment terms"><Select value={header.payment_terms} onChange={(v) => setH("payment_terms", v)} options={PAYMENT_TERMS} placeholder="—" /></Field>
          <Field label="Delivery terms (Incoterms)"><Select value={header.delivery_terms} onChange={(v) => setH("delivery_terms", v)} options={DELIVERY_TERMS} placeholder="—" /></Field>
          <Field label="Origin"><Select value={header.origin_type} onChange={(v) => setH("origin_type", v)} options={ORIGIN_TYPES} placeholder="—" /></Field>
        </div>

        <div className="mt-4 border-t border-slate-100 pt-4">
          <div className="mb-2 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-400">
            <Truck className="h-3.5 w-3.5" /> Delivery charges ({header.currency})
          </div>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="Packing cost">
              <input type="number" step="0.01" min="0" value={header.packing_cost} onChange={(e) => setH("packing_cost", e.target.value)} placeholder="0.00" className={`${ci} text-right`} />
            </Field>
            <Field label="Transportation cost">
              <input type="number" step="0.01" min="0" value={header.transportation_cost} onChange={(e) => setH("transportation_cost", e.target.value)} placeholder="0.00" className={`${ci} text-right`} />
            </Field>
            <Field label="Total delivery">
              <div className="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-right text-sm font-semibold text-[#28364b]">{money(deliveryTotal)}</div>
            </Field>
          </div>
        </div>

        <div className="mt-4 border-t border-slate-100 pt-4">
          <div className="mb-2 text-xs font-bold uppercase tracking-wider text-slate-400">GST / Tax</div>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="GST %">
              <input type="number" step="0.001" min="0" max="100" value={header.tax_rate} onChange={(e) => setH("tax_rate", e.target.value)} placeholder="0 = zero-rated" className={`${ci} text-right`} />
            </Field>
            <Field label="GST amount">
              <div className="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-right text-sm font-semibold text-[#28364b]">{money(taxAmount)}</div>
            </Field>
          </div>
          <p className="mt-2 text-xs text-slate-400">Leave 0 for zero-rated marine supplies; enter the rate (e.g. 9) for taxable local sales. GST applies to items + delivery.</p>
        </div>
      </div>

      {/* Line items with markup */}
      <div className="rounded-xl border border-slate-200 bg-white">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-4">
          <div>
            <div className="flex items-center gap-2">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Line items &amp; markup</h2>
              {/* An offer is priced once at creation, so this is the only way to
                  pull a changed vendor selection into a draft. Easy to miss as a
                  faint link, and missing it means quoting last week's price. */}
              <button
                onClick={handleRefresh}
                disabled={syncEnquiry.isLoading}
                title="Re-read base costs from the vendor selected on Compare & Award, and refresh descriptions"
                className="inline-flex items-center gap-1.5 rounded-lg bg-[#28364b] px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-[#3c4a63] disabled:opacity-50"
              >
                {syncEnquiry.isLoading ? <Spinner className="h-3.5 w-3.5" /> : <RefreshCw className="h-3.5 w-3.5" />} Refresh from enquiry
              </button>
              {/* Matria's own charges — an agency fee, handling, a service we
                  perform ourselves. No vendor, so the whole amount is profit. */}
              <button
                onClick={addManualLine}
                title="Add a charge of your own — agency fee, handling, a service Matria provides"
                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:border-[#28364b] hover:text-[#28364b]"
              >
                <Plus className="h-3.5 w-3.5" /> Add own line
              </button>
            </div>
            <p className="text-xs text-slate-400">
              Amber columns are internal — the customer only sees description, unit, qty, unit price &amp; amount.
              A vendor discount lowers <em>our</em> cost and becomes profit; it never reduces what the customer pays.
              Press <kbd className="rounded border border-slate-200 bg-slate-50 px-1 font-sans text-[10px] text-slate-500">↑</kbd>{" "}
              <kbd className="rounded border border-slate-200 bg-slate-50 px-1 font-sans text-[10px] text-slate-500">↓</kbd> or{" "}
              <kbd className="rounded border border-slate-200 bg-slate-50 px-1 font-sans text-[10px] text-slate-500">←</kbd>{" "}
              <kbd className="rounded border border-slate-200 bg-slate-50 px-1 font-sans text-[10px] text-slate-500">→</kbd> or{" "}
              <kbd className="rounded border border-slate-200 bg-slate-50 px-1 font-sans text-[10px] text-slate-500">Enter</kbd> to move around the grid.
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-x-2 gap-y-2">
            <span className="text-xs text-slate-500">Set all markup</span>
            <div className="relative">
              <input type="number" value={bulk} onChange={(e) => setBulk(e.target.value)} placeholder="%" className="w-20 rounded border border-slate-200 px-2 py-1 pr-5 text-sm" />
              <span className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-xs text-slate-400">%</span>
            </div>
            <button onClick={applyBulk} className="rounded-lg border border-[#28364b] px-3 py-1 text-sm font-medium text-[#28364b] transition-colors hover:bg-slate-50">Apply to all</button>
            <span className="ml-2 text-xs text-slate-500">Set all lead time</span>
            <input value={bulkLead} onChange={(e) => setBulkLead(e.target.value)} placeholder="e.g. 2 days" className="w-28 rounded border border-slate-200 px-2 py-1 text-sm" />
            <button onClick={applyBulkLead} className="rounded-lg border border-[#28364b] px-3 py-1 text-sm font-medium text-[#28364b] transition-colors hover:bg-slate-50">Apply to all</button>
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full min-w-[1380px] text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className={`${th} sticky left-0 z-10 bg-white min-w-[170px]`}>Description</th>
                <th className={th} title="Supplier / part number">Code</th>
                <th className={th} title="HS / customs tariff code, for shipping paperwork">Customs</th>
                {/* Amber: the office's own cost coding, never shown to the customer. */}
                <th className={`${th} ${internal}`} title="The office's own cost coding — internal, never printed for the customer">
                  Acct Code
                </th>
                <th className={th}>Unit</th>
                <th className={`${th} text-right`}>Qty</th>
                <th className={`${th} ${internal} text-right`}>Base</th>
                <th className={`${th} ${internal} text-right`}>Base Amt</th>
                <th className={`${th} ${internal} text-right`}>Markup %</th>
                <th className={`${th} text-right`}>Unit Price</th>
                {/* Amber: the vendor's discount is internal, never shown to the customer. */}
                <th className={`${th} ${internal} text-right`} title="Discount the VENDOR gives us — lowers our cost, not the customer's price">
                  Vendor Disc %
                </th>
                <th className={`${th} ${internal} text-right`} title="The vendor's discount per unit — money we keep">Disc Amt</th>
                <th className={`${th} ${internal} text-right`}>Markup Amt</th>
                <th className={`${th} text-right`}>Amount</th>
                <th className={th}>Lead time</th>
                <th className={th}>Delivery</th>
                <th className={th}>Remarks</th>
                <th className={th}></th>
              </tr>
            </thead>
            <tbody onKeyDown={gridKeyDown} onWheel={gridWheel}>
              {rows.map((r, idx) => (
                // Unsaved manual lines have no id yet, so fall back to position.
                <tr key={r.id ?? `new-${idx}`} className={`border-b border-slate-100 last:border-0 align-top ${r.manual ? "bg-sky-50/40" : ""}`}>
                  {/* textarea, not input: a single-line input silently strips the item's
    second description line when the offer is saved. */}
                  <td className="px-1.5 py-2 sticky left-0 z-10 bg-white"><textarea rows={2} className={ci + " resize-y leading-snug"} value={r.description} onChange={(e) => setItem(idx, { description: e.target.value })} /></td>
                  <td className="px-1.5 py-2"><input className={`${ci} w-32`} value={r.code} onChange={(e) => setItem(idx, { code: e.target.value })} placeholder="Part no." /></td>
                  <td className="px-1.5 py-2"><input className={`${ci} w-28`} value={r.customs_code} onChange={(e) => setItem(idx, { customs_code: e.target.value })} placeholder="HS code" /></td>
                  <td className={`px-1.5 py-2 ${internal}`}><input className={`${ci} w-28`} value={r.accounting_code} onChange={(e) => setItem(idx, { accounting_code: e.target.value })} placeholder="Acct code" /></td>
                  <td className="px-1.5 py-2"><input className={`${ci} w-14`} value={r.unit} onChange={(e) => setItem(idx, { unit: e.target.value })} /></td>
                  <td className="px-1.5 py-2"><input type="number" step="0.001" className={`${ci} ${num} min-w-[4.5rem] text-right`} value={r.qty} onChange={(e) => setItem(idx, { qty: e.target.value })} /></td>
                  <td className={`px-1.5 py-2 ${internal}`}>
                    {r.manual ? (
                      <div className="text-right text-xs text-slate-300" title="Matria's own charge — nothing was bought for it">—</div>
                    ) : (
                      <input type="number" step="0.0001" className={`${ci} ${num} min-w-[7rem] text-right`} value={r.base_price} onChange={(e) => setItem(idx, { base_price: e.target.value })} />
                    )}
                    {/* Whose price this is. The selection on Compare & Award can
                        move afterwards, so the line has to say it for itself. */}
                    {r.base_source && (
                      <div
                        className={`mt-0.5 truncate text-right text-[10px] ${r.base_source.startsWith("Cheapest") ? "text-amber-700" : "text-slate-400"}`}
                        title={`Base price from: ${r.base_source}`}
                      >
                        {r.base_source}
                      </div>
                    )}
                  </td>
                  <td className={`px-1.5 py-2 text-right text-slate-500 whitespace-nowrap ${internal}`}>{money(r.base_line)}</td>
                  <td className={`px-1.5 py-2 ${internal}`}>
                    {r.manual ? (
                      <div className="text-right text-xs text-slate-300">—</div>
                    ) : (
                      <input type="number" step="0.1" className={`${ci} ${num} min-w-[4rem] text-right`} value={r.markup_pct} onChange={(e) => setItem(idx, { markup_pct: e.target.value })} />
                    )}
                  </td>
                  {/* A manual line is priced here directly — there is no cost to mark up. */}
                  <td className="px-1.5 py-2 text-right font-medium text-[#28364b] whitespace-nowrap">
                    {r.manual ? (
                      <input type="number" step="0.01" className={`${ci} ${num} min-w-[6rem] text-right font-medium`} value={r.unit_price} onChange={(e) => setItem(idx, { unit_price: e.target.value })} />
                    ) : (
                      money(r.unit_price)
                    )}
                  </td>
                  <td className={`px-1.5 py-2 ${internal}`}>
                    {r.manual ? (
                      <div className="text-right text-xs text-slate-300">—</div>
                    ) : (
                      <input type="number" step="0.1" className={`${ci} ${num} min-w-[3.5rem] text-right`} value={r.discount_pct} onChange={(e) => setItem(idx, { discount_pct: e.target.value })} />
                    )}
                  </td>
                  <td className={`px-1.5 py-2 text-right whitespace-nowrap ${internal} ${Number(r.disc_amount) > 0 ? "font-medium text-green-700" : "text-slate-500"}`}>{money(r.disc_amount)}</td>
                  <td className={`px-1.5 py-2 text-right font-medium text-green-700 whitespace-nowrap ${internal}`}>{money(r.markup_amount)}</td>
                  <td className="px-1.5 py-2 text-right font-semibold text-[#28364b] whitespace-nowrap">{money(r.line_total)}</td>
                  <td className="px-1.5 py-2"><input className={`${ci} w-20`} value={r.lead_time} onChange={(e) => setItem(idx, { lead_time: e.target.value })} placeholder="e.g. 2 days" /></td>
                  <td className="px-1.5 py-2"><input className={`${ci} w-20`} value={r.delivery_location} onChange={(e) => setItem(idx, { delivery_location: e.target.value })} /></td>
                  {/* textarea, not input: a remark can now carry a second line
                      from Compare & Award, and a text input silently strips it. */}
                  <td className="px-1.5 py-2"><textarea rows={2} className={`${ci} w-24 resize-y leading-snug`} value={r.remarks} onChange={(e) => setItem(idx, { remarks: e.target.value })} /></td>
                  {/* Only Matria's own lines can be removed here; an enquiry
                      line is removed on the enquiry, not on the quotation. */}
                  <td className="px-1.5 py-2 text-center">
                    {r.manual && (
                      <button
                        type="button"
                        onClick={() => removeLine(idx)}
                        title="Remove this line"
                        className="rounded p-1 text-slate-300 transition-colors hover:bg-red-50 hover:text-red-600"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              {rows.length === 0 && (
                <tr><td colSpan={18} className="py-8 text-center text-slate-400">No line items on this offer.</td></tr>
              )}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-slate-200 bg-slate-50 align-bottom">
                <td colSpan={7} className="px-1.5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Items subtotal ({header.currency})</td>
                <td className="px-1.5 py-2 text-right whitespace-nowrap">
                  <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Total base</div>
                  <div className="text-xs font-semibold text-slate-600">{money(baseTotal)}</div>
                </td>
                <td colSpan={4}></td>
                <td className="px-1.5 py-2 text-right whitespace-nowrap">
                  <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Total mark-up</div>
                  <div className="text-xs font-semibold text-green-700">{money(profit)} ({profitPct.toFixed(1)}%)</div>
                </td>
                <td className="px-1.5 py-2 text-right whitespace-nowrap">
                  <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">After mark-up</div>
                  <div className="text-sm font-semibold text-[#28364b]">{money(custTotal)}</div>
                </td>
                <td colSpan={4}></td>
              </tr>
              {packing > 0 && (
                <tr className="bg-slate-50">
                  <td colSpan={13} className="px-1.5 py-1 text-right text-xs text-slate-500">Packing cost</td>
                  <td className="px-1.5 py-1 text-right text-sm text-slate-600 whitespace-nowrap">{money(packing)}</td>
                  <td colSpan={4}></td>
                </tr>
              )}
              {transportation > 0 && (
                <tr className="bg-slate-50">
                  <td colSpan={13} className="px-1.5 py-1 text-right text-xs text-slate-500">Transportation cost</td>
                  <td className="px-1.5 py-1 text-right text-sm text-slate-600 whitespace-nowrap">{money(transportation)}</td>
                  <td colSpan={4}></td>
                </tr>
              )}
              <tr className="border-t border-slate-200 bg-slate-50">
                <td colSpan={13} className="px-1.5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Grand total ({header.currency})</td>
                <td className="px-1.5 py-3 text-right text-base font-bold text-[#28364b] whitespace-nowrap">{money(grandTotal)}</td>
                <td colSpan={4}></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      {/* Notes */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <label className="text-xs font-bold uppercase tracking-wider text-slate-400">Notes (shown on quotation)</label>
        <textarea rows={2} value={header.notes} onChange={(e) => setH("notes", e.target.value)} placeholder="e.g. Prices valid subject to stock. Delivery within 3 days of order." className="mt-1.5 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
      </div>

      <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
        <p className="flex items-center gap-1.5 text-xs text-slate-400"><Lock className="h-3.5 w-3.5" /> Vendor cost &amp; markup never appear on the customer's PDF.</p>
        <button onClick={() => save.mutate()} disabled={save.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
          {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save changes
        </button>
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
