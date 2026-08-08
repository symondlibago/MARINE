import { useState, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Send, BarChart3, Trash2, Search, Pencil, UserPlus, ListChecks, FileDown, ChevronRight, Paperclip, CheckCircle2, ShieldCheck, Save } from "lucide-react";
import { toast } from "sonner";
import { rfqsAPI, vendorsAPI } from "@/pages/api";
import Modal from "./ui/Modal";
import Select from "./ui/Select";
import { Spinner, PageLoader } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";

// Offered in the quotation editor; the enquiry's own currency and whatever the
// vendor already quoted in are merged in, so nothing existing is ever lost.
const QUOTE_CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  sent: "bg-blue-100 text-blue-700",
  quoting: "bg-amber-100 text-amber-700",
  awarded: "bg-violet-100 text-violet-700",
  closed: "bg-green-100 text-green-700",
};

export default function EnquiryDetail({ params }) {
  const id = params.id;
  const qc = useQueryClient();
  const confirm = useConfirm();
  const [, setLocation] = useLocation();
  const [sendOpen, setSendOpen] = useState(false);
  const [selected, setSelected] = useState([]);
  const [selectedItems, setSelectedItems] = useState([]);
  const [vendorSearch, setVendorSearch] = useState("");
  const [vendorQuery, setVendorQuery] = useState("");
  const [compose, setCompose] = useState({ subject: "", message: "" });

  // Debounce the vendor search so we hit the server at most ~4x/second.
  useEffect(() => {
    const t = setTimeout(() => setVendorQuery(vendorSearch), 250);
    return () => clearTimeout(t);
  }, [vendorSearch]);

  // Opening the send dialog defaults to sending every line item.
  useEffect(() => {
    if (sendOpen) setSelectedItems((rfq?.items || []).map((it) => it.id));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sendOpen]);

  const { data: rfq, isLoading } = useQuery({
    queryKey: ["rfq", id],
    queryFn: async () => (await rfqsAPI.get(id)).data.data,
  });
  // Server-side search: only the first 50 matches are fetched & rendered.
  const { data: vendors, isFetching: vendorsFetching } = useQuery({
    queryKey: ["vendors", "send-picker", vendorQuery],
    queryFn: async () =>
      (await vendorsAPI.list({ ...(vendorQuery ? { search: vendorQuery } : {}), per_page: 50 })).data.data,
    enabled: sendOpen,
    keepPreviousData: true,
  });

  // Vendor-quotation modal: which vendor's quote is open (null = closed). Reuses
  // the compare endpoint so the modal shows exactly what Compare & Award sees.
  const [quoteVendorId, setQuoteVendorId] = useState(null);
  const { data: cmp, isLoading: cmpLoading } = useQuery({
    queryKey: ["rfq-compare", id],
    queryFn: async () => (await rfqsAPI.compare(id)).data.data,
    enabled: quoteVendorId != null,
  });

  const sendMutation = useMutation({
    mutationFn: () => rfqsAPI.send(id, { vendor_ids: selected, item_ids: selectedItems, subject: compose.subject || null, message: compose.message || null }),
    onSuccess: (res) => {
      const results = res.data.data.results;
      const ok = results.filter((r) => r.sent).length;
      const fail = results.filter((r) => !r.sent);
      toast.success(`Sent to ${ok} vendor(s).`);
      if (fail.length) toast.error(`${fail.length} failed: ${fail.map((f) => `${f.vendor} (${f.error})`).join("; ")}`);
      setSendOpen(false);
      setSelected([]);
      setVendorSearch("");
      qc.invalidateQueries({ queryKey: ["rfq", id] });
    },
    onError: () => toast.error("Send failed."),
  });

  const sendExternalMutation = useMutation({
    mutationFn: () => rfqsAPI.sendExternal(id, { vendor_ids: selected, item_ids: selectedItems }),
    onSuccess: (res) => {
      toast.success(res.data.message || "Vendors added externally.");
      setSendOpen(false);
      setSelected([]);
      setVendorSearch("");
      qc.invalidateQueries({ queryKey: ["rfq", id] });
    },
    onError: () => toast.error("Could not add vendors."),
  });

  const del = useMutation({
    mutationFn: () => rfqsAPI.remove(id),
    onSuccess: () => {
      toast.success("Enquiry deleted.");
      setLocation("/enquiries");
    },
  });

  // Download a per-vendor RFQ PDF (no prices) — only the items sent to that
  // vendor — so staff can email each vendor their own request manually.
  const downloadVendorPdf = async (vendorId, vendorName) => {
    try {
      const res = await rfqsAPI.enquiryVendorPdf(id, vendorId);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `RFQ-${rfq.reference}-${vendorName}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download PDF.");
    }
  };

  // Download the quotation this vendor submitted (prices as they sent them),
  // so staff can file or forward a vendor's offer without re-typing it.
  const [quotePdfBusy, setQuotePdfBusy] = useState(false);
  const downloadQuotePdf = async (vendorId, vendorName) => {
    setQuotePdfBusy(true);
    try {
      const res = await rfqsAPI.vendorQuotePdf(id, vendorId);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `Quotation-${rfq.reference}-${vendorName}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download this vendor's quotation.");
    } finally {
      setQuotePdfBusy(false);
    }
  };

  // Vendors phone in corrections after submitting ("wrong price, wrong qty,
  // wrong remark"), so the quotation modal doubles as an edit form. One atomic
  // save — the server refuses the whole edit rather than half-applying it.
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(null);
  const saveQuote = useMutation({
    mutationFn: ({ vendorId, payload }) => rfqsAPI.updateVendorQuote(id, vendorId, payload),
    onSuccess: (res) => {
      toast.success(res.data.message || "Quotation updated.");
      // Awards repriced, POs left alone, etc. — worth reading, so give them time.
      (res.data.data?.notes || []).forEach((n) => toast(n, { duration: 8000 }));
      setEditing(false);
      setDraft(null);
      qc.invalidateQueries({ queryKey: ["rfq-compare", id] });
      qc.invalidateQueries({ queryKey: ["rfq", id] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the quotation."),
  });

  const handleDelete = async () => {
    if (await confirm({ title: `Delete ${rfq.reference}?`, message: "This enquiry and its quotes will be removed.", confirmText: "Delete", tone: "danger" })) {
      del.mutate();
    }
  };

  if (isLoading || !rfq) return <PageLoader />;

  const sentVendors = rfq.rfq_vendors || [];
  const toggle = (vid) => setSelected((s) => (s.includes(vid) ? s.filter((x) => x !== vid) : [...s, vid]));
  const toggleItem = (iid) => setSelectedItems((s) => (s.includes(iid) ? s.filter((x) => x !== iid) : [...s, iid]));
  const items = rfq.items || [];
  // Only surface the new reference columns on enquiries that actually use them.
  const anyImpa = items.some((it) => it.impa_no);
  const anyCode = items.some((it) => it.accounting_code);
  // Guard shared by both send buttons: need vendors AND at least one item ticked.
  const guardSend = () => {
    if (selected.length === 0) { toast.error("Select at least one vendor."); return false; }
    if (items.length > 0 && selectedItems.length === 0) { toast.error("Select at least one item to send."); return false; }
    return true;
  };
  const vendorResults = vendors || [];
  // Selected vendors stay visible regardless of the current search filter.
  const selectedVendors = (vendors || []).filter((v) => selected.includes(v.id));

  // --- Vendor quotation modal: this vendor's slice of the compare payload ---
  const quoteRv = sentVendors.find((rv) => (rv.vendor_id ?? rv.vendor?.id) === quoteVendorId);
  const qv = (cmp?.vendors || []).find((v) => v.vendor_id === quoteVendorId);
  const qLines = (cmp?.rows || [])
    .map((row) => ({ row, cell: row.cells.find((c) => c.vendor_id === quoteVendorId) }))
    .filter(({ cell }) => cell && cell.asked !== false); // hide lines this vendor was never sent
  const fmt = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // While editing, every figure on screen comes from the draft so the running
  // total reacts as staff type; otherwise it comes straight from the server.
  // Both flags always move together — pair them so a half-set state can't render.
  const isEditing = editing && !!draft;
  const dItem = (rfqItemId) => draft?.items?.[rfqItemId];
  const lineQty = ({ row }) => (isEditing ? Number(dItem(row.rfq_item_id)?.qty) || 0 : Number(row.qty));
  const lineCost = ({ row, cell }) => {
    if (!isEditing) return cell.quoted ? Number(cell.unit_cost) : null;
    const raw = dItem(row.rfq_item_id)?.unit_cost;
    return raw === "" || raw == null ? null : Number(raw);
  };
  const qTotal = qLines.reduce((s, l) => s + (lineCost(l) ?? 0) * lineQty(l), 0);

  const quoteLocked = rfq.status === "closed";
  const editCurrency = isEditing ? draft.currency : qv?.currency;

  const startEdit = () => {
    setDraft({
      quotation_number: qv.quotation_number || "",
      currency: qv.currency || rfq.base_currency,
      exchange_rate: String(qv.exchange_rate ?? 1),
      items: Object.fromEntries(
        qLines.map(({ row, cell }) => [
          row.rfq_item_id,
          {
            description: row.description || "",
            qty: String(row.qty ?? ""),
            // Blank means "this vendor did not quote this line".
            unit_cost: cell.quoted ? String(cell.unit_cost) : "",
            remarks: cell.remarks || "",
          },
        ])
      ),
    });
    setEditing(true);
  };

  const cancelEdit = () => {
    setEditing(false);
    setDraft(null);
  };

  const setField = (key, value) => setDraft((d) => ({ ...d, [key]: value }));
  const setLine = (rfqItemId, key, value) =>
    setDraft((d) => ({ ...d, items: { ...d.items, [rfqItemId]: { ...d.items[rfqItemId], [key]: value } } }));

  const submitEdit = () => {
    const num = (v) => (v === "" || v == null ? null : Number(v));
    saveQuote.mutate({
      vendorId: quoteVendorId,
      payload: {
        quotation_number: draft.quotation_number || null,
        currency: draft.currency,
        exchange_rate: Number(draft.exchange_rate) || 1,
        items: Object.entries(draft.items).map(([rfqItemId, v]) => ({
          rfq_item_id: Number(rfqItemId),
          description: v.description,
          qty: num(v.qty),
          unit_cost: num(v.unit_cost),
          remarks: v.remarks || null,
        })),
      },
    });
  };
  const openAttachment = async (attachmentId) => {
    try {
      const res = await rfqsAPI.attachmentUrl(qv.quote_id, attachmentId);
      window.open(res.data.data.url, "_blank", "noopener");
    } catch {
      toast.error("Could not open attachment.");
    }
  };
  // Customer files staff attached to this enquiry (internal only).
  const openCustomerFile = async (attachmentId) => {
    try {
      const res = await rfqsAPI.fileUrl(id, attachmentId);
      window.open(res.data.data.url, "_blank", "noopener");
    } catch {
      toast.error("Could not open file.");
    }
  };

  /** A file attached to one line — these are the ones vendors receive. */
  const openLineFile = async (itemId, attachmentId) => {
    try {
      const res = await rfqsAPI.itemFileUrl(id, itemId, attachmentId);
      window.open(res.data.data.url, "_blank", "noopener");
    } catch {
      toast.error("Could not open file.");
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div className="flex items-start justify-between">
        <div>
          <Link href="/enquiries" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
            <ArrowLeft className="h-4 w-4" /> Back to enquiries
          </Link>
          <h1 className="mt-2 text-2xl font-bold text-[#28364b]">
            {rfq.reference}
            <span className={`ml-3 inline-flex rounded-full px-2 py-0.5 align-middle text-xs font-medium ${STATUS_STYLES[rfq.status] || "bg-slate-100"}`}>{rfq.status}</span>
          </h1>
        </div>
        <div className="flex gap-2">
          {/* Always available — staff can key in prices for vendors who email quotes directly. */}
          <Link href={`/enquiries/${id}/compare`} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63]">
            <BarChart3 className="h-4 w-4" /> Compare &amp; Award
          </Link>
          <Link href={`/enquiries/${id}/edit`} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
            <Pencil className="h-4 w-4" /> Edit
          </Link>
          <button onClick={handleDelete} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-500 hover:bg-red-50 hover:text-red-600">
            <Trash2 className="h-4 w-4" />
          </button>
        </div>
      </div>

      <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Info label="Customer" value={rfq.customer?.name} />
          <Info label="Customer ref" value={rfq.customer_reference} />
          <Info label="Vessel" value={rfq.ship_name} />
          <Info label="Priority" value={rfq.priority ? rfq.priority[0].toUpperCase() + rfq.priority.slice(1) : null} />
          <Info label="Requested by" value={rfq.requested_by} />
          <Info label="Delivery port" value={rfq.delivery_port} />
          <Info label="Base currency" value={rfq.base_currency} />
        </div>
        {rfq.requirements?.length > 0 && (
          <div>
            <div className="text-xs font-bold uppercase tracking-wider text-slate-400">Requirements <span className="font-normal normal-case text-slate-300">· shown to vendors</span></div>
            <div className="mt-1.5 flex flex-wrap gap-1.5">
              {rfq.requirements.map((req) => (
                <span key={req} className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">{req}</span>
              ))}
            </div>
          </div>
        )}
        {rfq.attachments?.length > 0 && (
          <div>
            <div className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-400">
              Enquiry files
              <span className="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-0.5 font-medium normal-case tracking-normal text-slate-400">
                <ShieldCheck className="h-3 w-3 text-green-600" /> internal — never sent to vendors
              </span>
            </div>
            <div className="mt-1.5 flex flex-wrap gap-1.5">
              {rfq.attachments.map((f) => (
                <button
                  key={f.id}
                  onClick={() => openCustomerFile(f.id)}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-50"
                >
                  <Paperclip className="h-3.5 w-3.5" /> {f.original_name}
                </button>
              ))}
            </div>
          </div>
        )}
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">#</th>
              <th className="px-4 py-3 font-semibold">Description</th>
              {anyImpa && <th className="px-4 py-3 font-semibold">IMPA no.</th>}
              {anyCode && <th className="px-4 py-3 font-semibold">Accounting code</th>}
              <th className="px-4 py-3 text-right font-semibold">Qty</th>
              <th className="px-4 py-3 font-semibold">Unit</th>
            </tr>
          </thead>
          <tbody>
            {(rfq.items || []).map((it, idx) => (
              <tr key={it.id} className="border-b border-slate-100 align-top last:border-0">
                <td className="px-4 py-2.5 text-slate-400">{idx + 1}</td>
                <td className="px-4 py-2.5 text-slate-700">
                  <div className="whitespace-pre-line">{it.description}</div>
                  {/* Line files ride along to whichever vendors get this line. */}
                  {it.attachments?.length > 0 && (
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                      {it.attachments.map((f) => (
                        <button
                          key={f.id}
                          onClick={() => openLineFile(it.id, f.id)}
                          title="Sent to the vendors asked to quote this line"
                          className="inline-flex items-center gap-1 rounded-md border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[11px] font-medium text-blue-700 transition-colors hover:bg-blue-100"
                        >
                          <Paperclip className="h-3 w-3" /> {f.original_name}
                        </button>
                      ))}
                    </div>
                  )}
                </td>
                {anyImpa && <td className="whitespace-nowrap px-4 py-2.5 text-slate-600">{it.impa_no || "—"}</td>}
                {anyCode && (
                  <td className="whitespace-nowrap px-4 py-2.5 text-slate-500" title="Internal — not printed on vendor or customer documents">
                    {it.accounting_code || "—"}
                  </td>
                )}
                <td className="px-4 py-2.5 text-right text-slate-600">{Number(it.qty)}</td>
                <td className="px-4 py-2.5 text-slate-600">{it.unit || "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Vendors</h2>
          <button onClick={() => setSendOpen(true)} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#3c4a63]">
            <Send className="h-4 w-4" /> Select vendors &amp; send
          </button>
        </div>
        {sentVendors.length === 0 ? (
          <p className="text-sm text-slate-400">Not sent to any vendor yet.</p>
        ) : (
          <div className="space-y-1.5">
            {sentVendors.map((rv) => (
              <div
                key={rv.id}
                onClick={() => setQuoteVendorId(rv.vendor_id ?? rv.vendor?.id)}
                title="View this vendor's quotation"
                className="group flex cursor-pointer items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm transition-colors hover:border-slate-300 hover:bg-slate-50"
              >
                <span className="flex items-center gap-2">
                  <span className="font-medium text-[#28364b]">{rv.vendor?.name}</span>
                  {rv.items?.length > 0 && rv.items.length < items.length && (
                    <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-500" title="Only some line items were sent to this vendor">
                      {rv.items.length} of {items.length} items
                    </span>
                  )}
                </span>
                <span className="flex items-center gap-2">
                  {rv.channel === "external" ? (
                    <span className="rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700">external{rv.responded_at ? " · quoted" : ""}</span>
                  ) : (
                    <span className="text-xs text-slate-500">{rv.status}{rv.responded_at ? " · quoted" : rv.opened_at ? " · opened" : " · sent"}</span>
                  )}
                  <button
                    onClick={(e) => { e.stopPropagation(); downloadVendorPdf(rv.vendor?.id, rv.vendor?.name); }}
                    title="Download this vendor's RFQ as a PDF (only the items sent to them, no prices)"
                    className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-xs text-slate-600 hover:bg-white"
                  >
                    <FileDown className="h-3.5 w-3.5" /> PDF
                  </button>
                  <ChevronRight className="h-4 w-4 text-slate-300 transition-colors group-hover:text-[#28364b]" />
                </span>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Wide: long vendor names and multi-line item descriptions were wrapping
          badly in the default modal width. max-w-5xl is double max-w-lg. */}
      <Modal open={sendOpen} onClose={() => setSendOpen(false)} title="Send to vendors" maxWidth="max-w-5xl">
        <div className="space-y-4 p-6">
          <p className="text-xs text-slate-500">Pick vendors, then <b>Send to vendors</b> to email them a quote link — or <b>Send externally</b> to add them without emailing (for vendors you contacted elsewhere; you'll key their prices in Compare &amp; Award).</p>
          <div>
            <div className="mb-1.5 flex items-center justify-between">
              <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">Recipients</label>
              <span className="text-xs text-slate-400">{selected.length} selected</span>
            </div>
            {selectedVendors.length > 0 && (
              <div className="mb-2 flex flex-wrap gap-1.5 rounded-lg border border-slate-200 bg-slate-50 p-2">
                {selectedVendors.map((v) => (
                  <span key={v.id} className="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-xs font-medium text-[#28364b] ring-1 ring-slate-200">
                    {v.name}
                    <button type="button" onClick={() => toggle(v.id)} className="text-slate-400 hover:text-red-600" title="Remove">✕</button>
                  </span>
                ))}
              </div>
            )}
            <div className="relative mb-2">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input value={vendorSearch} onChange={(e) => setVendorSearch(e.target.value)} placeholder="Search vendors…" className="w-full rounded-lg border border-slate-200 px-3 py-2 pl-9 text-sm" />
            </div>
            <div className="max-h-52 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
              {vendorsFetching && vendorResults.length === 0 ? (
                <p className="py-4 text-center text-xs text-slate-400">Searching…</p>
              ) : vendorResults.length === 0 ? (
                <p className="py-4 text-center text-xs text-slate-400">No vendors match.</p>
              ) : (
                vendorResults.map((v) => (
                  <label key={v.id} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm transition-colors hover:bg-slate-50">
                    <input type="checkbox" checked={selected.includes(v.id)} onChange={() => toggle(v.id)} className="accent-[#28364b]" />
                    <span className="font-medium text-[#28364b]">{v.name}</span>
                    <span className="text-xs text-slate-400">{v.email || "(no email)"}</span>
                  </label>
                ))
              )}
            </div>
            {vendorResults.length >= 50 && (
              <p className="mt-1 text-[11px] text-slate-400">Showing the first 50 — type to narrow down.</p>
            )}
          </div>
          {items.length > 0 && (
            <div>
              <div className="mb-1.5 flex items-center justify-between">
                <label className="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-[#28364b]">
                  <ListChecks className="h-3.5 w-3.5" /> Items to send
                </label>
                <div className="flex items-center gap-2 text-xs">
                  <span className="text-slate-400">{selectedItems.length} of {items.length}</span>
                  <button type="button" onClick={() => setSelectedItems(items.map((i) => i.id))} className="font-medium text-[#28364b] hover:underline">All</button>
                  <button type="button" onClick={() => setSelectedItems([])} className="text-slate-500 hover:underline">None</button>
                </div>
              </div>
              <p className="mb-2 text-[11px] text-slate-400">
                Untick anything these vendors don't supply — they'll only be asked to quote the ticked items. For vendors that carry
                different items (e.g. food vs. materials), send them in a separate batch. A ticked line's files are attached to the email.
              </p>
              <div className="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                {items.map((it) => (
                  <label key={it.id} className="flex cursor-pointer items-start gap-2 rounded px-2 py-1 text-sm transition-colors hover:bg-slate-50">
                    <input type="checkbox" checked={selectedItems.includes(it.id)} onChange={() => toggleItem(it.id)} className="mt-1 accent-[#28364b]" />
                    <span className="flex-1">
                      <span className="block text-slate-700 whitespace-pre-line">{it.description}</span>
                      <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                        {it.impa_no && <span className="text-[11px] text-slate-400">IMPA {it.impa_no}</span>}
                        {it.attachments?.length > 0 && (
                          <span
                            className="inline-flex items-center gap-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700"
                            title={it.attachments.map((f) => f.original_name).join(", ")}
                          >
                            <Paperclip className="h-2.5 w-2.5" /> {it.attachments.length} file{it.attachments.length === 1 ? "" : "s"} attached
                          </span>
                        )}
                      </span>
                    </span>
                    <span className="whitespace-nowrap pt-0.5 text-xs text-slate-400">{Number(it.qty)}{it.unit ? ` ${it.unit}` : ""}</span>
                  </label>
                ))}
              </div>
            </div>
          )}
          <Labeled label="Subject (optional)">
            <input className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" value={compose.subject} onChange={(e) => setCompose((c) => ({ ...c, subject: e.target.value }))} placeholder={`Request for Quotation — ${rfq.reference}`} />
          </Labeled>
          <Labeled label="Message (optional)">
            <textarea rows={3} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" value={compose.message} onChange={(e) => setCompose((c) => ({ ...c, message: e.target.value }))} placeholder="We would like to invite you to quote on the following request." />
          </Labeled>
          <div className="flex flex-wrap justify-end gap-2">
            <button onClick={() => setSendOpen(false)} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
            <button
              onClick={() => { if (guardSend()) sendExternalMutation.mutate(); }}
              disabled={sendExternalMutation.isLoading || sendMutation.isLoading}
              title="Add the selected vendors without emailing them — you'll key their prices in Compare & Award"
              className="inline-flex items-center gap-1 rounded-lg border border-[#28364b] px-4 py-2 text-sm font-semibold text-[#28364b] hover:bg-slate-50 disabled:opacity-70"
            >
              {sendExternalMutation.isLoading ? <Spinner className="h-4 w-4" /> : <UserPlus className="h-4 w-4" />}
              Send externally
            </button>
            <button
              onClick={() => { if (guardSend()) sendMutation.mutate(); }}
              disabled={sendMutation.isLoading || sendExternalMutation.isLoading}
              className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-70"
            >
              {sendMutation.isLoading ? <Spinner className="h-4 w-4" /> : <Send className="h-4 w-4" />}
              Send to {selected.length || ""} vendor{selected.length === 1 ? "" : "s"}
            </button>
          </div>
        </div>
      </Modal>

      {/* Vendor quotation — view, and correct in place when a vendor calls in a fix */}
      <Modal
        open={quoteVendorId != null}
        onClose={() => { cancelEdit(); setQuoteVendorId(null); }}
        title={quoteRv?.vendor?.name || qv?.vendor_name || "Vendor quotation"}
        maxWidth={isEditing ? "max-w-5xl" : "max-w-3xl"}
      >
        <div className="p-6">
          {cmpLoading || !cmp ? (
            <div className="flex justify-center py-14"><Spinner className="h-6 w-6" /></div>
          ) : !qv ? (
            <p className="py-10 text-center text-sm text-slate-400">This vendor has no quotation yet.</p>
          ) : (
            <div className="space-y-5">
              {/* Meta strip — becomes editable alongside the lines */}
              {isEditing ? (
                <div className="flex flex-wrap items-end gap-3">
                  <label className="text-xs font-semibold text-slate-500">
                    Quotation no.
                    <input
                      value={draft.quotation_number}
                      onChange={(e) => setField("quotation_number", e.target.value)}
                      placeholder="—"
                      className="mt-1 block w-40 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm font-normal text-[#28364b] focus:border-[#28364b] focus:outline-none"
                    />
                  </label>
                  <div className="text-xs font-semibold text-slate-500">
                    Currency
                    <Select
                      value={draft.currency}
                      onChange={(v) => setField("currency", v)}
                      options={[...new Set([rfq.base_currency, qv.currency, ...QUOTE_CURRENCIES])].filter(Boolean)}
                      className="mt-1 w-28 font-normal"
                    />
                  </div>
                  {/* Only a foreign-currency quote needs a rate; same currency is always 1:1. */}
                  {draft.currency !== rfq.base_currency && (
                    <label className="text-xs font-semibold text-slate-500">
                      Rate to {rfq.base_currency}
                      <input
                        type="number"
                        step="0.000001"
                        min="0"
                        value={draft.exchange_rate}
                        onChange={(e) => setField("exchange_rate", e.target.value)}
                        className="mt-1 block w-32 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm font-normal text-[#28364b] focus:border-[#28364b] focus:outline-none"
                      />
                    </label>
                  )}
                </div>
              ) : (
                <div className="flex flex-wrap items-center gap-2">
                  <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                    Quotation no. <span className="font-semibold text-[#28364b]">{qv.quotation_number || "—"}</span>
                  </span>
                  <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                    Currency <span className="font-semibold text-[#28364b]">{qv.currency}</span>
                  </span>
                  {Number(qv.exchange_rate) !== 1 && (
                    <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600" title={`Converted to ${rfq.base_currency} at this rate`}>
                      Rate <span className="font-semibold text-[#28364b]">× {qv.exchange_rate}</span>
                    </span>
                  )}
                  <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${qv.complete ? "bg-green-100 text-green-700" : "bg-amber-100 text-amber-700"}`}>
                    {qv.complete ? "Complete" : `Incomplete ${qv.quoted_count}/${qv.item_count}`}
                  </span>
                </div>
              )}

              {/* The two kinds of field on this form behave very differently —
                  say so before staff change the wrong one. */}
              {isEditing && (
                <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                  <strong>Price, remarks and currency</strong> change this vendor only.
                  <strong> Item and qty</strong> belong to the enquiry itself, so editing them changes what every vendor was asked for.
                </p>
              )}

              {/* Quoted lines */}
              <div className="overflow-hidden rounded-xl border border-slate-200">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                      <th className="px-4 py-2.5 font-semibold">Item</th>
                      <th className="px-3 py-2.5 text-right font-semibold">Qty</th>
                      <th className="px-3 py-2.5 text-right font-semibold">Unit price</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Amount ({editCurrency})</th>
                      <th className="px-4 py-2.5 font-semibold">Remarks</th>
                    </tr>
                  </thead>
                  <tbody>
                    {qLines.map((line) => {
                      const { row, cell } = line;
                      const d = dItem(row.rfq_item_id);
                      const cost = lineCost(line);
                      return (
                      <tr key={row.rfq_item_id} className="border-b border-slate-100 align-top last:border-0">
                        <td className="px-4 py-2.5">
                          <div className="flex items-start gap-1.5">
                            {isEditing ? (
                              <textarea
                                rows={2}
                                value={d?.description ?? ""}
                                onChange={(e) => setLine(row.rfq_item_id, "description", e.target.value)}
                                className="w-full resize-y rounded-lg border border-slate-200 px-2 py-1.5 text-sm text-slate-700 focus:border-[#28364b] focus:outline-none"
                              />
                            ) : (
                              <span className="text-slate-700 whitespace-pre-line">{row.description}</span>
                            )}
                            {/* A line can be split, so check every award on it
                                and show the share this vendor won. */}
                            {(() => {
                              const mine = (row.awards ?? (row.award ? [row.award] : [])).find(
                                (a) => a.vendor_id === quoteVendorId
                              );
                              if (!mine) return null;
                              const split = (row.awards?.length ?? 0) > 1;
                              return (
                                <span
                                  className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-green-50 px-1.5 py-0.5 text-[10px] font-semibold text-green-700"
                                  title={split ? "Part of this line is awarded to this vendor" : "This line is awarded to this vendor"}
                                >
                                  <CheckCircle2 className="h-3 w-3" /> awarded{split ? ` ${Number(mine.qty_to_buy)}` : ""}
                                </span>
                              );
                            })()}
                          </div>
                        </td>
                        {isEditing ? (
                          <>
                            <td className="whitespace-nowrap px-3 py-2.5 text-right">
                              <input
                                type="number"
                                step="0.001"
                                min="0"
                                value={d?.qty ?? ""}
                                onChange={(e) => setLine(row.rfq_item_id, "qty", e.target.value)}
                                className="w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-right text-sm text-slate-700 focus:border-[#28364b] focus:outline-none"
                              />
                              {row.unit ? <span className="ml-1 text-xs text-slate-400">{row.unit}</span> : null}
                            </td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-right">
                              <input
                                type="number"
                                step="0.0001"
                                min="0"
                                value={d?.unit_cost ?? ""}
                                onChange={(e) => setLine(row.rfq_item_id, "unit_cost", e.target.value)}
                                placeholder="not quoted"
                                className="w-24 rounded-lg border border-slate-200 px-2 py-1.5 text-right text-sm text-[#28364b] focus:border-[#28364b] focus:outline-none"
                              />
                            </td>
                            <td className="whitespace-nowrap px-4 py-2.5 text-right font-semibold text-[#28364b]">
                              {cost == null ? <span className="text-xs italic text-slate-400">—</span> : fmt(cost * lineQty(line))}
                            </td>
                            <td className="px-4 py-2.5">
                              <textarea
                                rows={2}
                                value={d?.remarks ?? ""}
                                onChange={(e) => setLine(row.rfq_item_id, "remarks", e.target.value)}
                                className="w-full min-w-[9rem] resize-y rounded-lg border border-slate-200 px-2 py-1.5 text-xs text-slate-600 focus:border-[#28364b] focus:outline-none"
                              />
                            </td>
                          </>
                        ) : (
                          <>
                            <td className="whitespace-nowrap px-3 py-2.5 text-right text-slate-600">{Number(row.qty)}{row.unit ? ` ${row.unit}` : ""}</td>
                            {cell.quoted ? (
                              <>
                                <td className="whitespace-nowrap px-3 py-2.5 text-right font-medium text-[#28364b]">{fmt(cell.unit_cost)}</td>
                                <td className="whitespace-nowrap px-4 py-2.5 text-right font-semibold text-[#28364b]">{fmt(cell.unit_cost * row.qty)}</td>
                              </>
                            ) : (
                              <td colSpan={2} className="px-4 py-2.5 text-right text-xs italic text-slate-400">not quoted</td>
                            )}
                            <td className="max-w-[200px] px-4 py-2.5 text-xs text-slate-500 whitespace-pre-line">{cell.remarks || <span className="text-slate-300">—</span>}</td>
                          </>
                        )}
                      </tr>
                      );
                    })}
                  </tbody>
                  <tfoot>
                    <tr className="border-t-2 border-slate-200 bg-slate-50">
                      <td colSpan={3} className="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Total ({editCurrency})</td>
                      <td className="whitespace-nowrap px-4 py-2.5 text-right text-base font-bold text-[#28364b]">{fmt(qTotal)}</td>
                      <td></td>
                    </tr>
                    {editCurrency !== rfq.base_currency && (
                      <tr className="bg-slate-50">
                        <td colSpan={3} className="px-4 pb-2.5 text-right text-xs text-slate-500">≈ in {rfq.base_currency}</td>
                        <td className="whitespace-nowrap px-4 pb-2.5 text-right text-sm font-semibold text-slate-600">
                          {fmt(isEditing ? qTotal * (Number(draft.exchange_rate) || 0) : qv.total_base)}
                        </td>
                        <td></td>
                      </tr>
                    )}
                  </tfoot>
                </table>
              </div>

              {/* Attachments the vendor uploaded with their quote */}
              {qv.attachments?.length > 0 && (
                <div>
                  <div className="mb-1.5 text-xs font-bold uppercase tracking-wider text-slate-400">Attachments</div>
                  <div className="flex flex-wrap gap-1.5">
                    {qv.attachments.map((a) => (
                      <button key={a.id} onClick={() => openAttachment(a.id)} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-50">
                        <Paperclip className="h-3.5 w-3.5" /> {a.name}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <p className="text-xs text-slate-400">
                  {isEditing
                    ? "Corrections apply the moment you save."
                    : quoteLocked
                      ? "Prices as submitted by the vendor. This enquiry is locked — reopen it to edit."
                      : "Prices as submitted by the vendor."}
                </p>
                {isEditing ? (
                  <div className="flex items-center gap-2">
                    <button
                      onClick={cancelEdit}
                      disabled={saveQuote.isLoading}
                      className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                    >
                      Cancel
                    </button>
                    <button
                      onClick={submitEdit}
                      disabled={saveQuote.isLoading}
                      className="inline-flex items-center gap-1.5 rounded-lg bg-[#28364b] px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-50"
                    >
                      {saveQuote.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save changes
                    </button>
                  </div>
                ) : (
                  <div className="flex items-center gap-4">
                    {!quoteLocked && (
                      <button onClick={startEdit} className="inline-flex items-center gap-1 text-sm font-semibold text-[#28364b] hover:underline">
                        <Pencil className="h-4 w-4" /> Edit quotation
                      </button>
                    )}
                    <button
                      onClick={() => downloadQuotePdf(quoteVendorId, quoteRv?.vendor?.name || qv?.vendor_name || "vendor")}
                      disabled={quotePdfBusy}
                      className="inline-flex items-center gap-1 text-sm font-semibold text-[#28364b] hover:underline disabled:opacity-50"
                    >
                      {quotePdfBusy ? <Spinner className="h-4 w-4" /> : <FileDown className="h-4 w-4" />} Download PDF
                    </button>
                    <Link href={`/enquiries/${id}/compare`} className="inline-flex items-center gap-1 text-sm font-semibold text-[#28364b] hover:underline">
                      <BarChart3 className="h-4 w-4" /> Open Compare &amp; Award
                    </Link>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>
      </Modal>
    </motion.div>
  );
}

function Info({ label, value }) {
  return (
    <div>
      <div className="text-xs font-bold uppercase tracking-wider text-slate-400">{label}</div>
      <div className="mt-0.5 text-sm text-[#28364b]">{value || "—"}</div>
    </div>
  );
}

function Labeled({ label, children }) {
  return (
    <div className="space-y-1.5">
      <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">{label}</label>
      {children}
    </div>
  );
}
