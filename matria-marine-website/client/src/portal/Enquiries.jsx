import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { Plus, Lock, Unlock, Paperclip } from "lucide-react";
import { toast } from "sonner";
import { rfqsAPI } from "@/pages/api";
import { TableSkeleton, Spinner } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";
import ListToolbar from "./ui/ListToolbar";
import { useDebounced } from "./ui/useDebounced";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  sent: "bg-blue-100 text-blue-700",
  quoting: "bg-amber-100 text-amber-700",
  awarded: "bg-violet-100 text-violet-700",
  closed: "bg-green-100 text-green-700",
};

// The statuses an enquiry can actually hold on each tab.
const OPEN_STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "draft", label: "Draft" },
  { value: "sent", label: "Sent" },
  { value: "quoting", label: "Quoting" },
  { value: "awarded", label: "Awarded" },
];

const CLOSED_STATUS_OPTIONS = [{ value: "", label: "All statuses" }];

const PRIORITY_STYLES = {
  low: "bg-slate-100 text-slate-500",
  normal: "bg-slate-100 text-slate-600",
  high: "bg-orange-100 text-orange-700",
  urgent: "bg-red-100 text-red-700",
};

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Markup as a share of what we paid, which is the number people quote. */
const markupPct = (offer) => {
  const cost = Number(offer?.subtotal || 0) - Number(offer?.markup_total || 0);
  if (cost <= 0) return "";

  return `(${Math.round((Number(offer.markup_total) / cost) * 100)}%)`;
};

const shortDate = (d) =>
  d ? new Date(d).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

/**
 * How many invited vendors have actually put a price in — the thing you would
 * otherwise have to open Compare & Award to find out.
 *
 * A vendor who emailed their quotation as a file but has no price keyed in yet
 * shows as a paperclip: something came back, it just needs reading.
 */
