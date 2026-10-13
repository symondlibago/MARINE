import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Plus, Trash2, Download, Mail, Save, Heading, CheckCircle2, FileMinus, Send, ArrowLeftRight, RefreshCw } from "lucide-react";
import { toast } from "sonner";
import { invoicesAPI, customersAPI } from "@/pages/api";
import Select from "./ui/Select";
import EntityPicker from "./ui/EntityPicker";
import DatePicker from "./ui/DatePicker";
import ProofOfDelivery from "./ProofOfDelivery";
import BackupButton from "./ui/BackupButton";
import Modal from "./ui/Modal";
import { fetchRates } from "@/lib/fx";
import { gridKeyDown } from "./ui/gridKeys";
import AccountSelect, { AccountCodeCell } from "./ui/AccountSelect";
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

const blankLine = () => ({ is_heading: false, description: "", code: "", unit: "", qty: "", unit_price: "", account_code: "", remarks: "" });

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
  // One account stamped across every line at once, for the common case where
  // the whole invoice belongs to the same place.
  const [bulkAcct, setBulkAcct] = useState("");

  useEffect(() => {
    if (!data) return;
    setForm({
      customer_id: data.customer_id ? String(data.customer_id) : "",
      customer_name: data.customer_name ?? "",
      customer_address: data.customer_address ?? "",
      customer_reference: data.customer_reference ?? "",
      currency: data.currency ?? "SGD",
      // Null means nobody has set one. Kept as null rather than defaulted to 1,
      // because a rate of 1 on a USD invoice is how the sales register came to
      // add 536,185 AED to a Singapore dollar total.
      exchange_rate: data.exchange_rate != null ? String(Number(data.exchange_rate)) : "",
      grand_total: data.grand_total ?? 0,
      status: data.status ?? "draft",
      issue_date: ymd(data.issue_date),
      due_date: ymd(data.due_date),
      payment_terms: data.payment_terms ?? "",
      delivery_terms: data.delivery_terms ?? "",
      origin_type: data.origin_type ?? "",
      packing_cost: data.packing_cost ?? 0,
      transportation_cost: data.transportation_cost ?? 0,
      tax_rate: data.tax_rate != null && Number(data.tax_rate) > 0 ? String(Number(data.tax_rate)) : "",
      account_code: data.account_code ?? "4100",
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
      account_code: it.account_code ?? "",
      remarks: it.remarks ?? "",
    })));
  }, [data]);

  const save = useMutation({
    mutationFn: () => invoicesAPI.update(id, {
      ...form,
      customer_id: form.customer_id || null,
      // Empty box means "not set yet", which is a null, not a zero and not a
      // one — the registers leave an unrated invoice out of the converted
      // total rather than counting it at par.
      exchange_rate: form.exchange_rate === "" ? null : Number(form.exchange_rate),
      items: lines,
    }),
    onSuccess: () => { toast.success("Invoice saved."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the invoice."),
  });

  /**
   * Saving is normally silent. Moving the issue date of an invoice that has
   * already left the building is not: it shifts the invoice between accounting
   * periods, so a statement printed last month stops matching. Worth one
   * question, and only asked when it actually applies.
   */
  const saveWithDateCheck = async () => {
    // Every billable line has to say which account it lands in, or the books
    // cannot tell earnings from money collected for someone else. Naming the
    // lines beats a generic "something is missing".
    const uncoded = lines
      .map((l, i) => ({ l, n: i + 1 }))
      .filter(({ l }) => !l.is_heading && !l.account_code);

    if (uncoded.length) {
      toast.error(
        `Set an account code on line${uncoded.length === 1 ? "" : "s"} ` +
        uncoded.map(({ l, n }) => `${n}${l.description ? ` (${l.description.slice(0, 28)})` : ""}`).join(", ")
      );
      return;
    }

    const moved = form.issue_date !== ymd(data?.issue_date);
    const issued = (data?.status ?? "draft") !== "draft";

    if (moved && issued) {
      const paid = data?.payment_count > 0;
      const ok = await confirm({
        title: "Move this invoice to a different date?",
        message:
          `${data.invoice_number} is dated ${ymd(data.issue_date) || "—"} and you are changing it to ${form.issue_date || "—"}.\n\n` +
          "It will move between accounting periods, so statements and open-entries reports already printed for those dates will no longer match." +
          (paid ? `\n\nThis invoice has ${data.payment_count} payment${data.payment_count === 1 ? "" : "s"} applied to it.` : ""),
        confirmText: "Change the date",
        tone: "danger",
      });
      if (!ok) return;
    }

    save.mutate();
  };

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

  // Quick status change to/from "sent", so the common case doesn't need the dropdown.
  const markSent = useMutation({
    mutationFn: (status) => invoicesAPI.update(id, { status }),
    onSuccess: (_res, status) => { toast.success(status === "sent" ? "Marked as sent." : "Moved back to draft."); refetch(); },
    onError: () => toast.error("Could not update the status."),
  });

  const del = useMutation({
    mutationFn: () => invoicesAPI.remove(id),
    onSuccess: () => { toast.success("Invoice deleted."); setLocation("/invoices"); },
    onError: () => toast.error("Could not delete."),
  });

  // Second-currency download: some customers are billed in one currency and
  // reconcile in another, and want both on the page. The rate is read live and
  // shown before the download, so nobody is guessing what was applied.
  //
  // Declared ABOVE the early return below: a hook after it is only reached on
  // the renders where the invoice has loaded, and React counts hooks per
  // render — which is exactly the "rendered more hooks" crash.
  const [fxOpen, setFxOpen] = useState(false);
  const [fxCurrency, setFxCurrency] = useState("");
  const [fxRate, setFxRate] = useState(null);
  const [fxLoading, setFxLoading] = useState(false);
  const [rateLoading, setRateLoading] = useState(false);

  useEffect(() => {
    if (!fxOpen || !fxCurrency) return;

    const invoiceCurrency = form?.currency || data?.currency || "USD";
    let cancelled = false;

    setFxLoading(true);
    setFxRate(null);

    fetchRates(invoiceCurrency)
      // Rounded to the six places the PDF quotes and converts at, so the rate
      // shown here, the one sent, and the one printed are the same number.
      .then((r) => {
        if (cancelled) return;
        const live = Number(r.rates?.[fxCurrency]);
        setFxRate(live > 0 ? Math.round(live * 1e6) / 1e6 : null);
      })
      .catch(() => { if (!cancelled) setFxRate(null); })
      .finally(() => { if (!cancelled) setFxLoading(false); });

    return () => { cancelled = true; };
  }, [fxOpen, fxCurrency]); // eslint-disable-line react-hooks/exhaustive-deps

  if (isLoading || !form) return <PageLoader />;

  // What the books are kept in. Comes from the server so there is one answer,
  // not a copy of it here that can drift.
  const baseCurrency = data?.base_currency || "SGD";
  const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

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
  const delivery = (Number(form.packing_cost) || 0) + (Number(form.transportation_cost) || 0);
  const taxRate = Number(form.tax_rate) || 0;
  const taxAmount = ((subtotal + delivery) * taxRate) / 100;
  const grand = subtotal + delivery + taxAmount;
  const cur = form.currency;

  const downloadPdf = async (params = {}) => {
    try {
      const res = await invoicesAPI.pdf(id, params);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${data.invoice_number}${params.currency ? `-${params.currency}` : ""}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download the PDF.");
    }
  };

  // The posting rate for a foreign invoice. Today's rate is only a starting
  // point — the right one is the rate on the day of supply, which is why it is
  // typed into a box rather than read at report time.
  const useLiveRate = async () => {
    setRateLoading(true);
    try {
      // DBS board rates — the bank Matria actually converts through, so the
      // figure in the books is the one that reaches the bank statement.
      // See App\Support\FxRates.
      const r = await fetchRates(form.currency);
      const live = Number(r.rates?.[baseCurrency]);
      if (!(live > 0)) throw new Error(`DBS has no ${baseCurrency} rate for ${form.currency}.`);

      const rate = Math.round(live * 1e6) / 1e6;
      setField("exchange_rate", String(rate));
      toast.success(`Rate applied — 1 ${form.currency} = ${rate} ${baseCurrency}`);
    } catch (e) {
      toast.error(e?.message || "Could not read today's rate.");
    } finally {
      setRateLoading(false);
    }
  };

  const openFx = () => {
    const invoiceCurrency = form?.currency || data?.currency || "USD";
    setFxCurrency(CURRENCIES.find((c) => c !== invoiceCurrency) || "AED");
    setFxRate(null);
    setFxOpen(true);
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
          {/* Quick "Mark as sent" so the status dropdown isn't needed for the usual flow. */}
          {data.status === "sent" ? (
            <button onClick={() => markSent.mutate("draft")} disabled={markSent.isLoading} title="Undo — back to draft" className="inline-flex items-center gap-1 rounded-lg border border-blue-300 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100 disabled:opacity-70">
              {markSent.isLoading ? <Spinner className="h-4 w-4" /> : <Send className="h-4 w-4" />} Sent
            </button>
          ) : data.status === "draft" ? (
            <button onClick={() => markSent.mutate("sent")} disabled={markSent.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-70">
              {markSent.isLoading ? <Spinner className="h-4 w-4" /> : <Send className="h-4 w-4" />} Mark as sent
            </button>
          ) : null}
          {data.status === "paid" ? (
            <button onClick={() => markPaid.mutate("sent")} disabled={markPaid.isLoading} title="Undo — mark as unpaid" className="inline-flex items-center gap-1 rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-100 disabled:opacity-70">
              {markPaid.isLoading ? <Spinner className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />} Paid{data.paid_at ? ` · ${ymd(data.paid_at)}` : ""}
            </button>
          ) : (
            <button onClick={() => markPaid.mutate("paid")} disabled={markPaid.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-70">
              {markPaid.isLoading ? <Spinner className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />} Mark as paid
            </button>
          )}
          <button onClick={saveWithDateCheck} disabled={save.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-70">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
          </button>
          {/* Joined to the PDF button, because that is what it is — the same
              invoice, got a different way. On its own as a bare icon it sat
              next to another bare icon and nobody found it. */}
          <div className="inline-flex overflow-hidden rounded-lg border border-slate-200">
            <button
              onClick={() => downloadPdf()}
              className="inline-flex items-center gap-1 px-3 py-2 text-sm hover:bg-slate-50"
            >
              <Download className="h-4 w-4" /> PDF
            </button>
            <button
              onClick={openFx}
              title="The same invoice with a second currency shown beside every amount"
              className="inline-flex items-center gap-1 border-l border-slate-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100"
            >
              <ArrowLeftRight className="h-4 w-4" /> 2 currencies
            </button>
          </div>
          <BackupButton type="invoice" id={id} number={data?.invoice_number} />
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
          {/* Only when it is actually a foreign invoice. One SGD is one SGD,
              and a rate box showing 1 on every local invoice is a box people
              stop reading — which is how a wrong one gets through. */}
          {form.currency !== baseCurrency && (
            <Field label={`Rate to ${baseCurrency}`}>
              <div className="flex items-center gap-2">
                <input
                  className={`${input} ${form.exchange_rate ? "" : "ring-1 ring-amber-400"}`}
                  type="number"
                  step="0.000001"
                  min="0"
                  value={form.exchange_rate ?? ""}
                  onChange={(e) => setField("exchange_rate", e.target.value)}
                  placeholder="Needed to report this invoice"
                />
                <button
                  type="button"
                  onClick={useLiveRate}
                  disabled={rateLoading}
                  title={`Fill in today's rate for ${form.currency} → ${baseCurrency}`}
                  className="shrink-0 rounded-lg border border-slate-200 px-2.5 py-2 text-slate-500 hover:bg-slate-50 disabled:opacity-60"
                >
                  {rateLoading ? <Spinner className="h-4 w-4" /> : <RefreshCw className="h-4 w-4" />}
                </button>
              </div>
              <p className="mt-1 text-xs text-slate-500">
                {form.exchange_rate
                  ? `1 ${form.currency} = ${form.exchange_rate} ${baseCurrency} · ${money(Number(form.grand_total || 0) * Number(form.exchange_rate))} ${baseCurrency}`
                  : `Until this is set, the invoice is left out of the ${baseCurrency} totals rather than counted at par.`}
              </p>
            </Field>
          )}
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

        {/* Each line is coded on its own, because one invoice can hold things
            of different kinds — a Cash to Master principal is money held for
            the vessel, the handling fee beside it is income. When they all
            belong to the same account, set it once here. */}
        <div className="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-3 py-2">
          <span className="text-xs text-slate-500">Set all account codes</span>
          <AccountCodeCell value={bulkAcct} onChange={setBulkAcct} className="w-64" placeholder="Choose account…" />
          <button
            type="button"
            disabled={!bulkAcct}
            onClick={() => {
              setLines((ls) => ls.map((l) => (l.is_heading ? l : { ...l, account_code: bulkAcct })));
              toast.success("Account code applied to every line.");
            }}
            className="rounded-lg border border-[#28364b] px-3 py-1 text-sm font-medium text-[#28364b] transition-colors hover:bg-white disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-400"
          >
            Apply to all
          </button>
        </div>

        {lines.length === 0 ? (
          <p className="py-6 text-center text-sm text-slate-400">No lines yet — add an item or a section heading above.</p>
        ) : (
          // onKeyDown: arrow keys move around the grid — see ui/gridKeys
          <div className="space-y-2" onKeyDown={gridKeyDown}>
            {lines.map((l, i) => (
              <div key={l.id ?? `new-${i}`} data-grid-row>
                <div className="flex gap-2">
                  {l.is_heading ? (
                    <input
                      className={cellInput + " flex-1 font-semibold text-[#28364b]"}
                      value={l.description}
                      onChange={(e) => setLine(i, "description", e.target.value)}
                      placeholder="Section heading"
                    />
                  ) : (
                    // textarea keeps multi-line specs intact on save
                    <textarea
                      rows={2}
                      className={cellInput + " flex-1 resize-y leading-snug"}
                      value={l.description}
                      onChange={(e) => setLine(i, "description", e.target.value)}
                      placeholder="Description (type anything)"
                    />
                  )}
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
                    {/* Required. An uncoded line cannot be told apart from
                        revenue, so the save is blocked until it is set. */}
                    <AccountCodeCell
                      full
                      className={`w-72 shrink-0 ${l.account_code ? "" : "ring-1 ring-amber-300"}`}
                      value={l.account_code}
                      onChange={(v) => setLine(i, "account_code", v)}
                      placeholder="Account…"
                    />
                    <input value={l.code} onChange={(e) => setLine(i, "code", e.target.value)} placeholder="Part-No. (optional)" className={cellInput + " w-1/5 !py-1 text-[11px]"} />
                    {/* textarea, not input: a multi-line remark carried over from
                        the enquiry would be silently flattened by a text input. */}
                    <textarea rows={1} value={l.remarks} onChange={(e) => setLine(i, "remarks", e.target.value)} placeholder="Remark — prints below the item (optional)" className={cellInput + " flex-1 resize-y !py-1 text-[11px] leading-snug"} />
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
        {/* The account decides the GST treatment and the F5 box this sale
            lands in — 4100 zero-rated, 4000 standard-rated. */}
        <div className="border-t border-slate-100 pt-3">
          <AccountSelect
            side="sales"
            value={form.account_code}
            onChange={(v) => setField("account_code", v)}
          />
        </div>
        <div className="flex items-center justify-between text-sm">
          <label className="text-slate-500">GST %</label>
          <input type="number" step="0.001" min="0" max="100" value={form.tax_rate} onChange={(e) => setField("tax_rate", e.target.value)} placeholder="0 = zero-rated" className={cellInput + " w-28 text-right"} />
        </div>
        {taxAmount > 0 && (
          <div className="flex items-center justify-between text-sm">
            <span className="text-slate-400">GST amount</span>
            <span className="text-slate-600">{taxAmount.toFixed(2)} {cur}</span>
          </div>
        )}
        <div className="flex items-center justify-between border-t border-slate-200 pt-2 text-base font-bold text-[#28364b]">
          <span>Total</span>
          <span>{grand.toFixed(2)} {cur}</span>
        </div>
      </div>

      {/* Credit memos are raised on their own screen now. What stays here is a
          pointer, so an invoice still shows at a glance that part of it has
          been credited back. */}
      {(data.credit_memos || []).length > 0 && (() => {
        const cm = data.credit_memos[0];
        return (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/60 p-4">
            <div className="flex items-center gap-2 text-sm">
              <FileMinus className="h-4 w-4 text-amber-700" />
              <span className="font-semibold text-amber-800">{cm.cm_number}</span>
              <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${cm.status === "issued" ? "bg-green-100 text-green-700" : "bg-slate-100 text-slate-600"}`}>
                {cm.status}
              </span>
              <span className="text-amber-800">
                credits {Number(cm.grand_total || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} {cur} back to the customer
                {cm.status === "issued" ? " — already off this sale in the reports." : " — not issued yet, so the reports still show the full sale."}
              </span>
            </div>
            <Link href="/credit-memos" className="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-sm font-medium text-amber-800 transition-colors hover:bg-amber-100">
              Open in Credit Memos
            </Link>
          </div>
        );
      })()}

      {/* Proof of delivery — filed against the linked delivery order, so the
          signed copy is uploaded once and shows on both screens. */}
      <ProofOfDelivery
        deliveryOrderId={data.proof_of_delivery_do?.id}
        doNumber={data.proof_of_delivery_do?.do_number}
        attachments={data.proof_of_delivery || []}
        onChange={() => refetch()}
        hint="Attach the delivery order signed and stamped by the vessel, confirming this invoice's goods were received."
      />

      <Modal open={fxOpen} onClose={() => setFxOpen(false)} title="Invoice with a second currency" maxWidth="max-w-md">
        <div className="space-y-4 px-6 py-5">
          <p className="text-sm text-slate-500">
            The same invoice, with every amount shown again in a second currency beside it. The invoice stays
            payable in <strong className="text-[#28364b]">{form?.currency || data?.currency}</strong> — the
            extra column is for the customer's own books, and the PDF says so.
          </p>

          <div>
            <label className="text-xs font-bold uppercase tracking-wider text-slate-400">Show alongside</label>
            <div className="mt-1">
              <Select
                value={fxCurrency}
                onChange={setFxCurrency}
                options={CURRENCIES.filter((c) => c !== (form?.currency || data?.currency))}
              />
            </div>
          </div>

          <div className="rounded-lg bg-slate-50 px-3 py-2.5 text-sm">
            {fxLoading ? (
              <span className="flex items-center gap-2 text-slate-500"><Spinner className="h-4 w-4" /> Reading today's rate…</span>
            ) : fxRate ? (
              <span className="text-[#28364b]">
                1 {form?.currency || data?.currency} = <strong>{fxRate}</strong> {fxCurrency}
              </span>
            ) : (
              <span className="text-amber-700">No live rate for {fxCurrency} right now — try another currency.</span>
            )}
          </div>

          <div className="flex justify-end gap-2 pt-1">
            <button onClick={() => setFxOpen(false)} className="rounded-lg border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50">
              Cancel
            </button>
            <button
              onClick={() => { downloadPdf({ currency: fxCurrency, rate: fxRate }); setFxOpen(false); }}
              disabled={!fxRate || fxLoading}
              className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-50"
            >
              <Download className="h-4 w-4" /> Download
            </button>
          </div>
        </div>
      </Modal>
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