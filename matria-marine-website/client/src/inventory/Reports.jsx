import { useQuery } from "@tanstack/react-query";
import { BarChart3, Wallet, ClipboardList, PackageCheck } from "lucide-react";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import { StatCard, money, dateOf } from "./lib";
import { PageLoader } from "@/portal/ui/Loading";

/**
 * Month-by-month spend against the budget, and where the next order's money
 * goes by provision category.
 *
 * The cook reaches this screen too and is not shown money, so he reads the same
 * year in the terms he has: how many orders a month and how many lines were on
 * them, with the months that ran over the budget still marked. The shape of the
 * year survives; the figures behind it do not.
 */
export default function Reports() {
  const { money: canSeeMoney } = useAccess();
  const { data: monthly, isLoading } = useQuery({
    queryKey: ["inv", "monthly"],
    queryFn: async () => (await inventoryAPI.monthly()).data.data,
  });

  const { data: byCategory } = useQuery({
    queryKey: ["inv", "by-category"],
    queryFn: async () => (await inventoryAPI.byCategory()).data.data,
  });

  if (isLoading) return <PageLoader label="Building reports…" />;

  const months = monthly?.months ?? [];
  const totals = monthly?.totals ?? {};
  // The bar is drawn against whatever the reader is allowed to compare. For
  // the office that is money; for the cook it is how many lines a month ran to,
  // which gives the same shape of year without a figure on it.
  const bar = (m) => (canSeeMoney ? Number(m.order_value || 0) : Number(m.items || 0));
  const peak = Math.max(
    1,
    ...months.map((m) => Math.max(bar(m), canSeeMoney ? Number(m.budget || 0) : 0))
  );

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-[#28364b]">Reports</h1>
        <p className="text-sm text-slate-500">
          {dateOf(monthly?.from)} — {dateOf(monthly?.to)}
        </p>
      </div>

      <div className={`grid grid-cols-2 gap-3 ${canSeeMoney ? "lg:grid-cols-4" : "lg:grid-cols-3"}`}>
        <StatCard label="Orders raised" value={totals.requisitions ?? 0} icon={ClipboardList} />
        <StatCard label="Lines ordered" value={totals.items ?? 0} icon={BarChart3} />
        {canSeeMoney && (
          <StatCard label="Total ordered" value={`$${money(totals.order_value)}`} tone="navy" icon={Wallet} />
        )}
        <StatCard label="Deliveries received" value={monthly?.receipts ?? 0} icon={PackageCheck} />
      </div>

      {/* Monthly budget vs order value */}
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="border-b border-slate-100 px-4 py-3">
          <h2 className="text-sm font-bold text-[#28364b]">Budget vs ordered, by month</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">Month</th>
                <th className="px-2 py-2 text-right font-semibold">Orders</th>
                <th className="px-2 py-2 text-right font-semibold">Lines</th>
                {canSeeMoney && <th className="px-2 py-2 text-right font-semibold">Ordered</th>}
                {canSeeMoney && <th className="px-2 py-2 text-right font-semibold">Budget</th>}
                {canSeeMoney && <th className="px-4 py-2 text-right font-semibold">Balance</th>}
                {!canSeeMoney && <th className="px-4 py-2 text-right font-semibold">Budget</th>}
              </tr>
            </thead>
            <tbody>
              {months.map((m) => (
                <tr key={m.month} className="border-t border-slate-100">
                  <td className="px-4 py-2">
                    <div className="font-medium text-[#28364b]">{m.label}</div>
                    {/* A bar makes the shape of the year readable at a glance. */}
                    <div className="mt-1 h-1.5 w-full max-w-[200px] overflow-hidden rounded-full bg-slate-100">
                      <div
                        className={`h-full rounded-full ${m.over_budget ? "bg-red-500" : "bg-[#28364b]"}`}
                        style={{ width: `${Math.min(100, (bar(m) / peak) * 100)}%` }}
                      />
                    </div>
                  </td>
                  <td className="px-2 py-2 text-right text-slate-500">{m.requisitions}</td>
                  <td className="px-2 py-2 text-right text-slate-500">{m.items}</td>
                  {canSeeMoney && (
                    <td className="px-2 py-2 text-right font-semibold text-[#28364b]">${money(m.order_value)}</td>
                  )}
                  {canSeeMoney && (
                    <td className="px-2 py-2 text-right text-slate-500">{m.budget > 0 ? `$${money(m.budget)}` : "—"}</td>
                  )}
                  {canSeeMoney && (
                    <td className={`px-4 py-2 text-right font-semibold ${m.over_budget ? "text-red-600" : "text-slate-500"}`}>
                      {m.budget > 0 ? `$${money(m.balance)}` : "—"}
                    </td>
                  )}
                  {/* Which months ran over is the finding; by how much is the
                      figure. The cook keeps the first. */}
                  {!canSeeMoney && (
                    <td className="px-4 py-2 text-right">
                      {m.over_budget
                        ? <span className="font-semibold text-red-600">Over</span>
                        : <span className="text-slate-400">—</span>}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Spend by category */}
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="border-b border-slate-100 px-4 py-3">
          <h2 className="text-sm font-bold text-[#28364b]">Next order, by category</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">Category</th>
                <th className="px-2 py-2 text-right font-semibold">Items</th>
                <th className="px-2 py-2 text-right font-semibold">To order</th>
                <th className="px-2 py-2 text-right font-semibold">Order now</th>
                <th className="px-2 py-2 text-right font-semibold">Low</th>
                {canSeeMoney && <th className="px-4 py-2 text-right font-semibold">Value</th>}
              </tr>
            </thead>
            <tbody>
              {(byCategory?.categories ?? []).map((c) => (
                <tr key={c.category_id} className="border-t border-slate-100">
                  <td className="px-4 py-2 font-medium text-[#28364b]">{c.category}</td>
                  <td className="px-2 py-2 text-right text-slate-500">{c.items}</td>
                  <td className="px-2 py-2 text-right text-slate-500">{c.to_order}</td>
                  <td className="px-2 py-2 text-right">
                    {c.order_now > 0 ? <span className="font-semibold text-red-600">{c.order_now}</span> : <span className="text-slate-400">—</span>}
                  </td>
                  <td className="px-2 py-2 text-right">
                    {c.low > 0 ? <span className="font-semibold text-amber-600">{c.low}</span> : <span className="text-slate-400">—</span>}
                  </td>
                  {canSeeMoney && (
                    <td className="px-4 py-2 text-right font-semibold text-[#28364b]">${money(c.order_value)}</td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Closed periods — the record of measured consumption */}
      {byCategory?.periods?.length > 0 && (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <div className="border-b border-slate-100 px-4 py-3">
            <h2 className="text-sm font-bold text-[#28364b]">Closed supply periods</h2>
          </div>
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">Period</th>
                <th className="px-2 py-2 font-semibold">From</th>
                <th className="px-2 py-2 font-semibold">To</th>
                <th className="px-2 py-2 text-right font-semibold">Days</th>
                <th className="px-4 py-2 text-right font-semibold">Crew</th>
              </tr>
            </thead>
            <tbody>
              {byCategory.periods.map((p) => (
                <tr key={p.id} className="border-t border-slate-100">
                  <td className="px-4 py-2 font-medium text-[#28364b]">{p.label}</td>
                  <td className="px-2 py-2 text-slate-500">{dateOf(p.start_date)}</td>
                  <td className="px-2 py-2 text-slate-500">{dateOf(p.end_date)}</td>
                  <td className="px-2 py-2 text-right text-slate-500">{p.days}</td>
                  <td className="px-4 py-2 text-right text-slate-500">{p.crew_size ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
