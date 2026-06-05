import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Lock, Unlock, FileDown, Download, ShoppingCart } from "lucide-react";
import { toast } from "sonner";
import { rfqsAPI, purchaseOrdersAPI } from "@/pages/api";
import { Spinner, PageLoader } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";

export default function CompareGrid({ params }) {
  const id = params.id;
  const confirm = useConfirm();
  const [awards, setAwards] = useState({}); // rfq_item_id -> { vendor_id, quote_item_id, unit_cost, qty_to_buy }

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["compare", id],
    queryFn: async () => (await rfqsAPI.compare(id)).data.data,
  });

  useEffect(() => {
    if (!data) return;
    const init = {};
    data.rows.forEach((row) => {
      if (row.award) {
        const cell = row.cells.find((c) => c.vendor_id === row.award.vendor_id && c.quoted);
        init[row.rfq_item_id] = {
          vendor_id: row.award.vendor_id,
          quote_item_id: cell?.quote_item_id ?? null,
          unit_cost: row.award.unit_cost,
          qty_to_buy: row.award.qty_to_buy,
        };
      }
    });
    setAwards(init);
  }, [data]);

  const saveMutation = useMutation({
    mutationFn: () => {
      const arr = Object.entries(awards).map(([rfqItemId, v]) => ({
        rfq_item_id: Number(rfqItemId),
        vendor_id: v.vendor_id,
        quote_item_id: v.quote_item_id,
        qty_to_buy: Number(v.qty_to_buy) || 0,
        unit_cost: v.unit_cost,
      }));
      return rfqsAPI.saveAwards(id, arr);
    },
    onSuccess: () => { toast.success("Awards saved."); refetch(); },
    onError: () => toast.error("Could not save awards."),
  });

  const finishMutation = useMutation({
    mutationFn: () => rfqsAPI.finish(id),
    onSuccess: () => { toast.success("Enquiry locked."); refetch(); },
    onError: () => toast.error("Could not finish."),
  });

  const reopenMutation = useMutation({
    mutationFn: () => rfqsAPI.reopen(id),
    onSuccess: () => { toast.success("Enquiry reopened."); refetch(); },
    onError: () => toast.error("Could not reopen."),
  });

  const rateMutation = useMutation({
    mutationFn: ({ quoteId, rate }) => rfqsAPI.updateQuoteRate(quoteId, rate),
    onSuccess: () => refetch(),
  });

  const canOrder = data?.rfq?.status === "awarded" || data?.rfq?.status === "closed";

  const { data: pos, refetch: refetchPos } = useQuery({
    queryKey: ["rfq-pos", id],
    queryFn: async () => (await purchaseOrdersAPI.list({ rfq_id: id })).data.data,
    enabled: canOrder,
  });

  const generatePos = useMutation({
    mutationFn: () => purchaseOrdersAPI.generate(id),
    onSuccess: (res) => { toast.success(res.data.message || "Purchase orders generated."); refetchPos(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not generate purchase orders."),
  });

  const handleFinish = async () => {
    const ok = await confirm({
      title: "Lock this enquiry?",
      message: "Awards can't be changed after finishing.",
      confirmText: "Finish & lock",
    });
    if (ok) finishMutation.mutate();
  };

  const handleReopen = async () => {
    const ok = await confirm({
      title: "Reopen this enquiry?",
      message: "You'll be able to change awards and quantities again.",
      confirmText: "Reopen",
    });
    if (ok) reopenMutation.mutate();
  };

  const downloadSummary = async () => {
    try {
      const res = await rfqsAPI.summaryPdf(id);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `Quotation-${data.rfq.reference}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download PDF.");
    }
  };

  const downloadPdf = async (vendorId, vendorName) => {
    try {
      const res = await rfqsAPI.vendorAwardPdf(id, vendorId);
      const url = URL.createObjectURL(res.data);
      const a = document.createElement("a");
      a.href = url;
      a.download = `Award-${data.rfq.reference}-${vendorName}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download PDF.");
    }
  };

  if (isLoading) return <PageLoader />;
  if (!data) return null;

  const locked = data.rfq.status === "closed";
  const pickAward = (row, cell) => {
    if (locked || !cell?.quoted) return;
    setAwards((a) => ({
      ...a,
      [row.rfq_item_id]: {
        vendor_id: cell.vendor_id,
        quote_item_id: cell.quote_item_id,
        unit_cost: cell.unit_cost,
        qty_to_buy: a[row.rfq_item_id]?.qty_to_buy ?? row.qty,
      },
    }));
  };
  const setQty = (rfqItemId, qty) => setAwards((a) => ({ ...a, [rfqItemId]: { ...a[rfqItemId], qty_to_buy: qty } }));

  const awardedVendorIds = [...new Set(Object.values(awards).map((a) => a.vendor_id))];
  const awardedVendors = data.vendors.filter((v) => awardedVendorIds.includes(v.vendor_id));
  const showPdfs = (data.rfq.status === "awarded" || locked) && awardedVendors.length > 0;

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <Link href={`/enquiries/${id}`} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> Back to enquiry
      </Link>

      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Compare &amp; Award — {data.rfq.reference}</h1>
          <p className="text-sm text-slate-500">
            Base {data.rfq.base_currency}. Lowest converted price per row is highlighted; click a cell to award that line.
            {locked && " (Locked)"}
          </p>
        </div>
        <div className="flex gap-2">
          {!locked ? (
            <>
              <button onClick={() => saveMutation.mutate()} disabled={saveMutation.isLoading} className="inline-flex items-center gap-1 rounded-lg border border-[#28364b] px-4 py-2 text-sm font-semibold text-[#28364b] hover:bg-slate-50">
                {saveMutation.isLoading && <Spinner className="h-4 w-4" />} Save awards
              </button>
              <button onClick={handleFinish} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63]">
                <Lock className="h-4 w-4" /> Finish
              </button>
            </>
          ) : (
            <button onClick={handleReopen} className="inline-flex items-center gap-1 rounded-lg border border-[#28364b] px-4 py-2 text-sm font-semibold text-[#28364b] hover:bg-slate-50">
              <Unlock className="h-4 w-4" /> Reopen to edit
            </button>
          )}
        </div>
      </div>

      {data.vendors.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-400">No quotes submitted yet.</div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="px-3 py-3 font-semibold">Item</th>
                <th className="px-3 py-3 text-right font-semibold">Qty</th>
                {data.vendors.map((v) => (
                  <th key={v.vendor_id} className="px-3 py-3">
                    <div className="font-semibold text-[#28364b]">{v.vendor_name}</div>
                    <div className="mt-1 flex items-center gap-1 text-[10px] font-normal normal-case text-slate-400">
                      {v.currency} · ×
                      <input
                        type="number"
                        step="0.0001"
                        defaultValue={v.exchange_rate}
                        disabled={locked}
                        onBlur={(e) => {
                          const rate = parseFloat(e.target.value);
                          if (!locked && rate > 0 && rate !== v.exchange_rate) rateMutation.mutate({ quoteId: v.quote_id, rate });
                        }}
                        className="w-16 rounded border border-slate-200 px-1 py-0.5 text-xs"
                        title="Exchange rate to base currency"
                      />
                    </div>
                  </th>
                ))}
                <th className="px-3 py-3 text-right font-semibold">Buy qty</th>
              </tr>
            </thead>
            <tbody>
              {data.rows.map((row) => {
                const sel = awards[row.rfq_item_id];
                return (
                  <tr key={row.rfq_item_id} className="border-b border-slate-100 last:border-0">
                    <td className="px-3 py-3 text-slate-700">
                      {row.description}
                      {row.unit && <span className="text-slate-400"> ({row.unit})</span>}
                    </td>
                    <td className="px-3 py-3 text-right text-slate-500">{row.qty}</td>
                    {data.vendors.map((v) => {
                      const cell = row.cells.find((c) => c.vendor_id === v.vendor_id);
                      const isLowest = cell?.quoted && cell.base_cost === row.lowest_base_cost;
                      const isAwarded = sel?.vendor_id === v.vendor_id;
                      return (
                        <td
                          key={v.vendor_id}
                          onClick={() => pickAward(row, cell)}
                          className={`px-3 py-3 transition-colors ${cell?.quoted ? "cursor-pointer" : "text-slate-300"} ${
                            isAwarded ? "bg-[#28364b] text-white" : isLowest ? "bg-green-50" : ""
                          }`}
                        >
                          {cell?.quoted ? (
                            <div>
                              <div className="font-medium">
                                {cell.unit_cost.toFixed(2)} <span className={`text-xs ${isAwarded ? "text-slate-200" : "text-slate-400"}`}>{cell.currency}</span>
                              </div>
                              <div className={`text-xs ${isAwarded ? "text-slate-200" : "text-slate-400"}`}>
                                = {cell.base_cost.toFixed(2)} {data.rfq.base_currency}
                                {isLowest && !isAwarded && " ↓"}
                              </div>
                              {cell.remarks && <div className={`text-[10px] ${isAwarded ? "text-slate-300" : "text-slate-400"}`}>{cell.remarks}</div>}
                            </div>
                          ) : (
                            "—"
                          )}
                        </td>
                      );
                    })}
                    <td className="px-3 py-3 text-right">
                      <input
                        type="number"
                        step="0.001"
                        value={sel?.qty_to_buy ?? row.qty}
                        disabled={locked || !sel}
                        onChange={(e) => setQty(row.rfq_item_id, e.target.value)}
                        className="w-20 rounded border border-slate-200 px-2 py-1 text-right text-sm disabled:bg-slate-50"
                      />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {showPdfs && (
        <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Quotation PDFs</h2>
          <div className="flex flex-wrap items-center gap-2">
            <button onClick={downloadSummary} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63]">
              <Download className="h-4 w-4" /> Overall quotation
            </button>
            <span className="px-1 text-xs text-slate-400">per vendor:</span>
            {awardedVendors.map((v) => (
              <button key={v.vendor_id} onClick={() => downloadPdf(v.vendor_id, v.vendor_name)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm transition-colors hover:bg-slate-50">
                <FileDown className="h-4 w-4" /> {v.vendor_name}
              </button>
            ))}
          </div>
        </motion.div>
      )}

      {canOrder && (
        <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="rounded-xl border border-slate-200 bg-white p-5">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Purchase Orders</h2>
            <button onClick={() => generatePos.mutate()} disabled={generatePos.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
              {generatePos.isLoading ? <Spinner className="h-4 w-4" /> : <ShoppingCart className="h-4 w-4" />} Generate POs
            </button>
          </div>
          {pos && pos.length > 0 ? (
            <div className="space-y-1.5">
              {pos.map((po) => (
                <Link key={po.id} href={`/purchase-orders/${po.id}`} className="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm transition-colors hover:bg-slate-50">
                  <span className="font-medium text-[#28364b]">{po.po_number} · {po.vendor}</span>
                  <span className="flex items-center gap-2 text-xs text-slate-500">
                    {Number(po.subtotal).toFixed(2)} {po.currency}
                    <span className="rounded-full bg-slate-100 px-2 py-0.5">{po.status}</span>
                  </span>
                </Link>
              ))}
            </div>
          ) : (
            <p className="text-sm text-slate-400">Creates one draft purchase order per awarded vendor, ready to review and send.</p>
          )}
        </motion.div>
      )}
    </motion.div>
  );
}
