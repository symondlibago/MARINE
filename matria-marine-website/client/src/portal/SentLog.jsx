import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { motion } from "framer-motion";
import { Search, Send, CheckCircle2, AlertCircle } from "lucide-react";
import { sentLogsAPI } from "@/pages/api";
import { TableSkeleton } from "./ui/Loading";
import Select from "./ui/Select";

const TYPE_OPTIONS = [
  { value: "", label: "All types" },
  { value: "RFQ", label: "RFQ (inquiry)" },
  { value: "Purchase Order", label: "Purchase Order" },
  { value: "Quotation", label: "Quotation" },
  { value: "Return Note", label: "Return Note" },
];

const TYPE_STYLES = {
  RFQ: "bg-blue-100 text-blue-700",
  "Purchase Order": "bg-amber-100 text-amber-700",
  Quotation: "bg-green-100 text-green-700",
  "Return Note": "bg-purple-100 text-purple-700",
};

export default function SentLog() {
  const [search, setSearch] = useState("");
  const [type, setType] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["sent-logs", type],
    queryFn: async () => (await sentLogsAPI.list(type ? { type } : {})).data.data,
  });

  const rows = (data ?? []).filter(
    (r) =>
      !search ||
      (r.reference || "").toLowerCase().includes(search.toLowerCase()) ||
      (r.recipient_name || "").toLowerCase().includes(search.toLowerCase()) ||
      (r.recipient_email || "").toLowerCase().includes(search.toLowerCase()) ||
      (r.sent_by || "").toLowerCase().includes(search.toLowerCase())
  );

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Sent Log</h1>
        <p className="text-sm text-slate-500">Proof of every document email sent — RFQs, purchase orders, quotations and return notes.</p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-[240px] flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search reference, recipient, email, sent by…"
            className="w-full rounded-lg border border-slate-200 px-3 py-2 pl-9 text-sm"
          />
        </div>
        <div className="w-52"><Select value={type} onChange={setType} options={TYPE_OPTIONS} /></div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Sent</th>
              <th className="px-4 py-3 font-semibold">Type</th>
              <th className="px-4 py-3 font-semibold">Reference</th>
              <th className="px-4 py-3 font-semibold">Recipient</th>
              <th className="px-4 py-3 font-semibold">Sent by</th>
              <th className="px-4 py-3 font-semibold">Status</th>
            </tr>
          </thead>
          {isLoading ? (
            <tbody><TableSkeleton cols={6} /></tbody>
          ) : rows.length === 0 ? (
            <tbody>
              <tr>
                <td colSpan={6} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center gap-2">
                    <Send className="h-8 w-8 text-slate-300" />
                    Nothing sent yet — when you email an RFQ, PO, quotation or return note, it shows here.
                  </div>
                </td>
              </tr>
            </tbody>
          ) : (
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-3 whitespace-nowrap text-slate-500">{r.sent_at}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${TYPE_STYLES[r.type] || "bg-slate-100 text-slate-600"}`}>{r.type}</span>
                  </td>
                  <td className="px-4 py-3 font-medium text-[#28364b]">{r.reference || "—"}</td>
                  <td className="px-4 py-3 text-slate-700">
                    {r.recipient_name || "—"}
                    {r.recipient_email && <span className="block text-xs text-slate-400">{r.recipient_email}</span>}
                  </td>
                  <td className="px-4 py-3 text-slate-500">{r.sent_by || "—"}</td>
                  <td className="px-4 py-3">
                    {r.status === "sent" ? (
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-green-700"><CheckCircle2 className="h-4 w-4" /> Sent</span>
                    ) : (
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-red-600" title={r.error || ""}><AlertCircle className="h-4 w-4" /> Failed</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          )}
        </table>
      </div>
    </motion.div>
  );
}
