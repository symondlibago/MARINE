import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { Search, Plus, Files } from "lucide-react";
import { documentsAPI } from "@/pages/api";
import { TableSkeleton } from "./ui/Loading";
import Select from "./ui/Select";

const TYPE_STYLES = {
  invoice: "bg-blue-100 text-blue-700",
  quotation: "bg-violet-100 text-violet-700",
  enquiry: "bg-amber-100 text-amber-700",
  delivery_note: "bg-emerald-100 text-emerald-700",
};

const TYPE_OPTIONS = [
  { value: "", label: "All types" },
  { value: "invoice", label: "Invoice" },
  { value: "quotation", label: "Quotation" },
  { value: "enquiry", label: "Enquiry" },
  { value: "delivery_note", label: "Delivery Note" },
];

export default function Documents() {
  const [, setLocation] = useLocation();
  const [search, setSearch] = useState("");
  const [type, setType] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["documents", type],
    queryFn: async () => (await documentsAPI.list(type ? { type } : {})).data.data,
  });

  const all = data ?? [];
  const rows = all.filter(
    (r) =>
      !search ||
      (r.number || "").toLowerCase().includes(search.toLowerCase()) ||
      (r.party_name || "").toLowerCase().includes(search.toLowerCase())
  );

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Documents</h1>
          <p className="text-sm text-slate-500">Invoices, quotations, enquiries &amp; delivery notes</p>
        </div>
        <Link href="/documents/new" className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63]">
          <Plus className="h-4 w-4" /> New document
        </Link>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-[220px] flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search number or recipient…" className="w-full rounded-lg border border-slate-200 px-3 py-2 pl-9 text-sm" />
        </div>
        <div className="w-48">
          <Select value={type} onChange={setType} options={TYPE_OPTIONS} />
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Number</th>
              <th className="px-4 py-3 font-semibold">Type</th>
              <th className="px-4 py-3 font-semibold">Recipient</th>
              <th className="px-4 py-3 text-right font-semibold">Date</th>
              <th className="px-4 py-3 text-right font-semibold">Total</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <TableSkeleton cols={5} />
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={5} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center gap-2">
                    <Files className="h-8 w-8 text-slate-300" />
                    No documents yet — click <span className="font-medium">New document</span> to make one.
                  </div>
                </td>
              </tr>
            ) : (
              rows.map((r, i) => (
                <motion.tr
                  key={r.id}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: Math.min(i * 0.02, 0.3) }}
                  onClick={() => setLocation(`/documents/${r.id}`)}
                  className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-4 py-3 font-medium text-[#28364b]">{r.number}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${TYPE_STYLES[r.type] || "bg-slate-100"}`}>{r.type_label}</span>
                  </td>
                  <td className="px-4 py-3 text-slate-700">{r.party_name || "—"}</td>
                  <td className="px-4 py-3 text-right text-slate-500">{r.date || "—"}</td>
                  <td className="px-4 py-3 text-right font-medium text-[#28364b]">
                    {Number(r.total).toFixed(2)} <span className="text-xs font-normal text-slate-400">{r.currency}</span>
                  </td>
                </motion.tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </motion.div>
  );
}
