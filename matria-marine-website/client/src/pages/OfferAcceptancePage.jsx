import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Loader2, CheckCircle } from "lucide-react";
import { offerAcceptanceAPI } from "@/pages/api";

const money = (n) => Number(n || 0).toFixed(2);

function Shell({ children }) {
  return (
    <div className="min-h-screen bg-slate-50 py-10">
      <div className="mx-auto max-w-3xl px-4">
        <div className="mb-6 flex items-center gap-3">
          <img src="/logo.png" alt="Matria Marine" className="h-11 w-11 shrink-0 object-contain" />
          <div>
            <h1 className="text-xl font-bold text-[#28364b]">Matria Marine — Quotation</h1>
            <p className="text-sm text-slate-500">Review your quotation and confirm your order. No login required.</p>
          </div>
        </div>
        {children}
      </div>
    </div>
  );
}

function OfferCard({ data }) {
  return (
    <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-lg font-bold text-[#28364b]">{data.offer_number}</h2>
          <p className="text-sm text-slate-500">{data.company?.name}</p>
        </div>
        <span className="text-right text-xs text-slate-500">
          {data.valid_until && <div>Valid until <span className="font-medium text-[#28364b]">{data.valid_until}</span></div>}
          <div>Currency <span className="font-medium text-[#28364b]">{data.currency}</span></div>
        </span>
      </div>

      <div className="grid gap-3 text-sm sm:grid-cols-2">
        <div><span className="text-slate-400">To:</span> <span className="font-medium text-[#28364b]">{data.customer_name || "—"}</span></div>
        {data.vessel && <div><span className="text-slate-400">Vessel:</span> <span className="font-medium text-[#28364b]">{data.vessel}</span></div>}
        {data.customer_reference && <div><span className="text-slate-400">Your ref:</span> <span className="font-medium text-[#28364b]">{data.customer_reference}</span></div>}
        {data.payment_terms && <div><span className="text-slate-400">Payment:</span> <span className="font-medium text-[#28364b]">{data.payment_terms}</span></div>}
        {data.delivery_terms && <div><span className="text-slate-400">Delivery:</span> <span className="font-medium text-[#28364b]">{data.delivery_terms}</span></div>}
        {data.origin_type && <div><span className="text-slate-400">Origin:</span> <span className="font-medium text-[#28364b]">{data.origin_type}</span></div>}
      </div>

      <div className="overflow-x-auto rounded-lg border border-slate-200">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-3 py-2 font-semibold">Item</th>
              <th className="px-3 py-2 text-right font-semibold">Qty</th>
              <th className="px-3 py-2 text-right font-semibold">Unit price</th>
              <th className="px-3 py-2 text-right font-semibold">Amount</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map((it, idx) =>
              it.is_heading ? (
                <tr key={idx} className="border-b border-slate-100 last:border-0">
                  <td colSpan={4} className="px-3 py-2 font-semibold text-[#28364b]">{it.description}</td>
                </tr>
              ) : (
                <tr key={idx} className="border-b border-slate-100 last:border-0">
                  <td className="px-3 py-2 text-slate-700">{it.description}{it.unit ? <span className="text-slate-400"> ({it.unit})</span> : null}</td>
                  <td className="px-3 py-2 text-right text-slate-600">{Number(it.qty)}</td>
                  <td className="px-3 py-2 text-right text-slate-600">{money(it.unit_price)}</td>
                  <td className="px-3 py-2 text-right font-medium text-[#28364b]">{money(it.line_total)}</td>
                </tr>
              )
            )}
          </tbody>
          <tfoot>
            {(Number(data.packing_cost) > 0 || Number(data.transportation_cost) > 0 || Number(data.tax_amount) > 0) && (
              <>
                <tr className="border-t border-slate-200">
                  <td colSpan={3} className="px-3 py-1.5 text-right text-sm text-slate-500">Subtotal</td>
                  <td className="px-3 py-1.5 text-right text-sm text-slate-600">{money(data.subtotal)}</td>
                </tr>
                {Number(data.packing_cost) > 0 && (
                  <tr>
                    <td colSpan={3} className="px-3 py-1.5 text-right text-sm text-slate-500">Packing cost</td>
                    <td className="px-3 py-1.5 text-right text-sm text-slate-600">{money(data.packing_cost)}</td>
                  </tr>
                )}
                {Number(data.transportation_cost) > 0 && (
                  <tr>
                    <td colSpan={3} className="px-3 py-1.5 text-right text-sm text-slate-500">Transportation cost</td>
                    <td className="px-3 py-1.5 text-right text-sm text-slate-600">{money(data.transportation_cost)}</td>
                  </tr>
                )}
                {Number(data.tax_amount) > 0 && (
                  <tr>
                    <td colSpan={3} className="px-3 py-1.5 text-right text-sm text-slate-500">GST {Number(data.tax_rate)}%</td>
                    <td className="px-3 py-1.5 text-right text-sm text-slate-600">{money(data.tax_amount)}</td>
                  </tr>
                )}
              </>
            )}
            <tr className="border-t border-slate-200">
              <td colSpan={3} className="px-3 py-2.5 text-right text-sm font-semibold text-slate-500">Total ({data.currency})</td>
              <td className="px-3 py-2.5 text-right text-base font-bold text-[#28364b]">{money(data.grand_total ?? data.subtotal)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );
}

/**
 * Public customer offer (quotation) acceptance page (magic link, NO login).
 * The {token} is validated server-side; vendor names and base prices are never
 * exposed — only the customer-facing prices.
 */
export default function OfferAcceptancePage({ token }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ["offer-accept", token],
    queryFn: async () => (await offerAcceptanceAPI.get(token)).data.data,
    enabled: !!token,
    retry: false,
  });

  const [name, setName] = useState("");
  const [note, setNote] = useState("");
  const [done, setDone] = useState(false);

  const accept = useMutation({
    mutationFn: () => offerAcceptanceAPI.accept(token, { name: name || null, note: note || null }),
    onSuccess: () => setDone(true),
    onError: () => {},
  });

  if (isLoading) {
    return <Shell><div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Loading your quotation…</div></Shell>;
  }

  if (isError || !data) {
    return (
      <Shell>
        <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-red-600">
          This quotation link is invalid or has been withdrawn. Please contact Matria Marine.
        </div>
      </Shell>
    );
  }

  const accepted = done || !!data.accepted_at;

  if (accepted) {
    const who = (done ? name : data.accepted_by_name) || data.customer_name;
    return (
      <Shell>
        <div className="mb-4 flex flex-col items-center gap-2 rounded-xl border border-green-200 bg-green-50 p-8 text-center">
          <CheckCircle className="h-12 w-12 text-green-600" />
          <h2 className="text-lg font-bold text-[#28364b]">Order confirmed</h2>
          <p className="text-sm text-slate-600">
            Thank you{who ? `, ${who}` : ""} — Matria Marine has recorded your acceptance of <span className="font-semibold">{data.offer_number}</span> and will arrange delivery.
          </p>
          {!done && data.acceptance_note && (
            <p className="mt-1 text-xs italic text-slate-500">“{data.acceptance_note}”</p>
          )}
        </div>
        <OfferCard data={data} />
      </Shell>
    );
  }

  return (
    <Shell>
      <OfferCard data={data} />

      <div className="mt-4 space-y-3 rounded-xl border border-slate-200 bg-white p-6">
        <h3 className="text-sm font-bold uppercase tracking-wider text-[#28364b]">Accept this quotation</h3>
        <p className="text-xs text-slate-500">By accepting, you confirm your order at the prices above.</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <label className="text-xs font-medium text-slate-500">Your name (optional)</label>
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Capt. Reyes"
              className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"
            />
          </div>
        </div>
        <div>
          <label className="text-xs font-medium text-slate-500">Note to Matria (optional)</label>
          <textarea
            rows={2}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="e.g. Please deliver before the vessel sails on the 12th."
            className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"
          />
        </div>
        {accept.isError && <p className="text-xs text-red-600">Something went wrong. Please try again.</p>}
        <div className="flex justify-end">
          <button
            onClick={() => accept.mutate()}
            disabled={accept.isLoading}
            className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70"
          >
            {accept.isLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle className="h-4 w-4" />}
            Accept &amp; place order
          </button>
        </div>
      </div>
    </Shell>
  );
}