function QuotedBadge({ row }) {
  const invited = row.rfq_vendors_count || 0;
  const priced = row.priced_quotes_count || 0;
  // Vendors holding a file with no price against it — not a total to compare,
  // since the vendor who priced and the vendor who sent a file are different.
  const waiting = row.unpriced_files_count || 0;

  if (invited === 0) {
    return <span className="text-xs text-slate-300" title="No vendors sent this enquiry yet">not sent</span>;
  }

  const tone =
    priced === 0
      ? "bg-slate-100 text-slate-500"
      : priced >= invited
        ? "bg-green-100 text-green-700"
        : "bg-amber-100 text-amber-700";

  const title =
    `${priced} of ${invited} vendor${invited === 1 ? "" : "s"} have priced at least one line` +
    (waiting ? ` · ${waiting} sent a quotation file that is not priced in yet` : "");

  return (
    <span className="inline-flex items-center gap-1">
      <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${tone}`} title={title}>
        {priced}/{invited}
      </span>
      {waiting > 0 && (
        <Paperclip className="h-3 w-3 text-amber-500" title={`${waiting} vendor file(s) waiting to be priced in`} />
      )}
    </span>
  );
}

export default function Enquiries() {
  const [, setLocation] = useLocation();
  const confirm = useConfirm();
  const [tab, setTab] = useState("open");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  // Which row is mid-request, so only that button spins.
  const [busyId, setBusyId] = useState(null);

  // The box updates as you type; the request waits until you stop.
  const term = useDebounced(search);

  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: ["rfqs", tab, status, term],
    queryFn: async () => (await rfqsAPI.list({ tab, status: status || undefined, q: term || undefined })).data,
    // Typing or switching tabs keeps the old table on screen instead of
    // flashing a skeleton on every keystroke.
    keepPreviousData: true,
  });

  const rows = data?.data ?? [];
  const counts = data?.meta ?? { open: 0, closed: 0 };

  const closeMutation = useMutation({
    mutationFn: (id) => rfqsAPI.finish(id),
    onSuccess: (res) => { toast.success(res.data.message || "Enquiry closed."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not close the enquiry."),
    onSettled: () => setBusyId(null),
  });

  const reopenMutation = useMutation({
    mutationFn: (id) => rfqsAPI.reopen(id),
    onSuccess: (res) => { toast.success(res.data.message || "Enquiry reopened."); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not reopen the enquiry."),
    onSettled: () => setBusyId(null),
  });

  /**
   * Closing locks the enquiry and marks the winning vendors as selected. It is
   * reversible, so the warning says so — but it also names any line still
   * without a vendor, which is the mistake worth catching before it locks.
   */
  const closeEnquiry = async (row) => {
    const unawarded = Math.max(0, (row.items_count || 0) - (row.awarded_items_count || 0));

    const ok = await confirm({
      title: `Close ${row.reference}?`,
      message:
        (unawarded > 0
          ? `${unawarded} of ${row.items_count} line${row.items_count === 1 ? "" : "s"} still have no vendor selected — closing now locks them that way.\n\n`
          : "") +
        "The enquiry is locked and the winning vendors are marked as selected. Prices and the vendor selection can no longer be edited on Compare & Award.\n\n" +
        "You can reopen it from the Closed tab if you need to change something.",
      confirmText: "Close enquiry",
    });

    if (!ok) return;
    setBusyId(row.id);
    closeMutation.mutate(row.id);
  };

  const reopenEnquiry = async (row) => {
    const ok = await confirm({
      title: `Reopen ${row.reference}?`,
      message: "It moves back to the open list and Compare & Award becomes editable again.",
      confirmText: "Reopen",
    });

    if (!ok) return;
    setBusyId(row.id);
    reopenMutation.mutate(row.id);
  };

  const tabClass = (name) =>
    `relative -mb-px border-b-2 px-4 py-2 text-sm font-semibold transition-colors ${
      tab === name
        ? "border-[#28364b] text-[#28364b]"
        : "border-transparent text-slate-400 hover:text-slate-600"
    }`;

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Enquiries</h1>
          <p className="text-sm text-slate-500">
            {tab === "open" ? `${counts.open} in progress` : `${counts.closed} closed`}
            {(term || status) && " matching"}
          </p>
        </div>
        <Link href="/enquiries/new" className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63]">
          <Plus className="h-4 w-4" /> New Enquiry
        </Link>
      </div>

      {/* Closed enquiries only ever pile up; keeping them on their own tab means
          the list you work from stays the length of the work actually open. */}
      <div className="flex items-center gap-1 border-b border-slate-200">
        {/* Switching tabs drops the status filter: "awarded" carried onto the
            closed tab is a guaranteed empty table with no obvious cause. */}
        <button type="button" onClick={() => { setTab("open"); setStatus(""); }} className={tabClass("open")}>
          In progress <span className="ml-1 text-xs font-normal text-slate-400">{counts.open}</span>
        </button>
        <button type="button" onClick={() => { setTab("closed"); setStatus(""); }} className={tabClass("closed")}>
          Closed <span className="ml-1 text-xs font-normal text-slate-400">{counts.closed}</span>
        </button>
        {isFetching && !isLoading && <Spinner className="ml-2 h-3.5 w-3.5 text-slate-300" />}
      </div>

      <ListToolbar
        search={search}
        onSearch={setSearch}
        placeholder="Search reference, customer, vessel or their ref…"
        filters={[
          {
            value: status,
            onChange: setStatus,
            // Closed lives on its own tab, so offering it here as well would
            // just be a way to produce an empty table.
            options: tab === "closed" ? CLOSED_STATUS_OPTIONS : OPEN_STATUS_OPTIONS,
            title: "Filter by status",
          },
        ]}
      />

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-3 py-3 font-semibold">Reference</th>
              <th className="px-3 py-3 font-semibold">Customer</th>
              <th className="px-3 py-3 font-semibold">Customer ref.</th>
              <th className="px-3 py-3 font-semibold">Vessel</th>
              <th className="px-3 py-3 font-semibold">Received</th>
              <th className="px-3 py-3 font-semibold">Priority</th>
              <th className="px-3 py-3 font-semibold">Status</th>
              <th className="px-3 py-3 text-right font-semibold">Items</th>
              <th className="px-3 py-3 text-right font-semibold">Vendors</th>
              <th className="px-3 py-3 text-center font-semibold">Quoted</th>
              <th className="px-3 py-3 text-right font-semibold">Total</th>
              <th className="px-3 py-3 text-right font-semibold">Markup</th>
              <th className="px-3 py-3 font-semibold">Cur.</th>
              <th className="px-3 py-3 text-right font-semibold">{tab === "open" ? "Close" : "Reopen"}</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <TableSkeleton cols={14} />
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={14} className="py-10 text-center text-slate-400">
                  {term || status
                    ? `Nothing ${tab === "open" ? "in progress" : "closed"} matches that.`
                    : tab === "open"
                      ? "Nothing in progress — create your first enquiry."
                      : "No closed enquiries yet."}
                </td>
              </tr>
            ) : (
              rows.map((r, i) => (
                <motion.tr
                  key={r.id}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: Math.min(i * 0.02, 0.3) }}
                  onClick={() => setLocation(`/enquiries/${r.id}`)}
                  className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-slate-50"
                >
                  <td className="px-3 py-3 font-medium text-[#28364b] whitespace-nowrap">{r.reference}</td>
                  <td className="px-3 py-3 text-slate-700">{r.customer?.name || "—"}</td>
                  <td className="px-3 py-3 text-slate-500">{r.customer_reference || "—"}</td>
                  <td className="px-3 py-3 text-slate-700">{r.ship_name || "—"}</td>
                  <td className="px-3 py-3 whitespace-nowrap text-slate-500">{shortDate(r.received_date)}</td>
                  <td className="px-3 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${PRIORITY_STYLES[r.priority] || "bg-slate-100 text-slate-600"}`}>
                      {r.priority || "normal"}
                    </span>
                  </td>
                  <td className="px-3 py-3">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[r.status] || "bg-slate-100"}`}>{r.status}</span>
                  </td>
                  <td className="px-3 py-3 text-right text-slate-600">{r.items_count}</td>
                  <td className="px-3 py-3 text-right text-slate-600">{r.rfq_vendors_count}</td>
                  <td className="px-3 py-3 text-center"><QuotedBadge row={r} /></td>
                  {/* Money only once a quotation exists — until then there is no
                      figure to show, and a 0.00 would read as a free job. */}
                  <td className="px-3 py-3 text-right font-medium text-[#28364b] whitespace-nowrap">
                    {r.offer ? money(r.offer.grand_total ?? r.offer.subtotal) : <span className="text-slate-300">—</span>}
                  </td>
                  <td className="px-3 py-3 text-right whitespace-nowrap">
                    {r.offer ? (
                      <span className="text-green-700">
                        {money(r.offer.markup_total)}
                        <span className="ml-1 text-xs text-slate-400">{markupPct(r.offer)}</span>
                      </span>
                    ) : (
                      <span className="text-slate-300">—</span>
                    )}
                  </td>
                  <td className="px-3 py-3 text-xs text-slate-500">{r.offer?.currency || r.base_currency || "—"}</td>
                  {/* The row itself opens the enquiry, so the action must not. */}
                  <td className="px-3 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                    {tab === "open" ? (
                      <button
                        type="button"
                        onClick={() => closeEnquiry(r)}
                        disabled={busyId === r.id}
                        title="Close this enquiry without opening Compare & Award"
                        className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 transition-colors hover:border-[#28364b] hover:text-[#28364b] disabled:opacity-50"
                      >
                        {busyId === r.id ? <Spinner className="h-3 w-3" /> : <Lock className="h-3 w-3" />} Close
                      </button>
                    ) : (
                      <button
                        type="button"
                        onClick={() => reopenEnquiry(r)}
                        disabled={busyId === r.id}
                        title="Put this enquiry back in progress"
                        className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 transition-colors hover:border-[#28364b] hover:text-[#28364b] disabled:opacity-50"
                      >
                        {busyId === r.id ? <Spinner className="h-3 w-3" /> : <Unlock className="h-3 w-3" />} Reopen
                      </button>
                    )}
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
