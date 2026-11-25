import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useSearch } from "wouter";
import {
  Plus,
  Trash2,
  Save,
  X,
  Layers,
  ClipboardList,
  AlertTriangle,
  CircleAlert,
  Clock,
  Info,
  Search,
} from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import { btn, inputCls, numCls, money, qty, dateOf, StatCard } from "./lib";
import Select from "@/portal/ui/Select";
import DatePicker from "@/portal/ui/DatePicker";
import { useConfirm } from "@/portal/ui/confirm";
import { PageLoader, Spinner } from "@/portal/ui/Loading";

const thisMonth = () => new Date().toISOString().slice(0, 7);
const today = () => new Date().toISOString().slice(0, 10);

/**
 * What left the store.
 *
 * The store can be kept two ways and both happen on real ships: some cooks
 * write down every draw as they make it, others only count the shelves at the
 * end of the week. Neither is compulsory here. A draw written down costs
 * itself against the batches it came out of; a count books whatever gap it
 * finds. Either way the month adds up — and a cook who does both gets the
 * detail without doing the work twice.
 *
 * Two tabs, because they answer different questions: what did we use, and what
 * is about to go off.
 */
export default function Withdrawals() {
  // Which tab opens is in the URL, so the expiry warnings on the dashboard and
  // the inventory can land straight on the batch board rather than on the log
  // with a second click to find.
  const search = useSearch();
  const [tab, setTab] = useState(() =>
    new URLSearchParams(search).get("tab") === "batches" ? "batches" : "log"
  );

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-bold text-[#28364b]">Withdrawals</h1>
        <p className="text-sm text-slate-500">
          What was taken out of the store, costed against the batch it came from.
        </p>
      </div>

      <div className="flex gap-1 border-b border-slate-200">
        <Tab active={tab === "log"} onClick={() => setTab("log")} icon={ClipboardList}>
          What we used
        </Tab>
        <Tab active={tab === "batches"} onClick={() => setTab("batches")} icon={Layers}>
          Batches on board
        </Tab>
      </div>

      {tab === "log" ? <Log /> : <Batches />}
    </div>
  );
}

function Tab({ active, onClick, icon: Icon, children }) {
  return (
    <button
      onClick={onClick}
      className={`-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors ${
        active
          ? "border-[#28364b] text-[#28364b]"
          : "border-transparent text-slate-400 hover:text-slate-600"
      }`}
    >
      <Icon className="h-4 w-4" /> {children}
    </button>
  );
}

/* ------------------------------- what we used ------------------------------ */

