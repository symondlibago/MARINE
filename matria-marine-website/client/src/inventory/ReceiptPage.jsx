import { useMemo, useState, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useRoute } from "wouter";
import { ArrowLeft, Save, Trash2, CheckCircle2, ClipboardList, Undo2, Search, PackageCheck } from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import { money, qty, dateOf, btn, inputCls, numCls } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";
import Select from "@/portal/ui/Select";
import DatePicker from "@/portal/ui/DatePicker";

const MONTHS = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December",
];

/**
 * One delivery: what was ordered, what actually arrived, and the difference.
 *
 * Confirming applies the received quantities to stock and records them as
 * supplied on the open period, so consumption stays measurable.
 */
export default function ReceiptPage() {
  const [, params] = useRoute("/receiving/:id");
  const id = params?.id;
  const qc = useQueryClient();
  const confirm = useConfirm();
  const { office, money: canSeeMoney } = useAccess();

  const [lines, setLines] = useState([]);
  const [head, setHead] = useState({ reference: "", received_date: "", supplier_id: null, notes: "" });
  const [picker, setPicker] = useState("");

  // The two shapes an expiry arrives in that are not already a date.
  const [bulkDays, setBulkDays] = useState("");
  const [bulkMonth, setBulkMonth] = useState("");
  const [bulkYear, setBulkYear] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "receipt", id],
    queryFn: async () => (await inventoryAPI.receipt(id)).data.data.receipt,
    enabled: !!id,
  });

  const { data: catalogue } = useQuery({
    queryKey: ["inv", "items"],
    queryFn: async () => (await inventoryAPI.items()).data.data,
    staleTime: 60_000,
  });

  // Office only, and not merely hidden from the crew: the API refuses a vessel
  // account the supplier list outright, so asking for it would be a 403 on a
  // screen that works perfectly well without one.
  const { data: suppliers } = useQuery({
    queryKey: ["inv", "suppliers"],
    queryFn: async () => (await inventoryAPI.suppliers()).data.data.suppliers,
    staleTime: 60_000,
    enabled: !!office,
  });

  useEffect(() => {
    if (!data) return;
    setHead({
      reference: data.reference || "",
      received_date: (data.received_date || "").slice(0, 10),
      supplier_id: data.supplier_id ?? null,
      notes: data.notes || "",
    });
    setLines(
      (data.items || []).map((l) => ({
        item_id: l.item_id,
        code: l.item?.code ?? "",
        name: l.item?.name ?? "Item",
        variant: l.item?.variant ?? "",
        unit: l.item?.unit ?? "",
        // What the ship asked for on the requisition, which is not always what
        // the office went on to buy. Null when there is no order behind this.
        asked_qty: l.asked_qty ?? null,
        ordered_qty: l.ordered_qty ?? 0,
        received_qty: l.received_qty ?? 0,
        unit_price: l.unit_price ?? "",
        expires_on: (l.expires_on || "").slice(0, 10),
      }))
    );
  }, [data]);

  const flat = useMemo(() => (catalogue?.groups ?? []).flatMap((g) => g.items), [catalogue]);

  const matches = useMemo(() => {
    const t = picker.trim().toLowerCase();
    if (t.length < 2) return [];
    const chosen = new Set(lines.map((l) => l.item_id));
    return flat
      .filter(
        (i) =>
          !chosen.has(i.id) &&
          (i.name.toLowerCase().includes(t) || i.code.toLowerCase().includes(t) || (i.variant || "").toLowerCase().includes(t))
      )
      .slice(0, 8);
  }, [picker, flat, lines]);

  const confirmed = data?.status === "confirmed";

  const addLine = (item) => {
    setLines((l) => [
      ...l,
      {
        item_id: item.id,
        code: item.code,
        name: item.name,
        variant: item.variant,
        unit: item.unit,
        // Nothing was ordered: this line is something that turned up, or a
        // delivery raised by hand. Zero reads as "not on the order" below, so
        // the Difference column stays quiet instead of flagging every line as
        // an over-delivery. The count starts blank for the same reason — a
        // suggested quantity in the Received box is a number nobody counted.
        ordered_qty: 0,
        received_qty: "",
        unit_price: item.unit_price ?? "",
        expires_on: "",
      },
    ]);
    setPicker("");
  };

  const setLine = (i, patch) => setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...patch } : row)));
  const dropLine = (i) => setLines((l) => l.filter((_, idx) => idx !== i));

  /** "Keeps for N days" counted from the delivery date, onto every line. */
  const applyDays = () => {
    const days = Number(bulkDays);
    if (!(days > 0) || !head.received_date) return;

    const d = new Date(`${head.received_date}T00:00:00`);
    d.setDate(d.getDate() + days);
    const iso = d.toISOString().slice(0, 10);

    setLines((l) => l.map((row) => ({ ...row, expires_on: iso })));
    toast.success(`Every line set to ${dateOf(iso)}.`);
  };

  /**
   * "Best before 12/2027" onto every line.
   *
   * Dated to the LAST day of the month. That is what a month-and-year label
   * means — the food is good through December, not on the 1st — and dating it
   * to the 1st would have the ship throwing away four good weeks of stock on
   * the strength of a rounding choice.
   */
  const applyMonth = () => {
    const m = Number(bulkMonth);
    const y = Number(bulkYear);
    if (!(m >= 1 && m <= 12) || !(y >= 2020)) return;

    // Day 0 of the next month is the last day of this one, leap years included.
    const last = new Date(Date.UTC(y, m, 0));
    const iso = last.toISOString().slice(0, 10);

    setLines((l) => l.map((row) => ({ ...row, expires_on: iso })));
    toast.success(`Every line set to ${dateOf(iso)}.`);
  };

  const save = useMutation({
    mutationFn: () =>
      inventoryAPI.updateReceipt(id, {
        reference: head.reference || null,
        received_date: head.received_date || null,
        // Left out entirely on board, rather than sent as null: the server
        // keeps whatever the office recorded when the key is absent, so a cook
        // correcting a quantity does not blank the chandler's name.
        ...(office ? { supplier_id: head.supplier_id || null } : {}),
        notes: head.notes || null,
        items: lines.map((l) => ({
          item_id: l.item_id,
          ordered_qty: l.ordered_qty === "" ? 0 : Number(l.ordered_qty),
          received_qty: l.received_qty === "" ? 0 : Number(l.received_qty),
          unit_price: l.unit_price === "" ? null : Number(l.unit_price),
          expires_on: l.expires_on || null,
        })),
      }),
    onSuccess: () => {
      toast.success("Delivery saved.");
      qc.invalidateQueries({ queryKey: ["inv", "receipt", id] });
      qc.invalidateQueries({ queryKey: ["inv", "receipts"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save."),
  });

  const doConfirm = useMutation({
    mutationFn: () => inventoryAPI.confirmReceipt(id),
    onSuccess: (res) => {
      toast.success(res.data.message);
      qc.invalidateQueries({ queryKey: ["inv", "receipt", id] });
      qc.invalidateQueries({ queryKey: ["inv", "items"] });
      qc.invalidateQueries({ queryKey: ["inv", "dashboard"] });
      qc.invalidateQueries({ queryKey: ["inv", "requisition-preview"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not confirm."),
  });

  const doReopen = useMutation({
    mutationFn: () => inventoryAPI.reopenReceipt(id),
    onSuccess: (res) => {
      toast.success(res.data.message);
      qc.invalidateQueries({ queryKey: ["inv", "receipt", id] });
      qc.invalidateQueries({ queryKey: ["inv", "items"] });
      qc.invalidateQueries({ queryKey: ["inv", "dashboard"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not reopen."),
  });

  const askConfirm = async () => {
    const ok = await confirm({
      title: "Confirm delivery",
      message: "The received quantities will be added to the stock on board. Save any edits first.",
      confirmText: "Confirm delivery",
    });
    if (ok) doConfirm.mutate();
  };

  const askReopen = async () => {
    const ok = await confirm({
      title: "Reopen delivery",
      message: "This takes the received quantities back out of stock so the delivery can be corrected.",
      confirmText: "Reopen",
      tone: "danger",
    });
    if (ok) doReopen.mutate();
  };

  if (isLoading) return <PageLoader label="Loading delivery…" />;

  const totalValue = lines.reduce((n, l) => n + Number(l.received_qty || 0) * Number(l.unit_price || 0), 0);

  // The Asked column only appears when the office actually changed something.
  // On the ordinary delivery — which is nearly all of them — asked and bought
  // are the same figure, and a column of matching pairs is noise in the way of
  // the one that is being typed into.
  const trimmed = lines.filter(
    (l) => l.asked_qty !== null && l.asked_qty !== undefined
      && Number(l.asked_qty) !== Number(l.ordered_qty || 0)
  );
  const showAsked = trimmed.length > 0;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Link href="/receiving" className="rounded-lg p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]">
            <ArrowLeft className="h-4 w-4" />
          </Link>
          <div>
            <h1 className="text-xl font-bold text-[#28364b]">{data?.reference || `Delivery #${id}`}</h1>
            <p className="text-sm text-slate-500">
              {confirmed ? "Confirmed — stock has been updated." : "Draft — nothing has been added to stock yet."}
              {data?.requisition?.req_number ? ` · against ${data.requisition.req_number}` : ""}
            </p>
          </div>
        </div>
        <div className="flex gap-2">
          {confirmed ? (
            <button onClick={askReopen} disabled={doReopen.isLoading} className={btn.danger}>
              {doReopen.isLoading ? <Spinner className="h-4 w-4" /> : <Undo2 className="h-4 w-4" />} Reopen
            </button>
          ) : (
            <>
              <button onClick={() => save.mutate()} disabled={save.isLoading} className={btn.ghost}>
                {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
              </button>
              <button onClick={askConfirm} disabled={doConfirm.isLoading || !lines.length} className={btn.primary}>
                {doConfirm.isLoading ? <Spinner className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />} Confirm &amp; add to stock
              </button>
            </>
          )}
        </div>
      </div>

      {/* Delivery details. Who supplied it is the office's half of the record
          and the ship is not shown it — she records what arrived and the
          reference on the delivery note, which is what she can vouch for. */}
      <div className={`grid gap-4 rounded-xl border border-slate-200 bg-white p-4 ${office ? "sm:grid-cols-4" : "sm:grid-cols-3"}`}>
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Reference</span>
          <input
            value={head.reference}
            disabled={confirmed}
            onChange={(e) => setHead((h) => ({ ...h, reference: e.target.value }))}
            placeholder="Supplier DO / invoice no."
            className={`${inputCls} w-full disabled:bg-slate-50`}
          />
        </label>
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Received on</span>
          <DatePicker
            value={head.received_date}
            onChange={(v) => !confirmed && setHead((h) => ({ ...h, received_date: v }))}
            placeholder="Select date"
          />
        </label>
        {office && (
          <label className="block">
            <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Supplier</span>
            <Select
              value={head.supplier_id ?? ""}
              onChange={(v) => !confirmed && setHead((h) => ({ ...h, supplier_id: v ? Number(v) : null }))}
              options={[
                { value: "", label: "—" },
                ...(suppliers ?? []).map((s) => ({ value: s.id, label: s.name })),
              ]}
              placeholder="—"
            />
          </label>
        )}
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Note</span>
          <input
            value={head.notes}
            disabled={confirmed}
            onChange={(e) => setHead((h) => ({ ...h, notes: e.target.value }))}
            className={`${inputCls} w-full disabled:bg-slate-50`}
          />
        </label>
      </div>

      {/* Add lines */}
      {!confirmed && (
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={picker}
            onChange={(e) => setPicker(e.target.value)}
            placeholder="Add an item — type at least 2 letters of its name or code"
            className={`${inputCls} w-full pl-9`}
          />
          {matches.length > 0 && (
            <div className="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
              {matches.map((m) => (
                <button
                  key={m.id}
                  onClick={() => addLine(m)}
                  className="flex w-full items-center justify-between gap-3 px-4 py-2 text-left text-sm transition-colors hover:bg-slate-50"
                >
                  <span>
                    <span className="font-medium text-[#28364b]">{m.name}</span>
                    {m.variant && <span className="ml-1 text-xs text-slate-400">{m.variant}</span>}
                    <span className="ml-2 font-mono text-[11px] text-slate-400">{m.code}</span>
                  </span>
                  <span className="shrink-0 text-xs text-slate-400">
                    stock {qty(m.current_stock)} {m.unit}
                    {m.order_qty > 0 ? ` · order ${qty(m.order_qty)}` : ""}
                  </span>
                </button>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Expiry, for a whole delivery at once.
          A packet gives its date in one of three ways and a cook at the
          gangway should not have to convert between them: some carry a full
          date, plenty carry only a month and year, and short-life goods carry
          a duration — "use within 28 days". Typing the resulting date into
          seven boxes by hand is how deliveries end up undated, which is how
          food ends up thrown away. Each of these fills every line at once; a
          line whose packet disagrees is still overtyped below. */}
      {!confirmed && lines.length > 0 && (
        <div className="flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white px-4 py-3">
          <div>
            <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
              Set every expiry
            </span>
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-xs text-slate-500">Keeps for</span>
              <input
                type="number"
                min="1"
                max="3650"
                value={bulkDays}
                onChange={(e) => setBulkDays(e.target.value)}
                placeholder="28"
                className={`${numCls} w-20 py-1.5`}
              />
              <span className="text-xs text-slate-500">days from</span>
              <span className="text-xs font-semibold text-[#28364b]">
                {head.received_date ? dateOf(head.received_date) : "the delivery date"}
              </span>
              <button
                onClick={() => applyDays()}
                disabled={!(Number(bulkDays) > 0) || !head.received_date}
                className={`${btn.ghost} !py-1.5 !text-xs`}
              >
                Apply
              </button>
            </div>
          </div>

          <div className="h-8 w-px bg-slate-200" />

          <div>
            <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
              Or best before
            </span>
            <div className="flex flex-wrap items-center gap-2">
              <select
                value={bulkMonth}
                onChange={(e) => setBulkMonth(e.target.value)}
                className={`${inputCls} py-1.5`}
              >
                <option value="">Month</option>
                {MONTHS.map((m, i) => (
                  <option key={m} value={String(i + 1)}>
                    {m}
                  </option>
                ))}
              </select>
              <input
                type="number"
                min="2020"
                max="2100"
                value={bulkYear}
                onChange={(e) => setBulkYear(e.target.value)}
                placeholder="2027"
                className={`${numCls} w-24 py-1.5`}
              />
              <button
                onClick={() => applyMonth()}
                disabled={!bulkMonth || !(Number(bulkYear) >= 2020)}
                className={`${btn.ghost} !py-1.5 !text-xs`}
              >
                Apply
              </button>
              {/* A packet that says only "12/2027" is good to the end of that
                  month — that is what the label means, and dating it to the
                  1st would throw away four good weeks. */}
              <span className="text-[11px] text-slate-400">dates to the last day of the month</span>
            </div>
          </div>
        </div>
      )}

      {/* Lines */}
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[980px] text-sm">
            <thead>
              <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2 font-semibold">Item</th>
                <th className="px-2 py-2 font-semibold">Unit</th>
                {showAsked && (
                  <th
                    className="px-2 py-2 text-right font-semibold"
                    title="What the ship asked for on the requisition"
                  >
                    Asked
                  </th>
                )}
                <th
                  className="px-2 py-2 text-right font-semibold"
                  title={showAsked ? "What the office actually bought, off the quotation" : undefined}
                >
                  {showAsked ? "Bought" : "Ordered"}
                </th>
                <th className="px-2 py-2 text-right font-semibold">Received</th>
                <th className="px-2 py-2 text-right font-semibold">Difference</th>
                {canSeeMoney && <th className="px-2 py-2 text-right font-semibold">Unit price</th>}
                {/* Taken off the delivery note while the goods are in front of
                    you. It travels onto the batch when this is confirmed, and
                    is what the expiry board reads. */}
                <th className="px-2 py-2 font-semibold">Expires</th>
                {canSeeMoney && <th className="px-4 py-2 text-right font-semibold">Value</th>}
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {lines.map((l, i) => {
                // Zero ordered means this line was not on an order, so there is
                // nothing to compare the count against and the difference is
                // not a shortfall — it is simply unknown.
                const ordered = Number(l.ordered_qty || 0);
                const diff = ordered > 0 ? Number(l.received_qty || 0) - ordered : null;
                // This line was trimmed ashore: the ship asked for one figure
                // and the office bought another.
                const cut =
                  l.asked_qty !== null && l.asked_qty !== undefined && Number(l.asked_qty) !== ordered;
                const priced = l.unit_price !== "" && l.unit_price !== null && l.unit_price !== undefined;
                return (
                  <tr key={`${l.item_id}-${i}`} className="border-t border-slate-100">
                    <td className="px-4 py-2">
                      <span className="font-medium text-[#28364b]">{l.name}</span>
                      {l.variant && <span className="ml-1 text-xs text-slate-400">{l.variant}</span>}
                      <span className="ml-2 font-mono text-[11px] text-slate-400">{l.code}</span>
                    </td>
                    <td className="px-2 py-2 text-xs text-slate-500">{l.unit}</td>
                    {/* Both read-only on purpose. What was asked for is the
                        requisition's figure and what was bought is the
                        quotation's; neither is settled at the gangway. The
                        moment Bought can be typed the Difference column stops
                        meaning anything, because a short delivery is tidied
                        away by editing it down to match. */}
                    {showAsked && (
                      <td className="px-2 py-2 text-right text-slate-400">
                        {l.asked_qty === null || l.asked_qty === undefined ? (
                          <span className="text-slate-300">—</span>
                        ) : (
                          <span className={cut ? "line-through" : ""}>{qty(l.asked_qty)}</span>
                        )}
                      </td>
                    )}
                    <td className="px-2 py-2 text-right text-slate-600">
                      {ordered > 0 ? qty(ordered) : <span className="text-slate-300">—</span>}
                    </td>
                    <td className="px-2 py-2 text-right">
                      <input
                        type="number"
                        min="0"
                        step="any"
                        disabled={confirmed}
                        value={l.received_qty}
                        onChange={(e) => setLine(i, { received_qty: e.target.value })}
                        className={`${numCls} w-24 py-1 disabled:bg-slate-50`}
                      />
                    </td>
                    <td
                      className={`px-2 py-2 text-right text-xs font-semibold ${
                        diff === null ? "text-slate-300" : diff < 0 ? "text-red-600" : diff > 0 ? "text-amber-600" : "text-slate-400"
                      }`}
                    >
                      {diff === null || diff === 0 ? "—" : `${diff > 0 ? "+" : ""}${qty(diff)}`}
                    </td>
                    {/* The price came off the quotation the office sent back —
                        it is what the ship agreed to pay, not something the
                        galley settles at the gangway. The office can still
                        correct a line against the invoice. The cook sees no
                        column at all, and the batch is still priced: the server
                        fills it from the same quotation when the sheet is
                        started. */}
                    {canSeeMoney && (
                      <td className="px-2 py-2 text-right">
                        {office && !confirmed ? (
                          <input
                            type="number"
                            min="0"
                            step="any"
                            value={l.unit_price ?? ""}
                            onChange={(e) => setLine(i, { unit_price: e.target.value })}
                            className={`${numCls} w-24 py-1`}
                          />
                        ) : (
                          <span className="text-slate-600">
                            {priced ? money(l.unit_price) : <span className="text-slate-300">—</span>}
                          </span>
                        )}
                      </td>
                    )}
                    <td className="px-2 py-2">
                      {confirmed ? (
                        <span className="whitespace-nowrap text-xs text-slate-500">
                          {l.expires_on ? dateOf(l.expires_on) : "—"}
                        </span>
                      ) : (
                        <div className="w-36">
                          <DatePicker
                            value={l.expires_on || ""}
                            onChange={(v) => setLine(i, { expires_on: v })}
                            placeholder="Optional"
                          />
                        </div>
                      )}
                    </td>
                    {canSeeMoney && (
                      <td className="px-4 py-2 text-right text-slate-600">
                        {money(Number(l.received_qty || 0) * Number(l.unit_price || 0))}
                      </td>
                    )}
                    <td className="px-3 py-2 text-right">
                      {!confirmed && (
                        <button
                          onClick={() => dropLine(i)}
                          className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
              {!lines.length && (
                <tr>
                  <td colSpan={(canSeeMoney ? 9 : 7) + (showAsked ? 1 : 0)} className="px-4 py-16 text-center text-sm text-slate-400">
                    <PackageCheck className="mx-auto mb-2 h-8 w-8 text-slate-200" />
                    No lines yet — search above to add what arrived.
                  </td>
                </tr>
              )}
            </tbody>
            {canSeeMoney && lines.length > 0 && (
              <tfoot>
                <tr className="border-t-2 border-[#28364b] bg-slate-50">
                  <td colSpan={7 + (showAsked ? 1 : 0)} className="px-4 py-3 text-right text-sm font-bold text-[#28364b]">
                    DELIVERY VALUE
                  </td>
                  <td className="px-4 py-3 text-right text-base font-bold text-[#28364b]">${money(totalValue)}</td>
                  <td />
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      </div>

      {/* The order was trimmed ashore. Said plainly, on the ship's own screen,
          because otherwise the only trace of it on board is a Bought column
          quietly smaller than the order the master signed — and the first
          anybody would know is a cook wondering why half his apples never came.
          The office's reason is not here; that it happened, and to which lines,
          is. */}
      {showAsked && (
        <div className="flex items-start gap-2.5 rounded-xl border border-[#cebd88] bg-[#cebd88]/10 px-4 py-3 text-xs text-[#28364b]">
          <ClipboardList className="mt-0.5 h-4 w-4 shrink-0 text-[#cebd88]" />
          <p>
            <span className="font-semibold">
              {trimmed.length} {trimmed.length === 1 ? "line was" : "lines were"} changed between what the ship asked
              for and what the office bought.
            </span>{" "}
            Count the delivery against <span className="font-semibold">Bought</span> — that is what was actually
            ordered from the chandler, so the Difference column shows a real shortfall rather than the trim.
          </p>
        </div>
      )}

      {/* This used to promise that a price typed here became the item's price
          in the catalogue. It no longer does, and should never have: a 3 kg
          top-up at a one-off price was rewriting the price of the stock already
          on the shelf. A delivery now prices its own batch and nothing else. */}
      {!confirmed && lines.length > 0 && (
        <p className="text-xs text-slate-400">
          {office
            ? "Ordered comes from the order, the price from the quotation. Correct a price if the invoice disagrees — it becomes the cost of this batch on board and nothing else."
            : "Ordered was settled ashore, and so was the price. Count what arrived and put it under Received — that is the only figure this sheet needs from you."}
        </p>
      )}
    </div>
  );
}
