import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Send, BarChart3, Trash2, Search, Pencil } from "lucide-react";
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
  const [vendorSearch, setVendorSearch] = useState("");
  const [compose, setCompose] = useState({ subject: "", message: "" });

  const { data: rfq, isLoading } = useQuery({
    queryKey: ["rfq", id],
    queryFn: async () => (await rfqsAPI.get(id)).data.data,
  });
  const { data: vendors } = useQuery({
    queryKey: ["vendors", ""],
    queryFn: async () => (await vendorsAPI.list()).data.data,
  });

  const sendMutation = useMutation({
    mutationFn: () => rfqsAPI.send(id, { vendor_ids: selected, subject: compose.subject || null, message: compose.message || null }),
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

  const del = useMutation({
    mutationFn: () => rfqsAPI.remove(id),
    onSuccess: () => {
      toast.success("Enquiry deleted.");
      setLocation("/enquiries");
    },
  });

  const handleDelete = async () => {
    if (await confirm({ title: `Delete ${rfq.reference}?`, message: "This enquiry and its quotes will be removed.", confirmText: "Delete", tone: "danger" })) {
      del.mutate();
    }
  };

  if (isLoading || !rfq) return <PageLoader />;

  const sentVendors = rfq.rfq_vendors || [];
  const hasQuotes = (rfq.quotes || []).length > 0;
  const toggle = (vid) => setSelected((s) => (s.includes(vid) ? s.filter((x) => x !== vid) : [...s, vid]));
  const filteredVendors = (vendors || []).filter(
    (v) => v.name.toLowerCase().includes(vendorSearch.toLowerCase()) || (v.email || "").toLowerCase().includes(vendorSearch.toLowerCase())
  );

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
          {hasQuotes && (
            <Link href={`/enquiries/${id}/compare`} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63]">
              <BarChart3 className="h-4 w-4" /> Compare &amp; Award
            </Link>
          )}
          <Link href={`/enquiries/${id}/edit`} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
            <Pencil className="h-4 w-4" /> Edit
          </Link>
          <button onClick={handleDelete} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-500 hover:bg-red-50 hover:text-red-600">
            <Trash2 className="h-4 w-4" />
          </button>
        </div>
      </div>

      <div className="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-4">
        <Info label="Vessel" value={rfq.ship_name} />
        <Info label="Requested by" value={rfq.requested_by} />
        <Info label="Delivery port" value={rfq.delivery_port} />
        <Info label="Base currency" value={rfq.base_currency} />
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
              <div key={rv.id} className="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm">
                <span className="font-medium text-[#28364b]">{rv.vendor?.name}</span>
                <span className="text-xs text-slate-500">{rv.status}{rv.responded_at ? " · quoted" : rv.opened_at ? " · opened" : " · sent"}</span>
              </div>
            ))}
          </div>
        )}
      </div>

      <Modal open={sendOpen} onClose={() => setSendOpen(false)} title="Send to vendors">
        <div className="space-y-4 p-6">
          <p className="text-xs text-slate-500">Each selected vendor gets a unique link. They reply to your account email.</p>
          <div>
            <div className="mb-1.5 flex items-center justify-between">
              <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">Recipients</label>
              <span className="text-xs text-slate-400">{selected.length} selected</span>
            </div>
            <div className="relative mb-2">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input value={vendorSearch} onChange={(e) => setVendorSearch(e.target.value)} placeholder="Search vendors…" className="w-full rounded-lg border border-slate-200 px-3 py-2 pl-9 text-sm" />
            </div>
            <div className="max-h-52 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
              {filteredVendors.length === 0 ? (
                <p className="py-4 text-center text-xs text-slate-400">No vendors match.</p>
              ) : (
                filteredVendors.map((v) => (
                  <label key={v.id} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm transition-colors hover:bg-slate-50">
                    <input type="checkbox" checked={selected.includes(v.id)} onChange={() => toggle(v.id)} className="accent-[#28364b]" />
                    <span className="font-medium text-[#28364b]">{v.name}</span>
                    <span className="text-xs text-slate-400">{v.email || "(no email)"}</span>
                  </label>
                ))
              )}
            </div>
          </div>
          <Labeled label="Subject (optional)">
            <input className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" value={compose.subject} onChange={(e) => setCompose((c) => ({ ...c, subject: e.target.value }))} placeholder={`Request for Quotation — ${rfq.reference}`} />
          </Labeled>
          <Labeled label="Message (optional)">
            <textarea rows={3} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" value={compose.message} onChange={(e) => setCompose((c) => ({ ...c, message: e.target.value }))} placeholder="We would like to invite you to quote on the following request." />
          </Labeled>
          <div className="flex justify-end gap-2">
            <button onClick={() => setSendOpen(false)} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
            <button
              onClick={() => { if (selected.length === 0) { toast.error("Select at least one vendor."); return; } sendMutation.mutate(); }}
              disabled={sendMutation.isLoading}
              className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-70"
            >
              {sendMutation.isLoading ? <Spinner className="h-4 w-4" /> : <Send className="h-4 w-4" />}
              Send to {selected.length || ""} vendor{selected.length === 1 ? "" : "s"}
            </button>
          </div>
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