function Log() {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const { money: canSeeMoney } = useAccess();
  const [month, setMonth] = useState(thisMonth());
  const [adding, setAdding] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "withdrawals", month],
    queryFn: async () => (await inventoryAPI.withdrawals({ month })).data.data,
  });

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["inv", "withdrawals"] });
    qc.invalidateQueries({ queryKey: ["inv", "lots"] });
    // The store is lighter: the catalogue, the dashboard and the next order all
    // read from the same stock figure.
    qc.invalidateQueries({ queryKey: ["inv", "items"] });
    qc.invalidateQueries({ queryKey: ["inv", "dashboard"] });
  };

  const undo = useMutation({
    mutationFn: (id) => inventoryAPI.undoWithdrawal(id),
    onSuccess: (res) => {
      toast.success(res.data.message);
      refresh();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not undo that."),
  });

  const askUndo = async (row) => {
    const ok = await confirm({
      title: `Undo ${qty(row.quantity)} ${row.unit} of ${row.name}?`,
      message: "The quantity goes back onto the batches it came out of, and the entry is removed.",
      confirmText: "Undo it",
      tone: "danger",
    });
    if (ok) undo.mutate(row.id);
  };

  if (isLoading) return <PageLoader label="Loading the log…" />;

  const rows = data?.withdrawals ?? [];
  const totals = data?.totals ?? {};
  const byReason = totals.by_reason ?? {};

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="w-44">
          <Select value={month} onChange={(v) => setMonth(v || thisMonth())} options={monthOptions()} />
        </div>
        {!adding && (
          <button onClick={() => setAdding(true)} className={btn.primary}>
            <Plus className="h-4 w-4" /> Record a draw
          </button>
        )}
      </div>

      {adding && <DrawForm reasons={data?.reasons ?? []} onDone={() => { setAdding(false); refresh(); }} onCancel={() => setAdding(false)} />}

      {/* The same four questions, answered in the terms each account is shown.
          In money for the office and the master; in entries for the cook, who
          still needs to see that nine draws this month were things going in the
          bin. The split is the point of the card, not the figure. */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {canSeeMoney ? (
          <>
            <StatCard label="Out of the store" value={`$${money(totals.cost)}`} hint={`${totals.entries || 0} entries`} tone="navy" />
            <StatCard label="To the galley" value={`$${money(byReason.galley?.cost)}`} hint="What fed the crew" tone="good" />
            <StatCard
              label="Spoiled or expired"
              value={`$${money(byReason.waste?.cost)}`}
              hint="Thrown away"
              tone={Number(byReason.waste?.cost) > 0 ? "danger" : "default"}
            />
            <StatCard label="Landed or transferred" value={`$${money(byReason.transfer?.cost)}`} hint="Off the ship" />
          </>
        ) : (
          <>
            <StatCard label="Out of the store" value={totals.entries || 0} hint="draws this month" tone="navy" />
            <StatCard label="To the galley" value={byReason.galley?.entries || 0} hint="What fed the crew" tone="good" />
            <StatCard
              label="Spoiled or expired"
              value={byReason.waste?.entries || 0}
              hint="Thrown away"
              tone={Number(byReason.waste?.entries) > 0 ? "danger" : "default"}
            />
            <StatCard label="Landed or transferred" value={byReason.transfer?.entries || 0} hint="Off the ship" />
          </>
        )}
      </div>

      {rows.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
          <p className="text-sm font-semibold text-[#28364b]">Nothing recorded this month</p>
          <p className="mx-auto mt-1 max-w-md text-sm text-slate-400">
            Writing draws down as you make them gives you the detail. If you would rather just count the shelves, close
            the supply period instead — the difference is worked out and costed for you.
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                  <Th>Date</Th>
                  <Th>Item</Th>
                  <Th className="text-right">Quantity</Th>
                  {canSeeMoney && <Th className="text-right">Cost</Th>}
                  <Th>Why</Th>
                  <Th>By</Th>
                  <Th />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <tr key={row.id} className={row.reason === "waste" ? "bg-red-50/40" : ""}>
                    <Td className="whitespace-nowrap text-slate-500">{dateOf(row.date)}</Td>
                    <Td>
                      <span className="font-medium text-[#28364b]">{row.name}</span>
                      {row.note && <span className="ml-2 text-xs text-slate-400">{row.note}</span>}
                    </Td>
                    <Td className="whitespace-nowrap text-right font-medium">
                      {qty(row.quantity)} <span className="text-xs text-slate-400">{row.unit}</span>
                    </Td>
                    {/* A positive quantity that cost nothing did not come out
                        of free food — it came out of a batch nobody ever
                        costed, which on a ship that has just started keeping
                        batches is most of what is on the shelf. Printing
                        "$0.00" for that says the galley ate for free, and the
                        month's figures are then quietly wrong in a way nobody
                        can see. A dash says the one true thing: not known. */}
                    {canSeeMoney && (
                      <Td className="whitespace-nowrap text-right font-semibold text-[#28364b]">
                        {Number(row.cost) === 0 && Number(row.quantity) > 0 ? (
                          <span
                            className="font-normal text-slate-300"
                            title="Drawn from stock that was on board before batches were costed, so what it cost was never recorded"
                          >
                            —
                          </span>
                        ) : (
                          `$${money(row.cost)}`
                        )}
                      </Td>
                    )}
                    <Td>
                      <span
                        className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${
                          row.reason === "waste"
                            ? "bg-red-50 text-red-600"
                            : row.reason === "transfer"
                              ? "bg-slate-100 text-slate-500"
                              : "bg-emerald-50 text-emerald-700"
                        }`}
                      >
                        {row.reason_label}
                      </span>
                    </Td>
                    <Td className="whitespace-nowrap text-xs text-slate-400">{row.by || "—"}</Td>
                    <Td className="text-right">
                      <button
                        onClick={() => askUndo(row)}
                        title="Undo"
                        className="rounded-lg p-1.5 text-slate-300 transition-colors hover:bg-red-50 hover:text-red-600"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * A day's draw: several items, one save.
 *
 * A cook does not go to the store once per ingredient, so he should not have
 * to save once per ingredient either.
 */
function DrawForm({ reasons, onDone, onCancel }) {
  const [date, setDate] = useState(today());
  const [reason, setReason] = useState("galley");
  const [note, setNote] = useState("");
  const [lines, setLines] = useState([{ item_id: "", quantity: "" }]);

  // Only what is actually on board can be drawn, so only that is offered.
  const { data: groups } = useQuery({
    queryKey: ["inv", "items", "onboard"],
    queryFn: async () => (await inventoryAPI.items()).data.data.groups,
  });

  const stocked = useMemo(
    () =>
      (groups ?? [])
        .flatMap((g) => g.items)
        .filter((i) => Number(i.current_stock) > 0)
        .map((i) => ({
          value: i.id,
          label: `${i.name}${i.variant ? ` (${i.variant})` : ""} — ${qty(i.current_stock)} ${i.unit}`,
          unit: i.unit,
          stock: Number(i.current_stock),
        })),
    [groups]
  );

  const byId = useMemo(() => Object.fromEntries(stocked.map((o) => [String(o.value), o])), [stocked]);

  const save = useMutation({
    mutationFn: () =>
      inventoryAPI.withdraw({
        date,
        reason,
        note: note.trim() || null,
        lines: lines
          .filter((l) => l.item_id && Number(l.quantity) > 0)
          .map((l) => ({ item_id: Number(l.item_id), quantity: Number(l.quantity) })),
      }),
    onSuccess: (res) => {
      toast.success(res.data.message);
      onDone();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not record that."),
  });

  const set = (i, patch) => setLines((ls) => ls.map((l, n) => (n === i ? { ...l, ...patch } : l)));
  const ready = lines.some((l) => l.item_id && Number(l.quantity) > 0);

  // Flagged before saving: the API refuses it anyway, but being told at the
  // keyboard beats being told after pressing Save.
  const tooMuch = (line) => {
    const option = byId[String(line.item_id)];
    return option && Number(line.quantity) > option.stock;
  };

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate();
      }}
      className="rounded-xl border border-slate-200 bg-white p-5"
    >
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-sm font-bold text-[#28364b]">Record a draw</h2>
        <button type="button" onClick={onCancel} className="rounded p-1 text-slate-400 hover:text-[#28364b]">
          <X className="h-4 w-4" />
        </button>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Date</span>
          <DatePicker value={date} onChange={setDate} />
        </label>
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Why</span>
          <Select value={reason} onChange={setReason} options={reasons} className="w-full" />
        </label>
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Note</span>
          <input
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="Optional"
            className={`${inputCls} w-full`}
          />
        </label>
      </div>

      <div className="mt-4 space-y-2">
        {lines.map((line, i) => (
          <div key={i} className="flex items-start gap-2">
            <div className="min-w-0 flex-1">
              <Select
                value={line.item_id}
                onChange={(v) => set(i, { item_id: v })}
                options={stocked}
                placeholder="Choose an item"
                searchable
                searchPlaceholder="Search the store…"
                className="w-full"
              />
            </div>
            <div className="w-28 shrink-0">
              <input
                type="number"
                min="0"
                step="any"
                value={line.quantity}
                onChange={(e) => set(i, { quantity: e.target.value })}
                placeholder="Qty"
                className={`${numCls} w-full ${tooMuch(line) ? "border-red-300 focus:border-red-400 focus:ring-red-400" : ""}`}
              />
              {tooMuch(line) && (
                <span className="mt-1 block text-[11px] text-red-500">
                  only {qty(byId[String(line.item_id)].stock)} on board
                </span>
              )}
            </div>
            <span className="w-10 shrink-0 pt-2.5 text-xs text-slate-400">
              {byId[String(line.item_id)]?.unit || ""}
            </span>
            <button
              type="button"
              onClick={() => setLines((ls) => (ls.length === 1 ? ls : ls.filter((_, n) => n !== i)))}
              disabled={lines.length === 1}
              className="shrink-0 rounded-lg p-2 text-slate-300 transition-colors hover:bg-slate-100 hover:text-slate-600 disabled:opacity-40"
            >
              <Trash2 className="h-4 w-4" />
            </button>
          </div>
        ))}

        <button
          type="button"
          onClick={() => setLines((ls) => [...ls, { item_id: "", quantity: "" }])}
          className="text-xs font-semibold text-[#28364b] hover:underline"
        >
          + Another item
        </button>
      </div>

      <div className="mt-5 flex items-start gap-2 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-500">
        <Info className="mt-0.5 h-4 w-4 shrink-0 text-[#cebd88]" />
        <p>
          It comes off the batches on board for you — the oldest stock is spent first, at what that batch actually cost.
          All this needs from you is the quantity.
        </p>
      </div>

      <div className="mt-4 flex justify-end gap-2">
        <button type="button" onClick={onCancel} className={btn.ghost}>
          Cancel
        </button>
        <button type="submit" disabled={!ready || save.isLoading} className={btn.primary}>
          {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Record
        </button>
      </div>
    </form>
  );
}

/* ----------------------------- batches on board ---------------------------- */

/**
 * Every batch still on board, soonest to go off at the top.
 *
 * The same rows that cost a withdrawal answer the expiry question, which is
 * why there is no separate expiry list to keep in step with this one.
 */
function Batches() {
  const { money: canSeeMoney } = useAccess();
  const [within, setWithin] = useState(14);
  const [find, setFind] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "lots", within],
    queryFn: async () => (await inventoryAPI.stockLots({ within })).data.data,
  });

  const lots = data?.lots ?? [];

  // Above the early return, like every other hook in the module: one that runs
  // on some renders and not others is what put "Rendered more hooks than during
  // the previous render" in front of the user twice.
  const shown = useMemo(() => {
    const term = find.trim().toLowerCase();
    if (!term) return lots;

    return lots.filter(
      (l) =>
        (l.name || "").toLowerCase().includes(term) ||
        (l.code || "").toLowerCase().includes(term) ||
        (l.category || "").toLowerCase().includes(term)
    );
  }, [lots, find]);

  if (isLoading) return <PageLoader label="Loading the batches…" />;

  const s = data?.summary ?? {};

  return (
    <div className="space-y-5">
      {/* The counts are the board's job and stay for everybody — four batches
          going off is the warning. Only what they are worth comes off. */}
      <div className={`grid gap-4 sm:grid-cols-2 ${canSeeMoney ? "lg:grid-cols-4" : "lg:grid-cols-3"}`}>
        {canSeeMoney && (
          <StatCard label="Store value" value={`$${money(s.value)}`} hint={`${s.batches || 0} batches`} tone="navy" />
        )}
        <StatCard
          label="Expired"
          value={s.expired || 0}
          hint={canSeeMoney ? `$${money(s.expired_value)} to write off` : "write these off"}
          tone={s.expired > 0 ? "danger" : "default"}
          icon={AlertTriangle}
        />
        <StatCard
          label={`Within ${within} days`}
          value={s.expiring || 0}
          hint={canSeeMoney ? `$${money(s.expiring_value)} to use up` : "cook these first"}
          tone={s.expiring > 0 ? "low" : "default"}
          icon={CircleAlert}
        />
        <StatCard label="No date" value={s.undated || 0} hint="Keeps, or never recorded" icon={Clock} />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        {/* A full ship carries several hundred batches, so the board is read by
            looking something up rather than by scrolling. Filtered here rather
            than refetched: the rows are already in hand, and the cards above
            deliberately do not move — "2 going off" is a fact about the store,
            not about what is on screen. */}
        <div className="relative min-w-[240px] flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={find}
            onChange={(e) => setFind(e.target.value)}
            placeholder="Find an item by name or code"
            className={`${inputCls} w-full py-1.5 pl-9 pr-8`}
          />
          {find && (
            <button
              onClick={() => setFind("")}
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
              title="Clear"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          )}
        </div>

        <div className="flex items-center gap-2 text-xs text-slate-500">
          <span>Warn me</span>
          <div className="w-28">
            <Select
              value={within}
              onChange={(v) => setWithin(Number(v))}
              options={[
                { value: 7, label: "7 days" },
                { value: 14, label: "14 days" },
                { value: 30, label: "30 days" },
                { value: 60, label: "60 days" },
              ]}
              triggerClassName="px-2 py-1"
            />
          </div>
          <span>ahead</span>
        </div>

        {find && (
          <p className="text-xs text-slate-400">
            {shown.length} of {lots.length} {lots.length === 1 ? "batch" : "batches"}
          </p>
        )}
      </div>

      {shown.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
          {find ? (
            <>
              <p className="text-sm font-semibold text-[#28364b]">Nothing on board matches “{find}”</p>
              <p className="mx-auto mt-1 max-w-md text-sm text-slate-400">
                Only what is still on the shelf is listed here — an item drawn down to nothing has no batch left to
                show.
              </p>
            </>
          ) : (
            <>
              <p className="text-sm font-semibold text-[#28364b]">The store is empty</p>
              <p className="mx-auto mt-1 max-w-md text-sm text-slate-400">
                Batches appear here as deliveries are confirmed, each with the price it was bought at and the date it
                expires.
              </p>
            </>
          )}
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                  <Th>Item</Th>
                  <Th>Received</Th>
                  <Th>Expires</Th>
                  <Th className="text-right">On board</Th>
                  {canSeeMoney && <Th className="text-right">Cost each</Th>}
                  {canSeeMoney && <Th className="text-right">Value</Th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {shown.map((lot) => {
                  const expired = lot.days_left !== null && lot.days_left < 0;
                  const soon = lot.days_left !== null && lot.days_left >= 0 && lot.days_left <= within;

                  return (
                    <tr key={lot.id} className={expired ? "bg-red-50/50" : soon ? "bg-amber-50/40" : ""}>
                      <Td>
                        <span className="font-medium text-[#28364b]">{lot.name}</span>
                        {lot.source !== "receipt" && (
                          <span
                            title={lot.note || ""}
                            className="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500"
                          >
                            {lot.source === "opening" ? "Not booked in" : "Stocktake"}
                          </span>
                        )}
                      </Td>
                      <Td className="whitespace-nowrap text-slate-500">{dateOf(lot.received_on)}</Td>
                      {/* A date read off the packet and a date worked out from
                          the item's shelf life are both useful and they are not
                          the same claim. The second is marked, because somebody
                          deciding whether to throw food away should know which
                          of the two he is looking at. */}
                      <Td className="whitespace-nowrap">
                        {lot.expires_on ? (
                          <span className={expired ? "font-semibold text-red-600" : soon ? "font-semibold text-amber-600" : "text-slate-500"}>
                            {dateOf(lot.expires_on)}
                            <span className="ml-1.5 text-[11px]">
                              {expired
                                ? `(${Math.abs(lot.days_left)}d ago)`
                                : `(${lot.days_left}d)`}
                            </span>
                            {lot.expiry_estimated && (
                              <span
                                className="ml-1.5 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500"
                                title="Worked out from the item's shelf life — no date was recorded off the packet"
                              >
                                est
                              </span>
                            )}
                          </span>
                        ) : (
                          <span className="text-slate-300">—</span>
                        )}
                      </Td>
                      <Td className="whitespace-nowrap text-right font-medium">
                        {qty(lot.remaining)} <span className="text-xs text-slate-400">{lot.unit}</span>
                        {lot.remaining !== lot.quantity && (
                          <span className="ml-1 text-[11px] text-slate-300">of {qty(lot.quantity)}</span>
                        )}
                      </Td>
                      {/* Same rule as the log above: a batch opened without a
                          price is uncosted, not free. These are the opening
                          batches — stock counted onto the ship before anybody
                          was recording what it cost — and they read as dashes
                          until they are drawn down and replaced by deliveries
                          that carry a real price. */}
                      {canSeeMoney && (
                        <Td className="whitespace-nowrap text-right text-slate-500">
                          {Number(lot.unit_cost) > 0 ? (
                            `$${money(lot.unit_cost)}`
                          ) : (
                            <span className="text-slate-300" title="This batch was never costed">
                              —
                            </span>
                          )}
                        </Td>
                      )}
                      {canSeeMoney && (
                        <Td className="whitespace-nowrap text-right font-semibold text-[#28364b]">
                          {Number(lot.unit_cost) > 0 ? (
                            `$${money(lot.value)}`
                          ) : (
                            <span className="font-normal text-slate-300">—</span>
                          )}
                        </Td>
                      )}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500">
        <Info className="mt-0.5 h-4 w-4 shrink-0 text-[#cebd88]" />
        <p>
          To throw a batch away, record a draw for it with <strong>Spoiled or expired</strong> as the reason. It is
          charged at what that batch cost, not at the price of the fresh stock behind it.
        </p>
      </div>
    </div>
  );
}

/* --------------------------------- plumbing -------------------------------- */

function Th({ children, className = "" }) {
  return <th className={`px-4 py-2.5 text-left font-semibold ${className}`}>{children}</th>;
}

function Td({ children, className = "" }) {
  return <td className={`px-4 py-2.5 ${className}`}>{children}</td>;
}

/** The last 12 months, newest first. */
function monthOptions() {
  const out = [];
  const now = new Date();

  for (let i = 0; i < 12; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
    out.push({ value, label: d.toLocaleDateString("en-GB", { month: "long", year: "numeric" }) });
  }

  return out;
}
