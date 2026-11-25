import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Pencil, Plus, Receipt, Save, Search, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import { qty, money, btn, inputCls, numCls } from "./lib";
import { Spinner } from "@/portal/ui/Loading";

/**
 * What is actually on a saved order, and — while it is still the ship's — a way
 * to change it.
 *
 * Fetched only when a row is opened, because 225 lines belong to one order out
 * of a list of them and nobody wants the other four loaded to look at one. The
 * snapshot the order was saved with is what shows — the description, the unit
 * and the stock as it stood that day — so an order read a month later says what
 * was asked for then, not what the catalogue says now.
 *
 * "Stock when asked" is that snapshot, and it is the reason this panel is worth
 * reading rather than counting: a line asking for 20 Kg means one thing against
 * an empty shelf and quite another against a full one, and by the time anybody
 * reads the order the shelf has moved. Frozen on the line at save time.
 *
 * Editing is offered only on a draft or an order the master sent back, which is
 * what the server means by editable. Once it is with the master it is his to
 * answer, and a cook who could still edit it could change what was signed for
 * after it was signed. The button is simply absent otherwise — the API refuses
 * it either way, but a button that always fails is worse than no button.
 *
 * Two different things are true about money here, and the order of them is the
 * whole point.
 *
 * BEFORE the office has quoted, nobody on board sees a price. A requisition's
 * unit_price is only ever the office's standing figure from an old quotation,
 * and the next thing that happens to an approved order is that it goes to
 * vendors to ask what things cost — so a total at this stage is a guess
 * assembled from whichever lines happened to carry a stale price, which is
 * worse than no figure at all.
 *
 * AFTER the office has quoted, the master sees the quoted prices, the total and
 * whether it fits the budget. That is not a guess: it is what the vessel will
 * be invoiced. He approved the order and he answers for the spend, so showing
 * him the order and hiding what it came to left him with nothing to push back
 * on — which is exactly when pushing back is worth something, because the
 * quantities can still be cut before anybody buys anything.
 *
 * The cook sees neither, at either stage.
 */
