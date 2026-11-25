import { Fragment, useEffect, useMemo, useRef, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link } from "wouter";
import {
  ClipboardList,
  Download,
  Printer,
  Save,
  Trash2,
  Wallet,
  ShoppingCart,
  PackageCheck,
  Check,
  RotateCcw,
  CalendarRange,
  SendHorizontal,
  Undo2,
  AlertTriangle,
  Info,
  ChevronRight,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import OrderLines from "./OrderLines";
import { StatCard, money, qty, dateOf, btn, inputCls, numCls, downloadBlob } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";
import Modal from "@/portal/ui/Modal";

/**
 * A value that settles before anything acts on it.
 *
 * The order total changes on every keystroke in a quantity box, and the budget
 * verdict is a request. Without this it would be one request per keystroke.
 */
function useSettled(value, ms) {
  const [settled, setSettled] = useState(value);

  useEffect(() => {
    const t = setTimeout(() => setSettled(value), ms);

    return () => clearTimeout(t);
  }, [value, ms]);

  return settled;
}

/**
 * The order, and the three pairs of hands it passes through.
 *
 * The cook writes it, because he is the one who knows what the galley is short
 * of. The master approves it, because he is answerable for what his ship
 * spends. Only then does the office see it, and buy it.
 *
 * The budget warns and never refuses. There are good reasons to go over — a
 * long leg, a crew change, a price that moved — and the only unacceptable
 * outcome is the office finding out afterwards.
 *
 * Line prices are not shown on board at all — not to the cook and not to the
 * master. A requisition is a request for quantities: the ship says what she is
 * short of, and what it costs is settled ashore against the quotations that
 * month, which move. Putting last month's price beside this month's request
 * only invites the galley to trim an order against a figure nobody is going to
 * pay. So the Unit price column is the office's, and nobody else's.
 *
 * What the order COMES TO is a different question, and that one follows rank:
 * the master signs the order off and answers for the spend, so he is shown the
 * total and the budget behind it. The cook is shown neither, and is still
 * warned when the order is too big — see the budget query below, which sends
 * the lines rather than a total precisely so that warning survives.
 */
export default function Requisition() {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const { vessel, master, office, money: canSeeMoney } = useAccess();
  const [notes, setNotes] = useState("");

  // How long this order has to last. Normally the vessel's own supply period;
  // the cook changes it for a long leg or a yard period, and every target on
  // the order scales with it.
  const [days, setDays] = useState("");
  const typedDays = Number(days) > 0 ? Number(days) : null;

  /**
   * Settled before it reaches the query key, which is the whole reason this
   * exists.
   *
   * Typing "60" passes through 6 on the way, so the key changed twice and the
   * screen fetched a six-day order nobody asked for. Worse: a key react-query
   * has not seen before has no rows cached, so the query reported itself as
   * loading, the page below swapped itself for a loader, and the box being
   * typed in was unmounted half way through the number.
   */
  const daysAsked = useSettled(typedDays, 500);

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ["inv", "requisition-preview", daysAsked],
    queryFn: async () =>
      (await inventoryAPI.requisitionPreview(daysAsked ? { days: daysAsked } : {})).data.data,
    staleTime: 15_000,
    // The old rows stay on screen while the new ones are worked out. Without
    // this the table empties on every recalculation, which reads as a page
    // reload even once the fetching itself is under control.
    keepPreviousData: true,
  });

  // Either the keystrokes have not settled yet or the answer is in flight.
  const recalculating = typedDays !== daysAsked || isFetching;

  useEffect(() => {
    // Shown as the vessel's figure until somebody types over it, so the box is
    // never blank and never lying about what the order was calculated for.
    if (days === "" && vessel?.supply_period_days) setDays(String(vessel.supply_period_days));
  }, [vessel, days]);

  const { data: history } = useQuery({
    queryKey: ["inv", "requisitions"],
    queryFn: async () => (await inventoryAPI.requisitions()).data.data,
  });

  const saved = history?.requisitions;
  const waiting = history?.waiting ?? 0;

  // What the office is actually ordering, which starts as what the calculator
  // asked for and stays editable until the order is saved.
  const [picked, setPicked] = useState({});    // item id -> true
  const [amounts, setAmounts] = useState({});  // item id -> typed quantity
  const [removed, setRemoved] = useState({});  // item id -> taken off the list
  const [hideUnpriced, setHideUnpriced] = useState(false);
  const [openOrder, setOpenOrder] = useState(null);   // saved order showing its lines
  const [sendBack, setSendBack] = useState(null);     // { req, reason } — the master's refusal

  /**
   * The half-written order survives leaving the screen.
   *
   * Everything on this page was component state, so stepping over to Inventory
   * to check a shelf and coming back threw it all away: lines put back on the
   * list, quantities back to the calculator's, ticks restored. The order is
   * written over several minutes and nobody writes one without looking
   * something up half way through.
   *
   * The browser is the right place for it. This is a draft nobody has agreed to
   * — it belongs to the person writing it, on the machine they are writing it
   * on, and the moment it is saved it stops being a draft and the server owns
   * it. Per vessel, so two ships open in two tabs do not share one.
   */
  const draftKey = vessel?.id ? `inv:req-draft:${vessel.id}` : null;
  const restored = useRef(false);

  const forgetDraft = () => {
    restored.current = true;
    try {
      if (draftKey) localStorage.removeItem(draftKey);
    } catch {
      /* private window, blocked storage — the draft simply does not persist */
    }
  };

  useEffect(() => {
    if (!data?.lines) return;

    const tick = {};
    const amount = {};
    for (const l of data.lines) {
      tick[l.id] = true;
      amount[l.id] = l.order_qty;
    }

    // Only on the way in. This effect runs again whenever the calculation
    // changes — a new days-of-supply, a saved order — and those must reset the
    // quantities to what was just worked out rather than reinstate old ones.
    if (!restored.current) {
      restored.current = true;

      let saved = null;
      try {
        saved = draftKey ? JSON.parse(localStorage.getItem(draftKey) || "null") : null;
      } catch {
        saved = null;
      }

      if (saved) {
        for (const l of data.lines) {
          if (saved.picked && l.id in saved.picked) tick[l.id] = saved.picked[l.id];
          if (saved.amounts && l.id in saved.amounts) amount[l.id] = saved.amounts[l.id];
        }
        setRemoved(saved.removed ?? {});
        if (saved.notes) setNotes(saved.notes);
      }
    }

    setPicked(tick);
    setAmounts(amount);
  }, [data, draftKey]);

  // Written back on every change, so there is nothing to lose by navigating
  // away mid-order.
  useEffect(() => {
    if (!draftKey || !restored.current) return;

    try {
      localStorage.setItem(draftKey, JSON.stringify({ picked, amounts, removed, notes }));
    } catch {
      /* see above */
    }
  }, [draftKey, picked, amounts, removed, notes]);

  const lines = data?.lines ?? [];

  const chosen = useMemo(
    () => lines.filter((l) => picked[l.id] && !removed[l.id] && Number(amounts[l.id]) > 0),
    [lines, picked, amounts, removed]
  );

  // The cards follow the ticks, not the calculation, so the figure above the
  // table is the one being ordered.
  const total = useMemo(
    () =>
      chosen.reduce(
        (sum, l) => sum + (l.unit_price === null ? 0 : Number(amounts[l.id] || 0) * Number(l.unit_price)),
        0
      ),
    [chosen, amounts]
  );

  const visible = useMemo(
    () =>
      (hideUnpriced ? lines.filter((l) => l.unit_price !== null) : lines)
        .filter((l) => !removed[l.id]),
    [lines, hideUnpriced, removed]
  );

  // The verdict on what is actually ticked, asked of the server so there is
  // only ever one answer to "are we over budget".
  //
  // The lines go up, not the total. A cook's rows arrive with the money taken
  // out, so a total worked out here would be zero for him and every order would
  // come back comfortably inside the budget — the one warning he still gets,
  // silenced by the rule that hides the figures. The server prices what he
  // ticked instead, and he is told the answer without being told the sum.
  //
  // Settled first: this moves with every tick and every retyped quantity.
  const order = useMemo(
    () => chosen.map((l) => ({ item_id: l.id, order_qty: Number(amounts[l.id] || 0) })),
    [chosen, amounts]
  );
  const settled = useSettled(JSON.stringify(order), 400);

  const { data: budget } = useQuery({
    queryKey: ["inv", "requisition-budget", settled, daysAsked],
    queryFn: async () =>
      (
        await inventoryAPI.requisitionBudget({
          lines: JSON.parse(settled),
          days: daysAsked || undefined,
        })
      ).data.data.budget,
    enabled: settled !== null,
    staleTime: 60_000,
  });

  const unpriced = lines.filter((l) => l.unit_price === null).length;
  const allVisiblePicked = visible.length > 0 && visible.every((l) => picked[l.id]);

  const tickAllVisible = (on) =>
    setPicked((p) => ({ ...p, ...Object.fromEntries(visible.map((l) => [l.id, on])) }));

  // Puts the whole list back: every quantity to what the calculator asked for,
  // and every line that was binned back on it. One button, because "undo what
  // I did to this order" is one thought.
  const resetAmounts = () => {
    setAmounts(Object.fromEntries(lines.map((l) => [l.id, l.order_qty])));
    setPicked(Object.fromEntries(lines.map((l) => [l.id, true])));
    setRemoved({});
    setNotes("");
  };

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["inv", "requisitions"] });
    qc.invalidateQueries({ queryKey: ["inv", "requisition-preview"] });
    // An open panel is looking at one of these; a distinct key from the
    // preview's, so this does not drag that along with it.
    qc.invalidateQueries({ queryKey: ["inv", "requisition"] });
  };

  const create = useMutation({
    mutationFn: () =>
      inventoryAPI.createRequisition({
        notes: notes.trim() || null,
        days_of_supply: daysAsked,
        lines: chosen.map((l) => ({ item_id: l.id, order_qty: Number(amounts[l.id]) })),
      }),
    onSuccess: (res) => {
      // The budget warning comes back with the save, so it is read on the
      // order that was just written rather than having to be gone looking for.
      const level = res.data.data?.budget?.level;
      const say = level === "over" || level === "spans" ? toast.warning : toast.success;
      say(res.data.message || `Saved as ${res.data.data.requisition.req_number}.`, {
        duration: level === "over" || level === "spans" ? 9000 : 4000,
      });
      // Saved, so it is not a draft any more — the next order starts from what
      // the calculator says, not from what was on screen when this one went.
      setNotes("");
      setRemoved({});
      forgetDraft();
      refresh();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the requisition."),
  });

  const remove = useMutation({
    mutationFn: (id) => inventoryAPI.removeRequisition(id),
    onSuccess: () => {
      toast.success("Requisition deleted.");
      refresh();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not delete."),
  });

  // Each step of the chain says its own piece: the server's message names the
  // order and who it went to, which is more use than "Saved".
  const told = (fallback) => ({
    onSuccess: (res) => {
      toast.success(res.data.message || fallback);
      refresh();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not do that."),
  });

  const submit = useMutation({
    mutationFn: (id) => inventoryAPI.submitRequisition(id),
    ...told("Sent up for approval."),
  });

  const pullBack = useMutation({
    mutationFn: (id) => inventoryAPI.pullBackRequisition(id),
    ...told("Pulled back."),
  });

  const approve = useMutation({
    mutationFn: (id) => inventoryAPI.approveRequisition(id),
    ...told("Approved."),
  });

  const decline = useMutation({
    mutationFn: ({ id, reason }) => inventoryAPI.declineRequisition(id, reason),
    onSuccess: (res) => {
      toast.success(res.data.message || "Sent back.");
      setSendBack(null);
      refresh();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not send it back."),
  });

  const setStatus = useMutation({
    mutationFn: ({ id, status }) => inventoryAPI.updateRequisition(id, { status }),
    onSuccess: () => refresh(),
    onError: (e) => toast.error(e?.response?.data?.message || "Could not update the requisition."),
  });

  const askApprove = async (req) => {
    const value = Number(req.total_value || 0);
    const over = value > Number(req.budget_at_issue || 0) && Number(req.budget_at_issue) > 0;

    // An order that totals nothing is an order nobody has priced, not an order
    // that costs nothing. On this ship most of the catalogue came out of the
    // workbook with no price at all — 172 items of 218 — so a master approving
    // fresh fruit is reliably shown a total of zero, and "$0.00" reads as free.
    // It is the same rule the catalogue and the quotations follow: a zero is
    // not a price, it is a gap, and the two must never be printed alike.
    const unpriced = canSeeMoney && value <= 0 && req.items_count > 0;

    // This is the moment somebody takes responsibility for the spend, so the
    // account that signs it is told what it comes to. A cook never reaches this
    // dialog — approving is the master's whole part in the chain — but the
    // check is on seeing money rather than on rank, so the two can never
    // disagree about what this screen is allowed to say.
    const message = unpriced
      ? `${req.items_count} ${req.items_count === 1 ? "line" : "lines"}, covering ${req.days_of_supply} days. ` +
        "None of them has a price on file yet, so there is no figure to approve against — " +
        "the office will price it against this month's quotes."
      : canSeeMoney
        ? (over
            ? `$${money(req.total_value)} against a $${money(req.budget_at_issue)} budget for ${req.days_of_supply} days. ` +
              "Approve it if the ship needs it."
            : `$${money(req.total_value)} over ${req.items_count} lines, covering ${req.days_of_supply} days.`)
        : `${req.items_count} ${req.items_count === 1 ? "line" : "lines"}, covering ${req.days_of_supply} days. ` +
          "The office will see it on their list straight away and price it against this month's quotes.";

    const ok = await confirm({ title: `Approve ${req.req_number}?`, message, confirmText: "Approve" });
    if (ok) approve.mutate(req.id);
  };

  const dropRequest = useMutation({
    mutationFn: (id) => inventoryAPI.dropOrderRequest(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["inv", "items"] }),
  });

  /**
   * Take a line off the order.
   *
   * It goes off the list and off the count at the top, and Reset quantities
   * brings it back — this is about the order being written, not about the
   * catalogue.
   *
   * One thing happens behind it. A line put here by somebody pressing Order on
   * the Inventory screen carries a STANDING ask: drop it from the list and it
   * would be back next time the screen is opened, which is not what anybody
   * means by removing it. So that ask is cancelled at the same time. A line the
   * calculator raised has nothing to cancel — it is below target, and it comes
   * back when it should, which is also right.
   */
  const removeLine = (line) => {
    setRemoved((r) => ({ ...r, [line.id]: true }));
    setPicked((p) => ({ ...p, [line.id]: false }));

    if (line.request_id) {
      dropRequest.mutate(line.request_id);
    }
  };

  // The reason is required by the API on purpose: "declined" on its own tells
  // the cook to guess, and he will guess the master is being awkward.
  const askDecline = (req) => setSendBack({ req, reason: "" });

  const [downloading, setDownloading] = useState(null);

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

  const askRemove = async (req) => {
    const ok = await confirm({
      title: "Delete requisition",
      message: `Delete ${req.req_number}? Only drafts can be deleted.`,
      confirmText: "Delete",
      tone: "danger",
    });
    if (ok) remove.mutate(req.id);
  };

  // Only the very first load takes the screen. A recalculation keeps the rows
  // and says so beside the box instead.
  if (isLoading && !data) return <PageLoader label="Working out the order…" />;

  const s = data?.summary ?? {};
  const budgetSet = Number(s.budget) > 0;
  const b = budget ?? data?.budget ?? {};
  const overBudget = b.level === "over";

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-[#28364b]">Requisition</h1>
          <p className="text-sm text-slate-500">
            Everything below target, with the quantity the calculator asked for. Untick what you are not ordering and
            change any quantity before you save.
          </p>
        </div>
        <div className="flex gap-2">
          <button onClick={() => window.print()} className={btn.ghost}>
            <Printer className="h-4 w-4" /> Print
          </button>
          <button onClick={() => create.mutate()} disabled={!chosen.length || create.isLoading} className={btn.primary}>
            {create.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />}
            Save this order{chosen.length ? ` (${chosen.length})` : ""}
          </button>
        </div>
      </div>

      <div className={`grid gap-3 ${canSeeMoney ? "grid-cols-2 lg:grid-cols-4" : "grid-cols-2"}`}>
        <StatCard
          label="Items to order"
          value={chosen.length}
          hint={chosen.length === lines.length ? "everything below target" : `of ${lines.length} below target`}
          icon={ShoppingCart}
        />
        {!canSeeMoney && (
          <StatCard
            label="Days of supply"
            value={daysAsked ?? vessel?.supply_period_days ?? "—"}
            hint="what this order has to last"
            tone="navy"
            icon={CalendarRange}
          />
        )}
        {canSeeMoney && (
          <>
            {/* Off the server's verdict rather than added up here. The master
                is shown the total but not the line prices, so there is nothing
                on his screen to add up — and the office gets the benefit that
                the card and the warning below can no longer disagree, because
                both now come from the same arithmetic. */}
            <StatCard label="Order value" value={`$${money(b.order_value ?? total)}`} tone="navy" icon={Wallet} />
            <StatCard
              label={b.spans_months ? `Budget · ${b.months} months` : "Budget"}
              value={budgetSet ? `$${money(b.allowance ?? s.budget)}` : "Not set"}
              hint={budgetSet && b.spans_months ? `$${money(b.budget)} a month` : undefined}
              icon={Wallet}
            />
            <StatCard
              label="Balance after order"
              value={budgetSet ? `$${money(b.balance ?? (b.allowance ?? s.budget) - total)}` : "—"}
              tone={!budgetSet ? "default" : overBudget ? "danger" : b.level === "close" ? "low" : "good"}
              icon={Wallet}
            />
          </>
        )}
      </div>

      {/* The budget warning, worked out against the days the order covers
          rather than against one month — which is the whole point. It never
          stops the order.
          Shown to the cook as well, and that is deliberate: he is the one
          writing the order, so he is the one who can take something off it.
          The API sends him the same verdict with the figures removed, so the
          sentence below is already in the terms his account is allowed. */}
      {b.message && (
        <div
          className={`flex items-start gap-2.5 rounded-xl border px-4 py-3 text-xs ${
            overBudget
              ? "border-red-200 bg-red-50 text-red-800"
              : b.level === "spans"
                ? "border-amber-200 bg-amber-50 text-amber-800"
                : "border-slate-200 bg-slate-50 text-slate-600"
          }`}
        >
          {overBudget ? (
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          ) : (
            <Info className="mt-0.5 h-4 w-4 shrink-0" />
          )}
          <p>{b.message}</p>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2">
        {/* How long the order has to last. Changing it rescales every target
            on the table below, and the budget warning with it. */}
        <label className="flex items-center gap-2 whitespace-nowrap rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
          <CalendarRange className="h-4 w-4 text-[#cebd88]" />
          <span>Days of supply</span>
          <input
            type="number"
            min="1"
            max="365"
            value={days}
            onChange={(e) => setDays(e.target.value)}
            onFocus={(e) => e.target.select()}
            className={`${numCls} w-16 px-2 py-1`}
          />
          {vessel?.supply_period_days && Number(days) !== Number(vessel.supply_period_days) && (
            <button
              type="button"
              onClick={() => setDays(String(vessel.supply_period_days))}
              className="text-[11px] font-semibold text-[#28364b] hover:underline"
              title={`Back to the vessel's usual ${vessel.supply_period_days} days`}
            >
              usually {vessel.supply_period_days}
            </button>
          )}
          {/* Something is happening, and the table below is a moment behind. */}
          {recalculating && <Spinner className="h-3.5 w-3.5 text-slate-400" />}
        </label>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <input
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          placeholder="Note for this order (optional) — e.g. delivery port, vessel schedule"
          className={`${inputCls} min-w-[260px] flex-1`}
        />
        {office && unpriced > 0 && (
          <label className="flex cursor-pointer items-center gap-2 whitespace-nowrap rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
            <input
              type="checkbox"
              checked={hideUnpriced}
              onChange={(e) => setHideUnpriced(e.target.checked)}
              className="h-3.5 w-3.5 accent-[#28364b]"
            />
            Hide the {unpriced} with no price
          </label>
        )}
        <button onClick={resetAmounts} className={`${btn.ghost} whitespace-nowrap`} title="Put every quantity back to what the calculator asked for, and any line you removed back on the list">
          <RotateCcw className="h-4 w-4" /> Reset quantities
        </button>
      </div>

      {/* The calculated order */}
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[860px] text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="w-10 px-3 py-2">
                  <input
                    type="checkbox"
                    checked={allVisiblePicked}
                    onChange={(e) => tickAllVisible(e.target.checked)}
                    className="h-3.5 w-3.5 accent-[#28364b]"
                    title={allVisiblePicked ? "Untick everything shown" : "Tick everything shown"}
                  />
                </th>
                <th className="px-2 py-2 font-semibold">Code</th>
                <th className="px-4 py-2 font-semibold">Item</th>
                <th className="px-2 py-2 font-semibold">Category</th>
                <th className="px-2 py-2 font-semibold">Unit</th>
                <th className="px-2 py-2 text-right font-semibold">Stock</th>
                <th className="px-2 py-2 text-right font-semibold">Target</th>
                <th className="px-2 py-2 text-right font-semibold">Order</th>
                {office && <th className="px-2 py-2 text-right font-semibold">Unit price</th>}
                {office && <th className="px-4 py-2 text-right font-semibold">Value</th>}
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {visible.map((l) => {
                const on = !!picked[l.id];
                const amount = amounts[l.id] ?? l.order_qty;
                const changed = Number(amount || 0) !== Number(l.order_qty);
                const value = l.unit_price === null ? 0 : Number(amount || 0) * Number(l.unit_price);

                return (
                  <tr key={l.id} className={`border-t border-slate-100 ${on ? "" : "bg-slate-50/60 text-slate-400"}`}>
                    <td className="px-3 py-2">
                      <input
                        type="checkbox"
                        checked={on}
                        onChange={(e) => setPicked((p) => ({ ...p, [l.id]: e.target.checked }))}
                        className="h-3.5 w-3.5 accent-[#28364b]"
                      />
                    </td>
                    <td className="whitespace-nowrap px-2 py-2 font-mono text-xs text-slate-400">{l.code}</td>
                    {/* A line is on this list for one of two reasons: the
                        calculator raised it because the shelf is below target,
                        or somebody pressed Order on the Inventory screen and
                        asked for it. Worth saying which, because the second
                        kind ignores the target and will look wrong otherwise. */}
                    <td className="px-4 py-2">
                      <span className={`font-medium ${on ? "text-[#28364b]" : ""}`}>{l.name}</span>
                      {l.variant && <span className="ml-1 text-xs text-slate-400">{l.variant}</span>}
                      {l.requested && (
                        <span
                          title={l.request_note || "Asked for on the Inventory screen, not raised by the calculator"}
                          className="ml-2 rounded-full bg-[#cebd88]/25 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-[#8a7a4a]"
                        >
                          Asked for
                        </span>
                      )}
                    </td>
                    <td className="px-2 py-2 text-xs text-slate-400">{l.category}</td>
                    <td className="px-2 py-2 text-xs text-slate-500">{l.unit}</td>
                    <td className="px-2 py-2 text-right text-slate-500">{qty(l.current_stock)}</td>
                    <td className="px-2 py-2 text-right text-slate-500">{qty(l.target_qty)}</td>
                    <td className="px-2 py-2 text-right">
                      <input
                        type="number"
                        min="0"
                        step="any"
                        value={amount}
                        disabled={!on}
                        onChange={(e) => setAmounts((a) => ({ ...a, [l.id]: e.target.value }))}
                        onFocus={(e) => e.target.select()}
                        className={`${numCls} w-20 px-2 py-1 disabled:bg-slate-50 disabled:text-slate-400`}
                      />
                      {/* What the calculator asked for, kept in view whenever
                          the order deviates from it. */}
                      {changed && (
                        <span className="mt-0.5 block text-[10px] text-amber-600" title="Calculated quantity">
                          was {qty(l.order_qty)}
                        </span>
                      )}
                    </td>
                    {office && (
                      <td className="px-2 py-2 text-right text-slate-500">
                        {l.unit_price === null ? <span className="text-amber-600">no price</span> : money(l.unit_price)}
                      </td>
                    )}
                    {office && <td className="px-4 py-2 text-right text-slate-600">{on ? money(value) : "—"}</td>}
                    <td className="px-3 py-2 text-right">
                      <button
                        onClick={() => removeLine(l)}
                        title="Take this off the order"
                        className="rounded-lg p-1.5 text-red-500 transition-colors hover:bg-red-50 hover:text-red-700"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </button>
                    </td>
                  </tr>
                );
              })}
              {!visible.length && (
                <tr>
                  <td colSpan={office ? 11 : 9} className="px-4 py-16 text-center text-sm text-slate-400">
                    {lines.length
                      ? "Every line below target has no price — untick the filter to see them."
                      : "Nothing to order — every item is at or above its target quantity."}
                  </td>
                </tr>
              )}
            </tbody>
            {visible.length > 0 && (
              <tfoot>
                <tr className="border-t-2 border-[#28364b] bg-slate-50">
                  {office ? (
                    <>
                      <td colSpan={9} className="px-4 py-3 text-right text-sm font-bold text-[#28364b]">
                        TOTAL ORDER VALUE · {chosen.length} {chosen.length === 1 ? "line" : "lines"}
                      </td>
                      <td className="px-4 py-3 text-right text-base font-bold text-[#28364b]">${money(total)}</td>
                    </>
                  ) : (
                    <td colSpan={8} className="px-4 py-3 text-right text-sm font-bold text-[#28364b]">
                      {chosen.length} {chosen.length === 1 ? "line" : "lines"} on this request
                    </td>
                  )}
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      </div>

      {/* Anyone shown a total is shown why it is wrong, which on this ship is
          most of the time: 172 of her 218 stocked items came out of the
          workbook with no price. Without this line the master reads "Order
          value $0.00" and takes it at face value. The office gets the sentence
          about how to fix it, because fixing it is the office's to do. */}
      {canSeeMoney && s.missing_prices > 0 && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
          {s.missing_prices} item{s.missing_prices === 1 ? " has" : "s have"} no price on file, so the total above is understated.
          {office
            ? " Add prices on the Inventory screen, or press Fill prices from quotations once the vendors have quoted."
            : " The office prices the order against this month's quotes once it goes ashore."}
        </p>
      )}

      {/* Saved orders, and where each one has got to */}
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
          <ClipboardList className="h-4 w-4 text-[#cebd88]" />
          <h2 className="text-sm font-bold text-[#28364b]">Saved orders</h2>
          {waiting > 0 && (
            <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700 ring-1 ring-amber-200">
              {waiting} waiting for the master
            </span>
          )}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[860px] text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">Number</th>
                <th className="px-2 py-2 font-semibold">Date</th>
                <th className="px-2 py-2 text-right font-semibold">Days</th>
                <th className="px-2 py-2 text-right font-semibold">Lines</th>
                <th className="px-2 py-2 font-semibold">Where it is</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              {(saved ?? []).map((r) => (
                <Fragment key={r.id}>
                <tr className="border-t border-slate-100 align-top">
                  <td className="px-4 py-2">
                    {/* The order's number opens the order. Approving something
                        you cannot read is not approving it, and the cook has
                        the same right to check what he sent up. */}
                    <button
                      onClick={() => setOpenOrder((o) => (o === r.id ? null : r.id))}
                      className="inline-flex items-center gap-1 font-semibold text-[#28364b] hover:underline"
                      title={openOrder === r.id ? "Hide the items" : "See the items on this order"}
                    >
                      <ChevronRight
                        className={`h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform ${openOrder === r.id ? "rotate-90" : ""}`}
                      />
                      {r.req_number}
                    </button>
                  </td>
                  <td className="whitespace-nowrap px-2 py-2 text-slate-500">{dateOf(r.req_date)}</td>
                  <td className="px-2 py-2 text-right text-slate-500">{r.days_of_supply}</td>
                  <td className="px-2 py-2 text-right text-slate-500">{r.items_count}</td>
                  <td className="px-2 py-2">
                    <StatusPill status={r.status} label={r.status_label} />
                    {/* The master's reason, where the cook will read it. */}
                    {r.status === "declined" && r.decline_reason && (
                      <p className="mt-1 max-w-[220px] text-[11px] text-red-600">“{r.decline_reason}”</p>
                    )}
                    {r.status === "approved" && r.approved_by && (
                      <p className="mt-1 text-[11px] text-slate-400">by {r.approved_by}</p>
                    )}
                    {r.status === "pending" && r.submitted_by && (
                      <p className="mt-1 text-[11px] text-slate-400">sent by {r.submitted_by}</p>
                    )}
                  </td>
                  <td className="px-4 py-2">
                    <div className="flex items-center justify-end gap-1">
                      {/* The cook's move: send it up. */}
                      {r.editable && (
                        <button
                          onClick={() => submit.mutate(r.id)}
                          disabled={submit.isLoading}
                          className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-[#28364b] px-2.5 py-1.5 text-[11px] font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-50"
                          title="Send to the master for approval"
                        >
                          <SendHorizontal className="h-3.5 w-3.5" /> Send up
                        </button>
                      )}

                      {/* Undo of the above, while nobody has answered it yet.
                          Shown to the galley only: the master already has Send
                          back, which is the same move with a reason attached. */}
                      {r.status === "pending" && !master && (
                        <button
                          onClick={() => pullBack.mutate(r.id)}
                          disabled={pullBack.isLoading}
                          className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-50"
                          title="Take it back off the master to change it"
                        >
                          {pullBack.isLoading && pullBack.variables === r.id ? (
                            <Spinner className="h-3.5 w-3.5" />
                          ) : (
                            <Undo2 className="h-3.5 w-3.5" />
                          )}
                          Pull back
                        </button>
                      )}

                      {/* The master's move, and only his. */}
                      {r.status === "pending" && master && (
                        <>
                          <button
                            onClick={() => askApprove(r)}
                            disabled={approve.isLoading}
                            className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-emerald-600 px-2.5 py-1.5 text-[11px] font-semibold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50"
                          >
                            <Check className="h-3.5 w-3.5" /> Approve
                          </button>
                          <button
                            onClick={() => askDecline(r)}
                            disabled={decline.isLoading}
                            className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-50"
                            title="Send it back with a reason"
                          >
                            <Undo2 className="h-3.5 w-3.5" /> Send back
                          </button>
                        </>
                      )}

                      {/* The office's move: mark it sent out to vendors. */}
                      {r.status === "approved" && office && (
                        <button
                          onClick={() => setStatus.mutate({ id: r.id, status: "issued" })}
                          className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-emerald-50 hover:text-emerald-600"
                          title="Mark as ordered"
                        >
                          <Check className="h-4 w-4" />
                        </button>
                      )}

                      <Link
                        href={`/receiving?requisition=${r.id}`}
                        className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                        title="Receive against this order"
                      >
                        <PackageCheck className="h-4 w-4" />
                      </Link>
                      <button
                        onClick={() => download(r)}
                        className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                        title="Download PDF"
                      >
                        {downloading === r.id ? <Spinner className="h-4 w-4" /> : <Download className="h-4 w-4" />}
                      </button>
                      {r.editable && (
                        <button
                          onClick={() => askRemove(r)}
                          className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                          title="Delete"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
                {openOrder === r.id && (
                  <tr className="border-t border-slate-100 bg-slate-50/60">
                    <td colSpan={6} className="px-4 py-3">
                      <OrderLines id={r.id} />
                    </td>
                  </tr>
                )}
                </Fragment>
              ))}
              {!saved?.length && (
                <tr>
                  <td colSpan={6} className="px-4 py-10 text-center text-sm text-slate-400">
                    No saved orders yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* The master's refusal.
          A dialog rather than a browser prompt because the reason is the point:
          it is the only thing the cook gets back, he reads it on the row in red,
          and he rewrites the order from it. Something worth typing two
          sentences into deserves a box you can see the order in while you do. */}
      <Modal open={!!sendBack} onClose={() => setSendBack(null)} title="Send the order back" maxWidth="max-w-lg">
        {sendBack && (
          <form
            className="space-y-4 p-6"
            onSubmit={(e) => {
              e.preventDefault();
              const reason = sendBack.reason.trim();
              if (!reason) {
                toast.error("Say what needs changing — the cook only gets this.");
                return;
              }
              decline.mutate({ id: sendBack.req.id, reason });
            }}
          >
            <div className="rounded-lg bg-slate-50 px-4 py-3">
              <p className="text-sm font-semibold text-[#28364b]">{sendBack.req.req_number}</p>
              <p className="mt-1 text-xs text-slate-500">
                {sendBack.req.items_count} {sendBack.req.items_count === 1 ? "line" : "lines"} covering{" "}
                {sendBack.req.days_of_supply} days
                {sendBack.req.submitted_by ? ` · sent by ${sendBack.req.submitted_by}` : ""}
              </p>
            </div>

            <label className="block">
              <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                Why it is going back
              </span>
              <textarea
                value={sendBack.reason}
                onChange={(e) => setSendBack((s) => ({ ...s, reason: e.target.value }))}
                rows={3}
                autoFocus
                maxLength={255}
                placeholder="Too much coffee for one month — halve it and take the nuts off."
                className={`${inputCls} w-full resize-none`}
              />
              <span className="mt-1 block text-[11px] text-slate-400">
                The cook reads this on the order and writes it again from it. {255 - sendBack.reason.length} left.
              </span>
            </label>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setSendBack(null)} className={btn.ghost}>
                Cancel
              </button>
              <button
                type="submit"
                disabled={!sendBack.reason.trim() || decline.isLoading}
                className="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-50"
              >
                {decline.isLoading ? <Spinner className="h-4 w-4" /> : <Undo2 className="h-4 w-4" />}
                Send it back
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
}

/** Where an order has got to, in the colour that says whose move it is. */
function StatusPill({ status, label }) {
  const tones = {
    draft: "bg-slate-100 text-slate-600 ring-slate-200",
    pending: "bg-amber-50 text-amber-700 ring-amber-200",
    declined: "bg-red-50 text-red-700 ring-red-200",
    approved: "bg-emerald-50 text-emerald-700 ring-emerald-200",
    issued: "bg-sky-50 text-sky-700 ring-sky-200",
    closed: "bg-slate-100 text-slate-500 ring-slate-200",
  };

  return (
    <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ${tones[status] ?? tones.draft}`}>
      {label ?? status}
    </span>
  );
}
