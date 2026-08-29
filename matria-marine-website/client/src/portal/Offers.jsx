import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useLocation } from "wouter";
import { motion } from "framer-motion";
import { Tag } from "lucide-react";
import { offersAPI } from "@/pages/api";
import { TableSkeleton } from "./ui/Loading";
import ListToolbar from "./ui/ListToolbar";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  sent: "bg-blue-100 text-blue-700",
  accepted: "bg-green-100 text-green-700",
  declined: "bg-red-100 text-red-700",
};

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "draft", label: "Draft" },
  { value: "sent", label: "Sent" },
  { value: "accepted", label: "Accepted" },
  { value: "declined", label: "Declined" },
];

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const shortDate = (d) =>
  d ? new Date(d).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

/** Markup as a share of what we paid, which is the number people quote. */
const markupPct = (r) => {
  const cost = Number(r.base_total || 0);
  if (cost <= 0) return "";

  return `(${Math.round((Number(r.markup_total || 0) / cost) * 100)}%)`;
};

/** Past its validity and not yet accepted — still quotable by mistake. */
const expired = (r) =>
  r.valid_until && r.status !== "accepted" && new Date(r.valid_until) < new Date(new Date().toDateString());

export default function Offers() {
  const [, setLocation] = useLocation();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["offers"],
    queryFn: async () => (await offersAPI.list()).data.data,
  });

  // Filtered here rather than on the server: the list is already loaded whole,
  // so a round trip per keystroke would be slower, not faster.
  const rows = useMemo(() => {
    const all = data ?? [];
    const q = search.trim().toLowerCase();

    return all.filter((r) => {
      if (status && r.status !== status) return false;
      if (!q) return true;

      return [r.offer_number, r.customer?.name || r.customer_name, r.rfq?.reference, r.creator?.name]
        .some((v) => String(v || "").toLowerCase().includes(q));
    });
  }, [data, search, status]);

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Offers</h1>
        <p className="text-sm text-slate-500">Customer quotations built from enquiries, with your markup.</p>
      </div>

      <ListToolbar
        search={search}
        onSearch={setSearch}
        placeholder="Search offer #, customer, enquiry or who quoted it…"
        filters={[{ value: status, onChange: setStatus, options: STATUS_OPTIONS, title: "Filter by status" }]}
      />

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-3 py-3 font-semibold">Offer</th>
              <th className="px-3 py-3 font-semibold">Customer</th>
              <th className="px-3 py-3 font-semibold">Customer ref.</th>
              <th className="px-3 py-3 font-semibold">Vessel</th>
              <th className="px-3 py-3 font-semibold">Enquiry</th>
              <th className="px-3 py-3 font-semibold">Quoted by</th>
              <th className="px-3 py-3 font-semibold">Issued</th>
              <th className="px-3 py-3 font-semibold">Valid until</th>
              <th className="px-3 py-3 font-semibold">Status</th>
              <th className="px-3 py-3 text-right font-semibold">Cost</th>
              <th className="px-3 py-3 text-right font-semibold">Markup</th>
              <th className="px-3 py-3 text-right font-semibold">Total</th>
              <th className="px-3 py-3 font-semibold">Cur.</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <TableSkeleton cols={13} />
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={13} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center gap-2">
                    <Tag className="h-8 w-8 text-slate-300" />
                    {search || status ? (
                      "No offers match that."
                    ) : (
                      <>
                        No offers yet — open an enquiry's <span className="font-medium">Compare &amp; Award</span> and click <span className="font-medium">Markup &amp; Offer</span>.
                      </>
                    )}
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
                  onClick={() => setLocation(`/offers/${r.id}`)}
                  className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-3 py-3 font-medium text-[#28364b] whitespace-nowrap">{r.offer_number}</td>
                  <td className="px-3 py-3 text-slate-700">{r.customer?.name || r.customer_name || "—"}</td>
                  <td className="px-3 py-3 text-slate-500">{r.rfq?.customer_reference || "—"}</td>
                  <td className="px-3 py-3 text-slate-700">{r.rfq?.ship_name || "—"}</td>
                  <td className="px-3 py-3 text-slate-500 whitespace-nowrap">{r.rfq?.reference || "—"}</td>
                  <td className="px-3 py-3 text-slate-500">{r.creator?.name || "—"}</td>
                  <td className="px-3 py-3 text-slate-500 whitespace-nowrap">{shortDate(r.created_at)}</td>
                  {/* An expired quotation is still quotable by mistake, so the
                      date says so rather than just sitting there. */}
                  <td className={`px-3 py-3 whitespace-nowrap ${expired(r) ? "font-medium text-red-600" : "text-slate-500"}`}>
                    {shortDate(r.valid_until)}
                    {expired(r) && <span className="ml-1 text-xs">expired</span>}
                  </td>
                  <td className="px-3 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[r.status] || "bg-slate-100"}`}>{r.status}</span>
                  </td>
                  {/* What we pay the vendors — internal, never on the quotation. */}
                  <td className="px-3 py-3 text-right text-slate-500 whitespace-nowrap">{money(r.base_total)}</td>
                  <td className="px-3 py-3 text-right text-green-700 whitespace-nowrap">
                    {money(r.markup_total)}
                    <span className="ml-1 text-xs text-slate-400">{markupPct(r)}</span>
                  </td>
                  <td className="px-3 py-3 text-right font-medium text-[#28364b] whitespace-nowrap">
                    {money(r.grand_total ?? r.subtotal)}
                  </td>
                  <td className="px-3 py-3 text-xs text-slate-500">{r.currency}</td>
                </motion.tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </motion.div>
  );
}
