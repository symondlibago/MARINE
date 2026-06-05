import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Anchor, Loader2, CheckCircle } from "lucide-react";
import { quoteAPI } from "@/pages/api";
import Select from "@/portal/ui/Select";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

// Defined at module scope so it keeps a stable identity across renders
// (defining it inside QuotePage would remount the inputs on every keystroke).
function Shell({ children }) {
  return (
    <div className="min-h-screen bg-slate-50 py-10">
      <div className="mx-auto max-w-3xl px-4">
        <div className="mb-6 flex items-center gap-3">
          <div className="rounded-full bg-[#28364b] p-2 text-white"><Anchor className="h-5 w-5" /></div>
          <div>
            <h1 className="text-xl font-bold text-[#28364b]">Matria Marine — Vendor Quotation</h1>
            <p className="text-sm text-slate-500">Submit your prices for the requested items. No login required.</p>
          </div>
        </div>
        {children}
      </div>
    </div>
  );
}

/**
 * Public vendor quotation page (magic link, NO login). The {token} is validated
 * server-side; the vendor sees only their items for this enquiry.
 */
export default function QuotePage({ token }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ["quote", token],
    queryFn: async () => (await quoteAPI.get(token)).data.data,
    enabled: !!token,
    retry: false,
  });

  const [currency, setCurrency] = useState("USD");
  const [lines, setLines] = useState({}); // rfq_item_id -> { unit_cost, remarks }
  const [done, setDone] = useState(false);

  useEffect(() => {
    if (!data) return;
    setCurrency(data.quote?.currency || data.vendor?.currency || "USD");
    const init = {};
    (data.quote?.items || []).forEach((qi) => {
      init[qi.rfq_item_id] = { unit_cost: qi.unit_cost, remarks: qi.remarks || "" };
    });
    setLines(init);
  }, [data]);

  const submit = useMutation({
    mutationFn: () => {
      const payload = {
        currency,
        lines: data.items.map((it) => {
          const v = lines[it.rfq_item_id] || {};
          const cost = v.unit_cost === undefined || v.unit_cost === "" ? null : Number(v.unit_cost);
          return { rfq_item_id: it.rfq_item_id, unit_cost: cost, remarks: v.remarks || null };
        }),
      };
      return quoteAPI.submit(token, payload);
    },
    onSuccess: () => setDone(true),
    onError: () => {},
  });

  const setLine = (id, k, v) => setLines((l) => ({ ...l, [id]: { ...l[id], [k]: v } }));

  if (isLoading) {
    return <Shell><div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Loading your request…</div></Shell>;
  }

  if (isError || !data) {
    return (
      <Shell>
        <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-red-600">
          This quotation link is invalid or has expired. Please contact Matria Marine.
        </div>
      </Shell>
    );
  }

  if (done) {
    return (
      <Shell>
        <div className="flex flex-col items-center gap-2 rounded-xl border border-slate-200 bg-white p-10 text-center">
          <CheckCircle className="h-12 w-12 text-green-600" />
          <h2 className="text-lg font-bold text-[#28364b]">Quotation submitted</h2>
          <p className="text-sm text-slate-500">Thank you — Matria Marine has received your prices. You may close this page.</p>
        </div>
      </Shell>
    );
  }

  return (
    <Shell>
      <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <div className="grid gap-3 text-sm sm:grid-cols-3">
          <div><span className="text-slate-400">Vendor:</span> <span className="font-medium text-[#28364b]">{data.vendor?.name}</span></div>
          <div><span className="text-slate-400">Reference:</span> <span className="font-medium text-[#28364b]">{data.rfq?.reference}</span></div>
          <div><span className="text-slate-400">Vessel:</span> <span className="font-medium text-[#28364b]">{data.rfq?.ship_name || "—"}</span></div>
        </div>

        {data.submitted && (
          <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
            You have already submitted a quotation. Submitting again will update your prices.
          </div>
        )}

        <div className="flex items-center gap-2">
          <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">Currency</label>
          <div className="w-32"><Select value={currency} onChange={setCurrency} options={CURRENCIES} /></div>
        </div>

        <div className="overflow-x-auto rounded-lg border border-slate-200">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="px-3 py-2 font-semibold">Item</th>
                <th className="px-3 py-2 text-right font-semibold">Qty</th>
                <th className="px-3 py-2 font-semibold">Unit price</th>
                <th className="px-3 py-2 font-semibold">Remarks</th>
              </tr>
            </thead>
            <tbody>
              {data.items.map((it) => (
                <tr key={it.rfq_item_id} className="border-b border-slate-100 last:border-0">
                  <td className="px-3 py-2 text-slate-700">{it.description}</td>
                  <td className="px-3 py-2 text-right text-slate-500">{it.qty} {it.unit}</td>
                  <td className="px-3 py-2">
                    <input
                      type="number"
                      step="0.0001"
                      min="0"
                      placeholder="—"
                      value={lines[it.rfq_item_id]?.unit_cost ?? ""}
                      onChange={(e) => setLine(it.rfq_item_id, "unit_cost", e.target.value)}
                      className="w-28 rounded border border-slate-200 px-2 py-1 text-sm"
                    />
                  </td>
                  <td className="px-3 py-2">
                    <input
                      value={lines[it.rfq_item_id]?.remarks ?? ""}
                      onChange={(e) => setLine(it.rfq_item_id, "remarks", e.target.value)}
                      className="w-full rounded border border-slate-200 px-2 py-1 text-sm"
                      placeholder="optional"
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <p className="text-xs text-slate-400">Leave a price blank if you cannot supply that item.</p>

        <div className="flex justify-end">
          <button
            onClick={() => submit.mutate()}
            disabled={submit.isLoading}
            className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-70"
          >
            {submit.isLoading && <Loader2 className="h-4 w-4 animate-spin" />}
            Submit quotation
          </button>
        </div>
      </div>
    </Shell>
  );
}