export default function OrderLines({ id }) {
  const qc = useQueryClient();
  const { money: canSeeMoney } = useAccess();
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState([]);   // [{ item_id, code, description, unit, order_qty }]
  const [picker, setPicker] = useState("");

  const { data, isLoading, isError } = useQuery({
    queryKey: ["inv", "requisition", id],
    queryFn: async () => (await inventoryAPI.requisition(id)).data.data,
    staleTime: 30_000,
  });

  // The catalogue, for adding something that is not on the order yet. Only
  // loaded once editing starts: it is ~900 rows and reading an order needs
  // none of them.
  const { data: catalogue } = useQuery({
    queryKey: ["inv", "items"],
    queryFn: async () => (await inventoryAPI.items()).data.data,
    staleTime: 60_000,
    enabled: editing,
  });

  const flat = useMemo(() => (catalogue?.groups ?? []).flatMap((g) => g.items), [catalogue]);

  // Every hook has to run on every render, so this sits above the early
  // returns below rather than down beside the search box it feeds.
  const matches = useMemo(() => {
    const term = picker.trim().toLowerCase();
    if (term.length < 2) return [];

    return flat
      .filter((i) => i.name.toLowerCase().includes(term) || i.code.toLowerCase().includes(term))
      .slice(0, 8);
  }, [picker, flat]);

  const save = useMutation({
    mutationFn: () =>
      inventoryAPI.updateRequisition(id, {
        lines: draft
          .filter((l) => Number(l.order_qty) > 0)
          .map((l) => ({ item_id: l.item_id, order_qty: Number(l.order_qty) })),
      }),
    onSuccess: (res) => {
      toast.success(res.data.message || "Order updated.");
      setEditing(false);
      qc.invalidateQueries({ queryKey: ["inv", "requisition", id] });
      qc.invalidateQueries({ queryKey: ["inv", "requisitions"] });
      qc.invalidateQueries({ queryKey: ["inv", "requisition-preview"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the changes."),
  });

  if (isLoading) {
    return (
      <p className="flex items-center gap-2 py-2 text-xs text-slate-400">
        <Spinner className="h-3.5 w-3.5" /> Loading the items…
      </p>
    );
  }

  if (isError) {
    return <p className="py-2 text-xs text-red-600">Could not load the items on this order.</p>;
  }

  const req = data?.requisition;
  const saved = req?.items ?? [];
  const editable = !!data?.editable;

  // Null until the office has quoted, and null for the cook always. Priced by
  // the ship's own catalogue code, so a line the quotation did not carry simply
  // has no figure rather than a wrong one.
  const quote = canSeeMoney ? data?.quotation ?? null : null;
  const priceOf = (line) => (quote ? quote.prices?.[line.code] ?? null : null);
  const showQuoted = !!quote && !editing;

  const startEdit = () => {
    setDraft(
      saved.map((l) => ({
        item_id: l.item_id,
        code: l.code,
        description: l.description,
        unit: l.unit,
        order_qty: l.order_qty,
      }))
    );
    setPicker("");
    setEditing(true);
  };

  const addItem = (item) => {
    setPicker("");
    if (draft.some((l) => l.item_id === item.id)) {
      toast.info(`${item.name} is already on this order.`);
      return;
    }
    setDraft((d) => [
      ...d,
      {
        item_id: item.id,
        code: item.code,
        description: [item.name, item.variant].filter(Boolean).join(" "),
        unit: item.unit,
        // What the calculator would ask for, so the usual case is one click.
        order_qty: item.order_qty > 0 ? item.order_qty : 1,
      },
    ]);
  };

  const rows = editing ? draft : saved;

  if (!rows.length && !editing) {
    return <p className="py-2 text-xs text-slate-400">This order has no lines on it.</p>;
  }

  // Code, item, unit, ordered, and the bin — the two snapshot columns are
  // hidden while editing, because they are not what is being edited.
  const columns = 5;

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-end gap-2">
        {editable && !editing && (
          <button onClick={startEdit} className={`${btn.ghost} !py-1.5 !text-[11px]`}>
            <Pencil className="h-3.5 w-3.5" /> Edit this order
          </button>
        )}
        {editing && (
          <>
            <button onClick={() => setEditing(false)} className={`${btn.ghost} !py-1.5 !text-[11px]`}>
              <X className="h-3.5 w-3.5" /> Cancel
            </button>
            <button
              onClick={() => save.mutate()}
              disabled={save.isLoading || !draft.some((l) => Number(l.order_qty) > 0)}
              className={`${btn.primary} !py-1.5 !text-[11px]`}
            >
              {save.isLoading ? <Spinner className="h-3.5 w-3.5" /> : <Save className="h-3.5 w-3.5" />}
              Save changes
            </button>
          </>
        )}
      </div>

      {/* Add an item that is not on the order yet. */}
      {editing && (
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
          <input
            value={picker}
            onChange={(e) => setPicker(e.target.value)}
            placeholder="Add an item — type at least 2 letters of its name or code"
            className={`${inputCls} w-full py-1.5 pl-9 text-xs`}
          />
          {matches.length > 0 && (
            <div className="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
              {matches.map((item) => (
                <button
                  key={item.id}
                  onClick={() => addItem(item)}
                  className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-xs transition-colors hover:bg-slate-50"
                >
                  <span className="min-w-0 truncate">
                    <span className="font-mono text-slate-400">{item.code}</span>{" "}
                    <span className="text-[#28364b]">{item.name}</span>
                    {item.variant && <span className="ml-1 text-slate-400">{item.variant}</span>}
                  </span>
                  <span className="shrink-0 text-slate-400">
                    {qty(item.current_stock)} {item.unit} on board
                  </span>
                </button>
              ))}
            </div>
          )}
        </div>
      )}

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-white text-left text-[10px] uppercase tracking-wide text-slate-400">
              <th className="px-3 py-2 font-semibold">Code</th>
              <th className="px-3 py-2 font-semibold">Item</th>
              <th className="px-2 py-2 font-semibold">Unit</th>
              {!editing && (
                <>
                  <th
                    className="px-2 py-2 text-right font-semibold"
                    title="What was on board the day this order was written — frozen then, not today's figure"
                  >
                    Stock when asked
                  </th>
                  <th
                    className="px-2 py-2 text-right font-semibold"
                    title="What the ship should have had for the period this order covers"
                  >
                    Target
                  </th>
                </>
              )}
              <th className="px-2 py-2 text-right font-semibold">Ordered</th>
              {showQuoted && (
                <>
                  <th className="px-2 py-2 text-right font-semibold" title={`As quoted on ${quote.number}`}>
                    Quoted
                  </th>
                  <th className="px-3 py-2 text-right font-semibold">Line total</th>
                </>
              )}
              {editing && <th className="px-3 py-2" />}
            </tr>
          </thead>
          <tbody>
            {rows.map((l, i) => (
              <tr key={l.item_id ?? l.id} className="border-t border-slate-100">
                <td className="whitespace-nowrap px-3 py-1.5 font-mono text-xs text-slate-400">{l.code}</td>
                <td className="px-3 py-1.5 text-[#28364b]">{l.description}</td>
                <td className="px-2 py-1.5 text-xs text-slate-500">{l.unit}</td>

                {!editing && (
                  <>
                    <td className="px-2 py-1.5 text-right text-slate-500">{qty(l.rob_at_issue)}</td>
                    <td className="px-2 py-1.5 text-right text-slate-500">{qty(l.target_qty)}</td>
                  </>
                )}

                <td className="px-2 py-1.5 text-right">
                  {editing ? (
                    <input
                      type="number"
                      min="0"
                      step="any"
                      value={l.order_qty}
                      onChange={(e) =>
                        setDraft((d) => d.map((row, idx) => (idx === i ? { ...row, order_qty: e.target.value } : row)))
                      }
                      onFocus={(e) => e.target.select()}
                      className={`${numCls} w-20 px-2 py-1`}
                    />
                  ) : (
                    <span className="font-semibold text-[#28364b]">{qty(l.order_qty)}</span>
                  )}
                </td>

                {/* What the vendors charged for this line, and what it comes
                    to at the quantity ordered. A line the quotation did not
                    carry reads as a dash — not as nothing. */}
                {showQuoted && (
                  <>
                    <td className="whitespace-nowrap px-2 py-1.5 text-right text-slate-500">
                      {priceOf(l) === null ? <span className="text-slate-300">—</span> : money(priceOf(l))}
                    </td>
                    <td className="whitespace-nowrap px-3 py-1.5 text-right font-semibold text-[#28364b]">
                      {priceOf(l) === null ? (
                        <span className="font-normal text-slate-300">—</span>
                      ) : (
                        money(Number(l.order_qty || 0) * Number(priceOf(l)))
                      )}
                    </td>
                  </>
                )}

                {editing && (
                  <td className="px-3 py-1.5 text-right">
                    <button
                      onClick={() => setDraft((d) => d.filter((_, idx) => idx !== i))}
                      className="rounded-lg p-1 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                      title="Take this line off the order"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  </td>
                )}
              </tr>
            ))}

            {editing && !rows.length && (
              <tr>
                <td colSpan={columns} className="px-3 py-6 text-center text-xs text-slate-400">
                  Nothing on the order — search above to put something back on it.
                </td>
              </tr>
            )}
          </tbody>
          {!editing && (
            <tfoot>
              <tr className="border-t-2 border-slate-200 bg-slate-50">
                <td colSpan={6} className="px-3 py-2 text-right text-xs font-bold text-[#28364b]">
                  {saved.length} {saved.length === 1 ? "line" : "lines"} over {req.days_of_supply} days
                </td>
                {showQuoted && (
                  <td colSpan={2} className="whitespace-nowrap px-3 py-2 text-right text-xs font-bold text-[#28364b]">
                    {quote.total === null ? "" : `$${money(quote.total)}`}
                  </td>
                )}
              </tr>
            </tfoot>
          )}
        </table>

        {/* Where the figures came from, and whether the ship can afford them.
            Named, because numbers that appear without a source read as the
            system's opinion rather than as a vendor's price. The grand total
            is the invoice figure — lines plus delivery and tax — so it will
            not be the sum of the column above it, and saying so here saves
            somebody checking the arithmetic by hand. */}
        {showQuoted && (
          <div
            className={`flex flex-wrap items-start gap-2 border-t px-3 py-2 text-[11px] ${
              quote.budget?.level === "over"
                ? "border-red-200 bg-red-50 text-red-800"
                : "border-slate-100 bg-white text-slate-500"
            }`}
          >
            <Receipt className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>
              Quoted on <span className="font-semibold">{quote.number}</span>
              {quote.status ? ` (${quote.status})` : ""}
              {quote.total === null ? "" : ` — $${money(quote.total)} ${quote.currency ?? ""} to pay, including delivery and tax.`}
              {quote.budget?.message ? ` ${quote.budget.message}` : ""}
            </span>
          </div>
        )}

        {req?.notes && !editing && (
          <p className="border-t border-slate-100 px-3 py-2 text-xs text-slate-500">
            <span className="font-semibold text-[#28364b]">Note:</span> {req.notes}
          </p>
        )}
      </div>

      {editing && (
        <p className="flex items-start gap-2 rounded-lg bg-white px-3 py-2 text-[11px] text-slate-500">
          <Plus className="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#cebd88]" />
          <span>
            Saving rewrites the order and scores every line again against the stock on board today, so "stock when
            asked" moves to now. Send it up again when it is right.
          </span>
        </p>
      )}
    </div>
  );
}
