import { useQuery } from "@tanstack/react-query";
import { Link } from "wouter";
import {
  Wallet,
  ShoppingCart,
  AlertTriangle,
  CheckCircle2,
  CircleAlert,
  ClipboardList,
  PackageCheck,
  CalendarRange,
  ArrowRight,
  Clock,
  Truck,
} from "lucide-react";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import { StatCard, StatusPill, money, qty, dateOf, btn } from "./lib";
import { PageLoader } from "@/portal/ui/Loading";

/**
 * Read-only summary for the crew: what is short, what is going off, what is on
 * its way — and, for the accounts shown money, what it comes to.
 * No editable fields and no visible formulas.
 */
export default function Dashboard() {
  const { money: canSeeMoney } = useAccess();

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "dashboard"],
    queryFn: async () => (await inventoryAPI.dashboard()).data.data,
    staleTime: 30_000,
  });

  if (isLoading) return <PageLoader label="Loading vessel summary…" />;

  const s = data?.summary ?? {};
  const vessel = data?.vessel;
  const budgetSet = Number(s.budget) > 0;

  // Counted by item rather than by batch: this card is read while deciding what
  // to cook, and two batches of the same thing is still one decision. The batch
  // board counts batches, because that is what gets thrown away.
  // Orders the office has bought and the ship has not booked in yet.
  const expecting = Number(data?.expected_deliveries ?? 0);
  const expiredItems = Number(s.expired_items ?? 0);
  const expiringItems = Number(s.expiring_items ?? 0);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-[#28364b]">{vessel?.name}</h1>
        <p className="text-sm text-slate-500">
          {s.crew_size} crew · next supply must cover {s.days_to_next_supply} days · {qty(s.safety_margin_pct)}% safety margin
        </p>
      </div>

      {/* Money — the office and the master only.
          The cook is not shown the budget, so he is not shown the row at all:
          four cards reading "—" would be worse than none, because they would
          tell him there is something here he is being kept from. What he needs
          off this screen is further down and does not involve a price. */}
      {canSeeMoney && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatCard label="Budget" value={budgetSet ? `$${money(s.budget)}` : "Not set"} hint={budgetSet ? "" : "Set it in Settings"} icon={Wallet} />
          <StatCard label="Order value" value={`$${money(s.order_value)}`} hint={`${s.items_to_order} items to order`} tone="navy" icon={ShoppingCart} />
          <StatCard
            label="Budget balance"
            value={budgetSet ? `$${money(s.budget_balance)}` : "—"}
            hint={s.over_budget ? "Over budget" : budgetSet ? "Remaining" : ""}
            tone={s.over_budget ? "danger" : budgetSet ? "good" : "default"}
            icon={Wallet}
          />
          <StatCard
            label="Missing prices"
            value={s.missing_prices}
            hint={s.missing_prices ? "Order value is understated" : "All priced"}
            tone={s.missing_prices > 0 ? "low" : "good"}
            icon={AlertTriangle}
          />
        </div>
      )}

      {/* The one part of the budget the cook does get: that the next order is
          past it. He is the one writing it, so he is the one who can take
          something off — he simply does not need the figure to do that. */}
      {!canSeeMoney && s.over_budget && (
        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
          <p className="text-sm text-amber-900">
            <span className="font-semibold">The next order is over the vessel&apos;s budget.</span>{" "}
            Trim the quantities on the requisition, or send it up as it is and let the master decide.
          </p>
        </div>
      )}

      {/* The state of the stores, in the order the questions get asked.
          The first three are quantity against target. The fourth is time: food
          on board, counted, GOOD against its target, and going off on Friday.
          The fifth is what is coming, which nothing used to tell the ship. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <StatCard label="Good" value={s.good} tone="good" icon={CheckCircle2} />
        <StatCard label="Low" value={s.low} tone="low" icon={CircleAlert} />
        <StatCard label="Order now" value={s.order_now} tone="danger" icon={AlertTriangle} />
        <Link href="/withdrawals?tab=batches" className="block transition-opacity hover:opacity-80">
          <StatCard
            label={expiredItems > 0 ? "Expired" : "Expiring"}
            value={expiredItems > 0 ? expiredItems : expiringItems}
            hint={
              expiredItems > 0
                ? `past its date${expiringItems > 0 ? ` · ${expiringItems} more soon` : ""} — write it off`
                : expiringItems > 0
                  ? `within ${s.expiry_window_days ?? 14} days — cook these first`
                  : "nothing near its date"
            }
            tone={expiredItems > 0 ? "danger" : expiringItems > 0 ? "low" : "good"}
            icon={expiredItems > 0 ? AlertTriangle : Clock}
          />
        </Link>
        <Link href="/receiving" className="block transition-opacity hover:opacity-80">
          <StatCard
            label="To receive"
            value={expecting}
            hint={expecting > 0 ? "bought ashore, not booked in" : "nothing on its way"}
            tone={expecting > 0 ? "navy" : "default"}
            icon={Truck}
          />
        </Link>
      </div>

      <div className="grid gap-5 lg:grid-cols-3">
        {/* Needs attention first */}
        <div className="lg:col-span-2">
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
              <h2 className="text-sm font-bold text-[#28364b]">Needs ordering first</h2>
              <Link href="/requisition" className="flex items-center gap-1 text-xs font-semibold text-[#28364b] hover:underline">
                Full order <ArrowRight className="h-3 w-3" />
              </Link>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-sm">
                <thead>
                  <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                    <th className="px-4 py-2 font-semibold">Item</th>
                    <th className="px-2 py-2 text-right font-semibold">Stock</th>
                    <th className="px-2 py-2 text-right font-semibold">Target</th>
                    <th className="px-2 py-2 text-right font-semibold">Order</th>
                    <th className="px-3 py-2 font-semibold">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {(data?.urgent ?? []).map((i) => (
                    <tr key={i.id} className="border-t border-slate-100">
                      <td className="px-4 py-2">
                        <span className="font-medium text-[#28364b]">{i.name}</span>
                        {i.variant && <span className="ml-1 text-xs text-slate-400">{i.variant}</span>}
                        <span className="ml-1 text-xs text-slate-400">· {i.unit}</span>
                      </td>
                      <td className="px-2 py-2 text-right text-slate-500">{qty(i.current_stock)}</td>
                      <td className="px-2 py-2 text-right text-slate-500">{qty(i.target_qty)}</td>
                      <td className="px-2 py-2 text-right font-semibold text-[#28364b]">{qty(i.order_qty)}</td>
                      <td className="px-3 py-2">
                        <StatusPill status={i.status} />
                      </td>
                    </tr>
                  ))}
                  {!data?.urgent?.length && (
                    <tr>
                      <td colSpan={5} className="px-4 py-10 text-center text-sm text-slate-400">
                        Nothing is critically short. Good to go.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        {/* Side column: period + recent activity */}
        <div className="space-y-4">
          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="mb-2 flex items-center gap-2">
              <CalendarRange className="h-4 w-4 text-[#cebd88]" />
              <h2 className="text-sm font-bold text-[#28364b]">Supply period</h2>
            </div>
            {data?.open_period ? (
              <p className="text-sm text-slate-600">
                <span className="font-semibold">{data.open_period.label}</span> is open since {dateOf(data.open_period.start_date)}.
              </p>
            ) : (
              <p className="text-sm text-slate-500">No period is open right now.</p>
            )}
            {data?.last_closed_period && (
              <p className="mt-1 text-xs text-slate-400">
                Ordering uses measured consumption from {data.last_closed_period.label} ({data.last_closed_period.days} days).
              </p>
            )}
            <Link href="/periods" className={`${btn.ghost} mt-3 w-full`}>
              Manage periods
            </Link>
          </div>

          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="mb-2 flex items-center gap-2">
              <ClipboardList className="h-4 w-4 text-[#cebd88]" />
              <h2 className="text-sm font-bold text-[#28364b]">Recent requisitions</h2>
            </div>
            <ul className="space-y-2">
              {(data?.recent_requisitions ?? []).map((r) => (
                <li key={r.id} className="flex items-center justify-between gap-2 text-sm">
                  <span className="truncate text-slate-600">{r.req_number}</span>
                  {/* What it came to, or what became of it. The cook gets the
                      second, which is the more useful of the two to him. */}
                  <span className="shrink-0 text-xs text-slate-400">
                    {canSeeMoney ? `$${money(r.total_value)}` : (r.status_label ?? r.status)}
                  </span>
                </li>
              ))}
              {!data?.recent_requisitions?.length && <li className="text-sm text-slate-400">None yet.</li>}
            </ul>
          </div>

          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="mb-2 flex items-center gap-2">
              <PackageCheck className="h-4 w-4 text-[#cebd88]" />
              <h2 className="text-sm font-bold text-[#28364b]">Recent deliveries</h2>
            </div>
            <ul className="space-y-2">
              {(data?.recent_receipts ?? []).map((r) => (
                <li key={r.id} className="flex items-center justify-between gap-2 text-sm">
                  <span className="truncate text-slate-600">{r.reference || r.supplier?.name || "Delivery"}</span>
                  <span className="shrink-0 text-xs text-slate-400">{dateOf(r.received_date)}</span>
                </li>
              ))}
              {!data?.recent_receipts?.length && <li className="text-sm text-slate-400">None yet.</li>}
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
}
