import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Anchor, Loader2, CheckCircle } from "lucide-react";
import { poAcceptanceAPI } from "@/pages/api";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  issued: "bg-blue-100 text-blue-700",
  received: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};

// Defined at module scope so they keep a stable identity across renders
// (defining them inside the page would remount the inputs on every keystroke).
function Shell({ children }) {
  return (
    <div className="min-h-screen bg-slate-50 py-10">
      <div className="mx-auto max-w-3xl px-4">
        <div className="mb-6 flex items-center gap-3">
          <div className="rounded-full bg-[#28364b] p-2 text-white"><Anchor className="h-5 w-5" /></div>
          <div>
            <h1 className="text-xl font-bold text-[#28364b]">Matria Marine — Purchase Order</h1>
            <p className="text-sm text-slate-500">Review your order and confirm acceptance. No login required.</p>
          </div>
        </div>
        {children}
      </div>
    </div>
  );
}

const money = (n) => Number(n || 0).toFixed(2);

function PoCard({ data }) {
  return (
    <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg font-bold text-[#28364b]">{data.po_number}</h2>
        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[data.status] || "bg-slate-100"}`}>{data.status}</span>
      </div>

      <div className="grid gap-3 text-sm sm:grid-cols-2">
        <div><span className="text-slate-400">Supplier:</span> <span className="font-medium text-[#28364b]">{data.supplier}</span></div>
        <div><span className="text-slate-400">Currency:</span> <span className="font-medium text-[#28364b]">{data.currency}</span></div>
        <div><span className="text-slate-400">Vessel:</span> <span className="font-medium text-[#28364b]">{data.ship_name || "—"}</span></div>
        <div><span className="text-slate-400">Delivery port:</span> <span className="font-medium text-[#28364b]">{data.delivery_port || "—"}</span></div>
        <div><span className="text-slate-400">Issued:</span> <span className="font-medium text-[#28364b]">{data.issued_date || "—"}</span></div>
        <div><span className="text-slate-400">Expected delivery:</span> <span className="font-medium text-[#28364b]">{data.expected_date || "—"}</span></div>
      </div>

      <div className="overflow-x-auto rounded-lg border border-slate-200">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-3 py-2 font-semibold">Item</th>
              <th className="px-3 py-2 text-right font-semibold">Qty</th>
              <th className="px-3 py-2 text-right font-semibold">Unit cost</th>
              <th className="px-3 py-2 text-right font-semibold">Line total</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map((it, idx) => (
              <tr key={idx} className="border-b border-slate-100 last:border-0">
                <td className="px-3 py-2 text-slate-700">{it.description}{it.unit ? <span className="text-slate-400"> ({it.unit})</span> : null}</td>
                <td className="px-3 py-2 text-right text-slate-600">{Number(it.qty)}</td>
                <td className="px-3 py-2 text-right text-slate-600">{money(it.unit_cost)}</td>
                <td className="px-3 py-2 text-right font-medium text-[#28364b]">{money(it.line_total)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t border-slate-200">
              <td colSpan={3} className="px-3 py-2.5 text-right text-sm font-semibold text-slate-500">Total ({data.currency})</td>
              <td className="px-3 py-2.5 text-right text-base font-bold text-[#28364b]">{money(data.subtotal)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      {data.notes && (
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold">Notes:</span> {data.notes}</p>
      )}
    </div>
  );
}

/**
 * Public vendor purchase-order acceptance page (magic link, NO login). The
 * {token} is validated server-side; the vendor sees only this one order.
 */
export default function PoAcceptancePage({ token }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ["po-accept", token],
    queryFn: async () => (await poAcceptanceAPI.get(token)).data.data,
    enabled: !!token,
    retry: false,
  });

  const [name, setName] = useState("");
  const [note, setNote] = useState("");
  const [done, setDone] = useState(false);

  const accept = useMutation({
    mutationFn: () => poAcceptanceAPI.accept(token, { name: name || null, note: note || null }),
    onSuccess: () => setDone(true),
    onError: () => {},
  });

  if (isLoading) {
    return <Shell><div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Loading your purchase order…</div></Shell>;
  }

  if (isError || !data) {
    return (
      <Shell>
        <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-red-600">
          This purchase order link is invalid or has been cancelled. Please contact Matria Marine.
        </div>
      </Shell>
    );
  }

  const accepted = done || !!data.accepted_at;

  if (accepted) {
    const who = (done ? name : data.accepted_by_name) || data.supplier;
    return (
      <Shell>
        <div className="mb-4 flex flex-col items-center gap-2 rounded-xl border border-green-200 bg-green-50 p-8 text-center">
          <CheckCircle className="h-12 w-12 text-green-600" />
          <h2 className="text-lg font-bold text-[#28364b]">Order accepted</h2>
          <p className="text-sm text-slate-600">
            Thank you{who ? `, ${who}` : ""} — Matria Marine has recorded your acceptance of <span className="font-semibold">{data.po_number}</span>.
          </p>
          {!done && data.acceptance_note && (
            <p className="mt-1 text-xs italic text-slate-500">“{data.acceptance_note}”</p>
          )}
        </div>
        <PoCard data={data} />
      </Shell>
    );
  }

  return (
    <Shell>
      <PoCard data={data} />

      <div className="mt-4 space-y-3 rounded-xl border border-slate-200 bg-white p-6">
        <h3 className="text-sm font-bold uppercase tracking-wider text-[#28364b]">Confirm acceptance</h3>
        <p className="text-xs text-slate-500">By accepting, you confirm you can supply the items above at the listed prices.</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <label className="text-xs font-medium text-slate-500">Your name (optional)</label>
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Juan Dela Cruz"
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
            placeholder="e.g. We confirm delivery by 12 June."
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
            Accept order
          </button>
        </div>
      </div>
    </Shell>
  );
}
