import { Fragment, useState } from "react";
import { Link, useLocation } from "wouter";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ClipboardList,
  Download,
  ExternalLink,
  Ship,
  Wallet,
  AlertTriangle,
  Info,
  SendHorizontal,
  FileSearch,
  ChevronRight,
} from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import OrderLines from "./OrderLines";
import { StatCard, money, dateOf, btn, downloadBlob } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";

const TABS = [
  { value: "approved", label: "To buy", hint: "Approved on board, not yet ordered" },
  { value: "issued", label: "Ordered", hint: "Sent out to vendors" },
  { value: "closed", label: "Closed", hint: "Delivered and done" },
];

/**
 * Every vessel's approved orders, in one place.
 *
 * This is the handover. An order on this list has been written by a cook and
 * signed off by his master, which is the ship saying "we need this and we
 * stand behind it". From here it is the office's: a vendor to ask, a price to
 * agree, a purchase order to raise.
 *
 * The only inventory screen that reads across vessels, which is why it does
 * not sit inside one.
 */
export default function Inbox() {
  const qc = useQueryClient();
  // Relative to the /inventory nest, so the fleet's sidebar stays put.
  const [, navigate] = useLocation();
  const [status, setStatus] = useState("approved");
  const [downloading, setDownloading] = useState(null);
  const [openOrder, setOpenOrder] = useState(null);   // order showing its lines

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "inbox", status],
    queryFn: async () => (await inventoryAPI.requisitionInbox({ status })).data.data,
  });

  /**
   * The handover: the order becomes an ordinary enquiry, and the browser goes
   * straight to it with the vendor dialog open.
   *
   * Asking vendors is the next thing that happens to an approved order, and the
   * portal already has the screen for it — pick vendors, pick lines, then
   * either email them a quote link or add them as contacted elsewhere. Sending
   * the buyer there with that dialog up is one step instead of three, and it is
   * the same dialog: one Compare & Award, one purchase order sequence, one set
   * of books, however the work arrived.
   *
   * Still inside /inventory, so the fleet's sidebar does not go anywhere.
   */
  const send = useMutation({
    mutationFn: (id) => inventoryAPI.toEnquiry(id),
    onSuccess: (res) => {
      toast.success(res.data.message, { duration: 7000 });
      qc.invalidateQueries({ queryKey: ["inv", "inbox"] });
      qc.invalidateQueries({ queryKey: ["inv", "vessels"] });

      const rfqId = res.data.data?.rfq?.id;
      if (rfqId) navigate(`/enquiries/${rfqId}?send=1`);
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not send it to the vendors."),
  });

  const download = async (req) => {
    setDownloading(req.id);
    try {
      const res = await inventoryAPI.requisitionPdf(req.id);
      downloadBlob(res.data, `${req.req_number}.pdf`);
    } catch {
      toast.error("Could not generate the PDF.");
    } finally {
      setDownloading(null);
    }
  };

  if (isLoading) return <PageLoader label="Loading the fleet's orders…" />;

  const rows = data?.requisitions ?? [];
  const counts = data?.counts ?? {};
  // Counted in lines, not dollars: nothing on this list has been priced yet —
  // sending it to procurement is how it gets priced.
  const lines = rows.reduce((n, r) => n + Number(r.items_count || 0), 0);

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-bold text-[#28364b]">Requisitions</h1>
        <p className="text-sm text-slate-500">
          What the fleet has asked for. Each one has been written on board and approved by her master.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard
          label="To buy"
          value={counts.approved ?? 0}
          hint={
            status === "approved"
              ? `${lines} line${lines === 1 ? "" : "s"} to price up`
              : "Approved, not yet ordered"
          }
          tone={counts.approved > 0 ? "navy" : "default"}
          icon={ClipboardList}
        />
        <StatCard label="Ordered" value={counts.issued ?? 0} hint="Out with vendors" icon={Wallet} />
        <StatCard label="Closed" value={counts.closed ?? 0} hint="Delivered and done" />
      </div>

      <div className="flex gap-1 border-b border-slate-200">
        {TABS.map((tab) => (
          <button
            key={tab.value}
            onClick={() => setStatus(tab.value)}
            title={tab.hint}
            className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors ${
              status === tab.value
                ? "border-[#28364b] text-[#28364b]"
                : "border-transparent text-slate-400 hover:text-slate-600"
            }`}
          >
            {tab.label}
            {(counts[tab.value] ?? 0) > 0 && (
              <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">
                {counts[tab.value]}
              </span>
            )}
          </button>
        ))}
      </div>

      {rows.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
          <ClipboardList className="mx-auto mb-3 h-10 w-10 text-slate-200" />
          <p className="text-sm font-semibold text-[#28364b]">
            {status === "approved" ? "Nothing waiting to be bought" : "Nothing here"}
          </p>
          <p className="mx-auto mt-1 max-w-md text-sm text-slate-400">
            {status === "approved"
              ? "An order appears here the moment a master approves it. Until then it is still on board being written."
              : "Orders move here once the office has sent them out."}
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[900px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-2.5 font-semibold">Vessel</th>
                  <th className="px-2 py-2.5 font-semibold">Number</th>
                  <th className="px-2 py-2.5 font-semibold">Approved</th>
                  <th className="px-2 py-2.5 text-right font-semibold">Days</th>
                  <th className="px-2 py-2.5 text-right font-semibold">Lines</th>
                  <th
                    className="px-2 py-2.5 text-right font-semibold"
                    title="What this vessel has to spend over the days the order covers — what the master approved against"
                  >
                    Budget
                  </th>
                  <th className="px-4 py-2.5" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((r) => {
                  // The budget frozen when the master approved, not today's
                  // figure — that is the number he took responsibility for.
                  //
                  // Shown on its own, with nothing to compare it against: what
                  // this order will actually cost is not known until the
                  // vendors answer, so there is no "over budget" to flag here.
                  // That belongs on the offer, where the prices are real.
                  const budget = Number(r.budget_at_issue || 0);
                  const months = budget > 0 ? Math.max(1, r.days_of_supply / 30) : 1;
                  const allowance = budget * months;

                  return (
                    <Fragment key={r.id}>
                    <tr>
                      <td className="px-4 py-2.5">
                        <a
                          href={`/inventory/v/${r.vessel?.id}`}
                          className="inline-flex items-center gap-1.5 font-semibold text-[#28364b] hover:underline"
                        >
                          <Ship className="h-3.5 w-3.5 text-[#cebd88]" />
                          {r.vessel?.name}
                        </a>
                      </td>
                      <td className="whitespace-nowrap px-2 py-2.5">
                        {/* Read it before it goes to a vendor. The number opens
                            the lines the ship actually asked for. */}
                        <button
                          onClick={() => setOpenOrder((o) => (o === r.id ? null : r.id))}
                          className="inline-flex items-center gap-1 font-medium text-slate-600 hover:text-[#28364b] hover:underline"
                          title={openOrder === r.id ? "Hide the items" : "See the items on this order"}
                        >
                          <ChevronRight
                            className={`h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform ${openOrder === r.id ? "rotate-90" : ""}`}
                          />
                          {r.req_number}
                        </button>
                      </td>
                      <td className="whitespace-nowrap px-2 py-2.5 text-slate-500">
                        {dateOf(r.approved_at)}
                        {r.approved_by && <span className="block text-[11px] text-slate-400">by {r.approved_by}</span>}
                      </td>
                      <td className="px-2 py-2.5 text-right text-slate-500">{r.days_of_supply}</td>
                      <td className="px-2 py-2.5 text-right text-slate-500">{r.items_count}</td>
                      <td className="whitespace-nowrap px-2 py-2.5 text-right">
                        {budget > 0 ? (
                          <span className="text-slate-500">${money(allowance)}</span>
                        ) : (
                          <span className="text-slate-300">not set</span>
                        )}
                      </td>
                      <td className="px-4 py-2.5">
                        <div className="flex items-center justify-end gap-1">
                          {/* Either it is waiting to be bought, or it is
                              already an enquiry and the link goes there. */}
                          {r.rfq ? (
                            // Stays on these pages: the enquiry screen is
                            // mounted here too, showing the fleet's own.
                            <Link
                              href={`/enquiries/${r.rfq.id}`}
                              className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-[#28364b] transition-colors hover:bg-slate-50"
                              title="Open the enquiry"
                            >
                              <FileSearch className="h-3.5 w-3.5" /> {r.rfq.reference}
                            </Link>
                          ) : (
                            <button
                              onClick={() => send.mutate(r.id)}
                              disabled={send.isLoading}
                              className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-[#28364b] px-2.5 py-1.5 text-[11px] font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-50"
                              title="Pick vendors and ask them for prices"
                            >
                              {send.isLoading && send.variables === r.id ? (
                                <Spinner className="h-3.5 w-3.5" />
                              ) : (
                                <SendHorizontal className="h-3.5 w-3.5" />
                              )}
                              Send to vendors
                            </button>
                          )}
                          <button
                            onClick={() => download(r)}
                            className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                            title="Download the order"
                          >
                            {downloading === r.id ? <Spinner className="h-4 w-4" /> : <Download className="h-4 w-4" />}
                          </button>
                          <a
                            href={`/inventory/v/${r.vessel?.id}/requisition`}
                            className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                            title="Open it on the vessel"
                          >
                            <ExternalLink className="h-4 w-4" />
                          </a>
                        </div>
                      </td>
                    </tr>
                    {openOrder === r.id && (
                      <tr className="bg-slate-50/60">
                        <td colSpan={7} className="px-4 py-3">
                          {/* No vessel is named: this screen is not inside one,
                              and the endpoint takes the vessel off the
                              requisition rather than off the request. */}
                          <OrderLines id={r.id} />
                        </td>
                      </tr>
                    )}
                    </Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {status === "approved" && rows.length > 0 && (
        <div className="flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-[#cebd88]" />
          <p>
            <strong>Send to vendors</strong> turns an order into an ordinary enquiry and opens it, so you can pick who
            to ask — email them a quote link, or add a vendor you contacted elsewhere and key their prices in later.
            From there it is the same Compare &amp; Award, the same purchase order numbering and the same books as
            every other job. It stays on these pages: the <em>Buying</em> links on the left are the whole flow, showing
            the fleet's own documents only.
          </p>
        </div>
      )}

      {rows.some((r) => !r.budget_at_issue) && (
        <div className="flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-[#cebd88]" />
          <p>
            Some of these ships have no monthly budget set, so there was nothing to warn their master about. Set one on
            the vessel's Settings and the warning starts working on her next order.
          </p>
        </div>
      )}
    </div>
  );
}
