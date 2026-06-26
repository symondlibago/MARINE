import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useLocation } from "wouter";
import { motion } from "framer-motion";
import { Search, Undo2 } from "lucide-react";
import { returnNotesAPI } from "@/pages/api";
import { TableSkeleton } from "./ui/Loading";
import Select from "./ui/Select";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  issued: "bg-blue-100 text-blue-700",
};

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "draft", label: "Draft" },
  { value: "issued", label: "Issued" },
];

export default function ReturnNotes() {
  const [, setLocation] = useLocation();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["return-notes", status],
    queryFn: async () => (await returnNotesAPI.list(status ? { status } : {})).data.data,
  });

  const rows = (data ?? []).filter(
    (r) =>
      !search ||
      (r.rtn_number || "").toLowerCase().includes(search.toLowerCase()) ||
      (r.vendor || "").toLowerCase().includes(search.toLowerCase()) ||
      (r.po_number || "").toLowerCase().includes(search.toLowerCase())
  );

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Return Notes</h1>
        <p className="text-sm text-slate-500">{rows.length} return note{rows.length === 1 ? "" : "s"} · goods returned to vendors</p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-[220px] flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search RTN #, PO #, vendor…"
            className="w-full rounded-lg border border-slate-200 px-3 py-2 pl-9 text-sm"
          />
        </div>
        <div className="w-44"><Select value={status} onChange={setStatus} options={STATUS_OPTIONS} /></div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Return note</th>
              <th className="px-4 py-3 font-semibold">Against PO</th>
              <th className="px-4 py-3 font-semibold">Vendor</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="px-4 py-3 text-right font-semibold">Items</th>
              <th className="px-4 py-3 text-right font-semibold">Credit</th>
              <th className="px-4 py-3 text-right font-semibold">Date</th>
            </tr>
          </thead>
          {isLoading ? (
            <tbody><TableSkeleton cols={7} /></tbody>
          ) : rows.length === 0 ? (
            <tbody>
              <tr>
                <td colSpan={7} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center gap-2">
                    <Undo2 className="h-8 w-8 text-slate-300" />
                    No return notes yet — raise one from a purchase order when goods are returned.
                  </div>
                </td>
              </tr>
            </tbody>
          ) : (
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} onClick={() => setLocation(`/return-notes/${r.id}`)} className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-amber-50/40">
                  <td className="px-4 py-2.5 font-medium text-[#28364b]">{r.rtn_number}</td>
                  <td className="px-4 py-2.5 text-slate-700">{r.po_number || "—"}</td>
                  <td className="px-4 py-2.5 text-slate-700">{r.vendor || "—"}</td>
                  <td className="px-4 py-2.5">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[r.status] || "bg-slate-100"}`}>{r.status}</span>
                  </td>
                  <td className="px-4 py-2.5 text-right text-slate-600">{r.items_count}</td>
                  <td className="px-4 py-2.5 text-right font-medium text-amber-700">{Number(r.subtotal).toFixed(2)} <span className="text-xs font-normal text-slate-400">{r.currency}</span></td>
                  <td className="px-4 py-2.5 text-right text-slate-500">{r.return_date || "—"}</td>
                </tr>
              ))}
            </tbody>
          )}
        </table>
      </div>
    </motion.div>
  );
}
