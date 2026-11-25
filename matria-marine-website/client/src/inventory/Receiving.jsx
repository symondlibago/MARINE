import { useEffect, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useLocation, useSearch } from "wouter";
import { PackageCheck, Plus, Trash2, ArrowRight, Truck } from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import { dateOf, btn } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";

/**
 * Deliveries list. A delivery is created as a draft and only raises stock when
 * it is confirmed on its own page.
 */
export default function Receiving() {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const [, navigate] = useLocation();
  const searchString = useSearch();
  const { office } = useAccess();

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "receipts"],
    queryFn: async () => (await inventoryAPI.receipts()).data.data,
  });

  const create = useMutation({
    mutationFn: (payload) => inventoryAPI.createReceipt(payload),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ["inv", "receipts"] });
      navigate(`/receiving/${res.data.data.receipt.id}`);
    },
    onError: () => toast.error("Could not start a delivery."),
  });

  // Arriving from a saved requisition pre-fills the sheet with what was ordered.
  const [prefilling, setPrefilling] = useState(false);
  useEffect(() => {
    const params = new URLSearchParams(searchString);
    const reqId = params.get("requisition");
    if (!reqId || prefilling) return;

    setPrefilling(true);
    (async () => {
      try {
        const res = await inventoryAPI.receivingSheet(reqId);
        const { requisition, lines, quotation } = res.data.data;

        // Numbers already in the price boxes would otherwise look like somebody
        // else's typing. Say where they came from.
        if (quotation) {
          toast.success(`Prices taken from quotation ${quotation} — check them against the delivery note.`, {
            duration: 8000,
          });
        }

        create.mutate({
          requisition_id: requisition.id,
          supplier_id: requisition.supplier_id ?? null,
          reference: requisition.req_number,
          items: lines.map((l) => ({
            item_id: l.item_id,
            ordered_qty: l.ordered_qty,
            received_qty: l.ordered_qty,
            unit_price: l.unit_price,
          })),
        });
      } catch {
        toast.error("Could not load that order.");
        setPrefilling(false);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchString]);

  const remove = useMutation({
    mutationFn: (id) => inventoryAPI.removeReceipt(id),
    onSuccess: () => {
      toast.success("Delivery deleted.");
      qc.invalidateQueries({ queryKey: ["inv", "receipts"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not delete."),
  });

  const askRemove = async (r) => {
    const ok = await confirm({
      title: "Delete delivery",
      message: "Delete this draft delivery?",
      confirmText: "Delete",
      tone: "danger",
    });
    if (ok) remove.mutate(r.id);
  };

  if (isLoading || prefilling) return <PageLoader label={prefilling ? "Preparing the sheet…" : "Loading deliveries…"} />;

  const rows = data?.receipts ?? [];
  const expected = data?.expected ?? [];

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-[#28364b]">Receiving</h1>
          <p className="text-sm text-slate-500">
            Record what actually arrived. Confirming a delivery is the only thing that raises stock on board.
          </p>
        </div>
        <button onClick={() => create.mutate({ items: [] })} disabled={create.isLoading} className={btn.primary}>
          {create.isLoading ? <Spinner className="h-4 w-4" /> : <Plus className="h-4 w-4" />} New delivery
        </button>
      </div>

      {/* What the office has bought and the ship has not booked in yet.
          Without this nothing told a cook an order was coming: it left the
          galley, was approved, was bought, and the next he knew of it was a
          pallet on the quay. An order drops off this list the moment a
          delivery against it is confirmed. */}
      {expected.length > 0 && (
        <div className="overflow-hidden rounded-xl border border-[#cebd88] bg-[#cebd88]/5">
          <div className="flex flex-wrap items-center gap-2 border-b border-[#cebd88]/40 px-4 py-3">
            <Truck className="h-4 w-4 text-[#cebd88]" />
            <h2 className="text-sm font-bold text-[#28364b]">On its way</h2>
            <span className="rounded-full bg-[#28364b] px-2 py-0.5 text-[11px] font-bold text-white">
              {expected.length}
            </span>
            <p className="w-full text-xs text-slate-500 sm:w-auto sm:flex-1">
              Ordered ashore and not booked in yet. Start the sheet and it comes pre-filled with what was ordered.
            </p>
          </div>
          <ul className="divide-y divide-[#cebd88]/30">
            {expected.map((e) => (
              <li key={e.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-[#28364b]">{e.req_number}</p>
                  <p className="text-xs text-slate-500">
                    {e.items_count} {e.items_count === 1 ? "line" : "lines"} · ordered {dateOf(e.req_date)}
                    {e.required_by ? ` · wanted by ${dateOf(e.required_by)}` : ""}
                    {e.receipt_id ? " · counting started" : ""}
                  </p>
                </div>
                {e.receipt_id ? (
                  <Link
                    href={`/receiving/${e.receipt_id}`}
                    className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-[#28364b] px-3 py-1.5 text-xs font-semibold text-[#28364b] transition-colors hover:bg-white"
                  >
                    <PackageCheck className="h-3.5 w-3.5" /> Carry on counting
                  </Link>
                ) : (
                  <Link
                    href={`/receiving?requisition=${e.id}`}
                    className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-[#28364b] px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-[#3c4a63]"
                  >
                    <PackageCheck className="h-3.5 w-3.5" /> Start receiving
                  </Link>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">Reference</th>
                {office && <th className="px-2 py-2 font-semibold">Supplier</th>}
                <th className="px-2 py-2 font-semibold">Received</th>
                <th className="px-2 py-2 text-right font-semibold">Lines</th>
                <th className="px-2 py-2 font-semibold">Status</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="border-t border-slate-100 transition-colors hover:bg-slate-50">
                  <td className="px-4 py-2">
                    <Link href={`/receiving/${r.id}`} className="font-semibold text-[#28364b] hover:underline">
                      {r.reference || `Delivery #${r.id}`}
                    </Link>
                  </td>
                  {office && <td className="px-2 py-2 text-slate-500">{r.supplier?.name || "—"}</td>}
                  <td className="px-2 py-2 text-slate-500">{dateOf(r.received_date)}</td>
                  <td className="px-2 py-2 text-right text-slate-500">{r.items_count}</td>
                  <td className="px-2 py-2">
                    <span
                      className={`rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ${
                        r.status === "confirmed"
                          ? "bg-emerald-50 text-emerald-700 ring-emerald-200"
                          : "bg-amber-50 text-amber-700 ring-amber-200"
                      }`}
                    >
                      {r.status}
                    </span>
                  </td>
                  <td className="px-4 py-2">
                    <div className="flex items-center justify-end gap-1">
                      {r.status === "draft" && (
                        <button
                          onClick={() => askRemove(r)}
                          className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                          title="Delete draft"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      )}
                      <Link
                        href={`/receiving/${r.id}`}
                        className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                        title="Open"
                      >
                        <ArrowRight className="h-4 w-4" />
                      </Link>
                    </div>
                  </td>
                </tr>
              ))}
              {!rows.length && (
                <tr>
                  <td colSpan={office ? 6 : 5} className="px-4 py-16 text-center text-sm text-slate-400">
                    <PackageCheck className="mx-auto mb-2 h-8 w-8 text-slate-200" />
                    No deliveries recorded yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
