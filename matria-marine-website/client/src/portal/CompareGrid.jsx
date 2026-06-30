import { useState, useEffect, useRef } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Link, useLocation } from "wouter";
import { motion } from "framer-motion";
import { ArrowLeft, Lock, Unlock, FileDown, Download, ShoppingCart, Percent, RefreshCw, Paperclip } from "lucide-react";
import { toast } from "sonner";
import { rfqsAPI, purchaseOrdersAPI, offersAPI } from "@/pages/api";
import { fetchRates, rateToBase } from "@/lib/fx";
import { Spinner, PageLoader } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

export default function CompareGrid({ params }) {
  const id = params.id;
  const confirm = useConfirm();
  const [, setLocation] = useLocation();
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
    onError: (e) =>
      toast.error(e?.response?.data?.message || `Couldn't save the rate (${e?.response?.status || "network error"}).`),
  });

  const pricesMutation = useMutation({
    mutationFn: ({ quoteId, line }) => rfqsAPI.saveVendorPrices(quoteId, [line]),
    onSuccess: () => refetch(),
    onError: () => toast.error("Could not save."),
  });

  const currencyMutation = useMutation({
    mutationFn: ({ quoteId, currency }) => rfqsAPI.updateQuoteCurrency(quoteId, currency),
    onSuccess: () => refetch(),
  });

  const quoteNumberMutation = useMutation({
    mutationFn: ({ quoteId, quotation_number }) => rfqsAPI.updateQuoteNumber(quoteId, quotation_number),
    onSuccess: () => refetch(),
    onError: () => toast.error("Could not save the quotation number."),
  });

  // Auto-fill today's live exchange rate for any vendor quoting in a currency
  // other than the enquiry currency whose rate hasn't been set yet (still 1).
  // Fetched in the browser; staff can still override the box afterwards.
  const autoFilled = useRef(new Set());
  useEffect(() => {
    if (!data || data.rfq.status === "closed") return;
    const base = data.rfq.base_currency;
    const need = (data.vendors || []).filter(
      (v) =>
        v.currency &&
        v.currency !== base &&
        Number(v.exchange_rate) === 1 &&
        !autoFilled.current.has(v.quote_id)
    );
    if (need.length === 0) return;

    (async () => {
      let rates;
      try {
        rates = await fetchRates(base);
      } catch {
        return; // offline / blocked — leave the boxes for manual entry
      }
      for (const v of need) {
        autoFilled.current.add(v.quote_id);
        const rate = rateToBase(rates, v.currency);
        if (rate) rateMutation.mutate({ quoteId: v.quote_id, rate: Number(rate.toFixed(6)) });
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data]);

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

  const makeOffer = useMutation({
    mutationFn: () => offersAPI.generate(id),
    onSuccess: (res) => { toast.success(res.data.message || "Offer ready."); setLocation(`/offers/${res.data.data.id}`); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not create offer."),
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

  // Staff key in / change a vendor's unit price for one line (esp. file-only vendors).
  // Staff key in a vendor's unit price + per-product remark together (esp. for
  // vendors who just email their quotation instead of using the link). The remark
  // prints below the item on the quotation/Offer, PO, DO, proforma and invoices.
  const saveCell = (vendor, rfqItemId, { unit_cost, remarks }) => {
    if (locked) return;
    pricesMutation.mutate({
      quoteId: vendor.quote_id,
      line: { rfq_item_id: rfqItemId, unit_cost, remarks },
    });
  };

  // Staff key in the vendor's own quotation/reference number (esp. when emailed).
  const saveQuoteNumber = (vendor, value) => {
    if (locked) return;
    const next = value.trim();
    if (next === (vendor.quotation_number ?? "").trim()) return;
    quoteNumberMutation.mutate({ quoteId: vendor.quote_id, quotation_number: next || null });
  };

  // Open a vendor's uploaded file via a short-lived signed URL.
  const openAttachment = async (quoteId, att) => {
    try {
      const res = await rfqsAPI.attachmentUrl(quoteId, att.id);
      window.open(res.data.data.url, "_blank", "noopener");
    } catch {
      toast.error("Could not open the file.");
    }
  };

  // Pull today's live rate for one vendor (manual ↻) and save it to their quote.
  const applyLiveRate = async (vendor) => {
    let rates;
    try {
      rates = await fetchRates(data.rfq.base_currency);
    } catch {
      toast.error("Could not fetch a live rate — enter it manually.");
      return;
    }
    const rate = rateToBase(rates, vendor.currency);
    if (!rate) {
      toast.error(`No live rate available for ${vendor.currency}.`);
      return;
    }
    try {
      autoFilled.current.add(vendor.quote_id);
      // mutateAsync so the success toast only fires once the save actually persists.
      await rateMutation.mutateAsync({ quoteId: vendor.quote_id, rate: Number(rate.toFixed(6)) });
      toast.success(`${vendor.currency}→${data.rfq.base_currency} rate saved: ${rate.toFixed(4)}.`);
    } catch {
      /* onError on the mutation already shows the reason */
    }
  };

  // Staff correct a vendor's quote currency (e.g. file-only vendor who left it on
  // the default) — then re-pull the live rate for the new currency.
  const changeCurrency = async (vendor, currency) => {
    if (locked || currency === vendor.currency) return;
    const base = data.rfq.base_currency;
    try {
      await currencyMutation.mutateAsync({ quoteId: vendor.quote_id, currency });
      autoFilled.current.add(vendor.quote_id);
      if (currency === base) {
        await rateMutation.mutateAsync({ quoteId: vendor.quote_id, rate: 1 });
      } else {
        try {
          const rates = await fetchRates(base);
          const rate = rateToBase(rates, currency);
          if (rate) await rateMutation.mutateAsync({ quoteId: vendor.quote_id, rate: Number(rate.toFixed(6)) });
        } catch {
          /* couldn't fetch — leave the rate for manual entry */
        }
      }
      toast.success(`Currency set to ${currency}.`);
    } catch {
      toast.error("Could not change the currency.");
    }
  };

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
            Base {data.rfq.base_currency}. Type a vendor's unit price in its cell (e.g. for a vendor who only uploaded a file 📎); prices convert at today's live FX rate (edit or click ↻ to override). Add a remark under any priced cell — it prints below the item on the quotation, PO and invoices. Click a vendor's cell to award that line.
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
                    <div className="flex items-center gap-2">
                      <span className="font-semibold text-[#28364b]">{v.vendor_name}</span>
                      <span className={`inline-flex shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium normal-case ${v.complete ? "bg-green-100 text-green-700" : "bg-amber-100 text-amber-700"}`} title={v.complete ? "Priced every line" : "Some lines not priced"}>
                        {v.complete ? "Complete" : `Incomplete ${v.quoted_count}/${v.item_count}`}
                      </span>
                    </div>
                    <div className="mt-1">
                      <input
                        type="text"
                        key={`q-${v.quote_id}-${v.quotation_number ?? ""}`}
                        defaultValue={v.quotation_number ?? ""}
                        disabled={locked}
                        placeholder="Quotation no."
                        onBlur={(e) => saveQuoteNumber(v, e.target.value)}
                        className="w-full rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] font-normal normal-case text-slate-700 disabled:bg-slate-50"
                        title="Vendor's quotation / reference number — key it in if they emailed it"
                      />
                    </div>
                    <div className="mt-1 flex items-center gap-1 text-[10px] font-normal normal-case text-slate-400">
                      <select
                        value={v.currency}
                        disabled={locked}
                        onChange={(e) => changeCurrency(v, e.target.value)}
                        title="Vendor quote currency — change if the vendor used the wrong one"
                        className="rounded border border-slate-200 bg-white px-1 py-0.5 text-[10px] font-medium uppercase text-[#28364b] disabled:bg-slate-50"
                      >
                        {CURRENCIES.map((c) => (
                          <option key={c} value={c}>{c}</option>
                        ))}
                      </select>
                      ×
                      <input
                        type="number"
                        step="0.0001"
                        key={`${v.quote_id}-${v.exchange_rate}`}
                        defaultValue={v.exchange_rate}
                        disabled={locked}
                        onBlur={(e) => {
                          const rate = parseFloat(e.target.value);
                          if (!locked && rate > 0 && rate !== v.exchange_rate) rateMutation.mutate({ quoteId: v.quote_id, rate });
                        }}
                        className="w-16 rounded border border-slate-200 px-1 py-0.5 text-xs"
                        title="Exchange rate to base currency"
                      />
                      {v.currency !== data.rfq.base_currency && !locked && (
                        <button
                          type="button"
                          onClick={() => applyLiveRate(v)}
                          title={`Fetch today's live ${v.currency}→${data.rfq.base_currency} rate`}
                          className="text-slate-400 transition-colors hover:text-[#28364b]"
                        >
                          <RefreshCw className="h-3 w-3" />
                        </button>
                      )}
                    </div>
                    {v.attachments?.length > 0 && (
                      <div className="mt-1 flex flex-wrap gap-1">
                        {v.attachments.map((a) => (
                          <button
                            key={a.id}
                            type="button"
                            onClick={() => openAttachment(v.quote_id, a)}
                            title={`Open ${a.name}`}
                            className="inline-flex max-w-[150px] items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium normal-case text-slate-600 transition-colors hover:bg-slate-200"
                          >
                            <Paperclip className="h-3 w-3 shrink-0" />
                            <span className="truncate">{a.name}</span>
                          </button>
                        ))}
                      </div>
                    )}
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
                          title={cell?.quoted && !locked ? (isAwarded ? "Awarded to this vendor" : "Click to award this line") : undefined}
                          className={`px-3 py-3 align-top transition-colors ${cell?.quoted && !locked ? "cursor-pointer" : ""} ${
                            isAwarded ? "bg-[#28364b]" : isLowest ? "bg-green-50/60 hover:bg-green-100/70" : "hover:bg-slate-50"
                          }`}
                        >
                          <PriceRemarkCell
                            key={`cell-${v.quote_id}-${row.rfq_item_id}-${cell?.unit_cost ?? ""}-${cell?.remarks ?? ""}`}
                            cell={cell}
                            vendorCurrency={v.currency}
                            baseCurrency={data.rfq.base_currency}
                            isAwarded={isAwarded}
                            isLowest={isLowest}
                            locked={locked}
                            onSave={(payload) => saveCell(v, row.rfq_item_id, payload)}
                          />
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
            <tfoot>
              <tr className="border-t-2 border-slate-200 bg-slate-50">
                <td className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500" colSpan={2}>
                  Vendor total ({data.rfq.base_currency})
                </td>
                {data.vendors.map((v) => {
                  const lowestTotal = Math.min(...data.vendors.filter((x) => x.complete).map((x) => x.total_base));
                  const isCheapest = v.complete && v.total_base === lowestTotal && data.vendors.filter((x) => x.complete).length > 1;
                  return (
                    <td key={v.vendor_id} className={`px-3 py-3 font-bold ${isCheapest ? "text-green-700" : "text-[#28364b]"}`}>
                      {v.total_base.toFixed(2)}
                      {isCheapest && <span className="ml-1 text-[10px] font-medium">↓ lowest</span>}
                    </td>
                  );
                })}
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}

      {data.vendors.length > 0 && (
        <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#28364b]/20 bg-[#28364b]/[0.04] p-5">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-[#28364b]">Customer Offer</h2>
            <p className="text-xs text-slate-500">Add your markup to the vendor prices and send the customer a quotation.</p>
          </div>
          <button onClick={() => makeOffer.mutate()} disabled={makeOffer.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70">
            {makeOffer.isLoading ? <Spinner className="h-4 w-4" /> : <Percent className="h-4 w-4" />} Markup &amp; Offer
          </button>
        </motion.div>
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

// One compare-grid cell: the vendor's unit price with the per-product remark box
// right below it. Both are editable from the start (even before a price exists,
// e.g. a vendor who just emailed their quotation) and save together on blur.
function PriceRemarkCell({ cell, vendorCurrency, baseCurrency, isAwarded, isLowest, locked, onSave }) {
  const quoted = !!cell?.quoted;
  const [price, setPrice] = useState(quoted ? String(cell.unit_cost) : "");
  const [remark, setRemark] = useState(cell?.remarks ?? "");

  const commit = () => {
    if (locked) return;
    const nextPrice = price.trim() === "" ? null : Number(price);
    const nextRemark = remark.trim() || null;
    const curPrice = quoted ? Number(cell.unit_cost) : null;
    const curRemark = (cell?.remarks ?? "").trim() || null;
    if ((nextPrice ?? null) === (curPrice ?? null) && nextRemark === curRemark) return;
    // A remark must attach to a priced line. With no price yet, hold the remark
    // in the box and wait — it saves together once a price is typed.
    if (nextPrice === null && curPrice === null) return;
    onSave({ unit_cost: nextPrice, remarks: nextRemark });
  };

  return (
    <>
      <div className="flex items-center gap-1">
        <input
          type="number"
          step="0.0001"
          min="0"
          value={price}
          disabled={locked}
          placeholder="—"
          onClick={(e) => e.stopPropagation()}
          onChange={(e) => setPrice(e.target.value)}
          onBlur={commit}
          className="w-20 rounded border border-slate-200 bg-white px-1.5 py-1 text-sm text-slate-800 disabled:bg-slate-50"
          title="Vendor unit price — staff can enter or override"
        />
        <span className={`text-[10px] ${isAwarded ? "text-slate-300" : "text-slate-400"}`}>{vendorCurrency}</span>
      </div>
      {quoted ? (
        <div className={`mt-1 text-xs ${isAwarded ? "font-semibold text-white" : "text-slate-500"}`}>
          = {cell.base_cost.toFixed(2)} {baseCurrency}
          {isAwarded ? " ✓ awarded" : isLowest ? " ↓" : ""}
        </div>
      ) : (
        <div className="mt-1 text-[10px] text-slate-400">enter a price</div>
      )}
      <input
        type="text"
        value={remark}
        disabled={locked}
        placeholder="Remark…"
        onClick={(e) => e.stopPropagation()}
        onChange={(e) => setRemark(e.target.value)}
        onBlur={commit}
        className={`mt-1 w-full rounded border px-1.5 py-0.5 text-[11px] disabled:bg-slate-50 ${
          isAwarded ? "border-slate-500 bg-[#28364b] text-slate-100 placeholder:text-slate-400" : "border-slate-200 bg-white text-slate-600 placeholder:text-slate-300"
        }`}
        title="Remark for this product — prints below the item on the quotation, PO and invoices"
      />
    </>
  );
}
