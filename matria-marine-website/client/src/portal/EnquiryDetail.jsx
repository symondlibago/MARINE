import { useState, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Send, BarChart3, Trash2, Search, Pencil, UserPlus, ListChecks, FileDown, ChevronRight, Paperclip, CheckCircle2, ShieldCheck } from "lucide-react";
import { toast } from "sonner";
import { rfqsAPI, vendorsAPI } from "@/pages/api";
import Modal from "./ui/Modal";
import { Spinner, PageLoader } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";

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
  const qTotal = qLines.reduce((s, { row, cell }) => s + (cell.quoted ? cell.unit_cost * row.qty : 0), 0);
  const fmt = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
              Customer files
              <span className="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-0.5 font-medium normal-case tracking-normal text-slate-400">
                <ShieldCheck className="h-3 w-3 text-green-600" /> internal only
              </span>
            </div>
            <div className="mt-1.5 flex flex-wrap gap-1.5">
              {rfq.attachments.map((f) => (
                <button key={f.id} onClick={() => openCustomerFile(f.id)} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-50">
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
              <th className="px-4 py-3 text-right font-semibold">Qty</th>
              <th className="px-4 py-3 font-semibold">Unit</th>
            </tr>
          </thead>
          <tbody>
            {(rfq.items || []).map((it, idx) => (
              <tr key={it.id} className="border-b border-slate-100 last:border-0">
                <td className="px-4 py-2.5 text-slate-400">{idx + 1}</td>
                <td className="px-4 py-2.5 text-slate-700">{it.description}</td>
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

      <Modal open={sendOpen} onClose={() => setSendOpen(false)} title="Send to vendors">
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
              <p className="mb-2 text-[11px] text-slate-400">Untick anything these vendors don't supply — they'll only be asked to quote the ticked items. For vendors that carry different items (e.g. food vs. materials), send them in a separate batch.</p>
              <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                {items.map((it) => (
                  <label key={it.id} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm transition-colors hover:bg-slate-50">
                    <input type="checkbox" checked={selectedItems.includes(it.id)} onChange={() => toggleItem(it.id)} className="accent-[#28364b]" />
                    <span className="flex-1 text-slate-700">{it.description}</span>
                    <span className="text-xs text-slate-400">{Number(it.qty)}{it.unit ? ` ${it.unit}` : ""}</span>
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

      {/* Vendor quotation viewer — read-only view of what this vendor quoted */}
      <Modal open={quoteVendorId != null} onClose={() => setQuoteVendorId(null)} title={quoteRv?.vendor?.name || qv?.vendor_name || "Vendor quotation"} maxWidth="max-w-3xl">
        <div className="p-6">
          {cmpLoading || !cmp ? (
            <div className="flex justify-center py-14"><Spinner className="h-6 w-6" /></div>
          ) : !qv ? (
            <p className="py-10 text-center text-sm text-slate-400">This vendor has no quotation yet.</p>
          ) : (
            <div className="space-y-5">
              {/* Meta strip */}
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

              {/* Quoted lines */}
              <div className="overflow-hidden rounded-xl border border-slate-200">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                      <th className="px-4 py-2.5 font-semibold">Item</th>
                      <th className="px-3 py-2.5 text-right font-semibold">Qty</th>
                      <th className="px-3 py-2.5 text-right font-semibold">Unit price</th>
                      <th className="px-4 py-2.5 text-right font-semibold">Amount ({qv.currency})</th>
                      <th className="px-4 py-2.5 font-semibold">Remarks</th>
                    </tr>
                  </thead>
                  <tbody>
                    {qLines.map(({ row, cell }) => (
                      <tr key={row.rfq_item_id} className="border-b border-slate-100 align-top last:border-0">
                        <td className="px-4 py-2.5">
                          <div className="flex items-start gap-1.5">
                            <span className="text-slate-700">{row.description}</span>
                            {row.award?.vendor_id === quoteVendorId && (
                              <span className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-green-50 px-1.5 py-0.5 text-[10px] font-semibold text-green-700" title="This line is awarded to this vendor">
                                <CheckCircle2 className="h-3 w-3" /> awarded
                              </span>
                            )}
                          </div>
                        </td>
                        <td className="whitespace-nowrap px-3 py-2.5 text-right text-slate-600">{Number(row.qty)}{row.unit ? ` ${row.unit}` : ""}</td>
                        {cell.quoted ? (
                          <>
                            <td className="whitespace-nowrap px-3 py-2.5 text-right font-medium text-[#28364b]">{fmt(cell.unit_cost)}</td>
                            <td className="whitespace-nowrap px-4 py-2.5 text-right font-semibold text-[#28364b]">{fmt(cell.unit_cost * row.qty)}</td>
                          </>
                        ) : (
                          <td colSpan={2} className="px-4 py-2.5 text-right text-xs italic text-slate-400">not quoted</td>
                        )}
                        <td className="max-w-[200px] px-4 py-2.5 text-xs text-slate-500">{cell.remarks || <span className="text-slate-300">—</span>}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t-2 border-slate-200 bg-slate-50">
                      <td colSpan={3} className="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Total ({qv.currency})</td>
                      <td className="whitespace-nowrap px-4 py-2.5 text-right text-base font-bold text-[#28364b]">{fmt(qTotal)}</td>
                      <td></td>
                    </tr>
                    {qv.currency !== rfq.base_currency && (
                      <tr className="bg-slate-50">
                        <td colSpan={3} className="px-4 pb-2.5 text-right text-xs text-slate-500">≈ in {rfq.base_currency}</td>
                        <td className="whitespace-nowrap px-4 pb-2.5 text-right text-sm font-semibold text-slate-600">{fmt(qv.total_base)}</td>
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

              <div className="flex items-center justify-between border-t border-slate-100 pt-3">
                <p className="text-xs text-slate-400">Prices as submitted by the vendor.</p>
                <Link href={`/enquiries/${id}/compare`} className="inline-flex items-center gap-1 text-sm font-semibold text-[#28364b] hover:underline">
                  <BarChart3 className="h-4 w-4" /> Open Compare &amp; Award
                </Link>
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
