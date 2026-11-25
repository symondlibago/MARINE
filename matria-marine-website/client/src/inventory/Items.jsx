import { useMemo, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Search,
  ChevronRight,
  Plus,
  Pencil,
  Trash2,
  Boxes,
  AlertTriangle,
  ShoppingCart,
  Wallet,
  X,
  Save,
} from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import { StatusPill, StatCard, money, qty, statusOf, inputCls, numCls, btn } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";
import Modal from "@/portal/ui/Modal";
import Select from "@/portal/ui/Select";

const FILTERS = ["All", "ORDER NOW", "LOW", "GOOD"];

const blankItem = () => ({
  id: null,
  code: "",
  category_id: null,
  name: "",
  variant: "",
  unit: "Kg",
  unit_price: "",
  shelf_life_days: "",
  current_stock: "",
  manual_target: "",
  notes: "",
});

/**
 * The main working screen.
 *
 * The whole catalogue is fetched once and searched in the browser, so typing
 * feels instant on ~900 rows. Every number shown — target, order quantity,
 * value, status — comes from the backend; nothing is recalculated here.
 */
export default function Items() {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const { office, money: canSeeMoney } = useAccess();

  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState("All");
  const [openGroups, setOpenGroups] = useState({});
  const [drafts, setDrafts] = useState({});     // itemId -> typed ROB, before saving
  const [saving, setSaving] = useState({});     // itemId -> true while in flight
  const [form, setForm] = useState(null);       // add / edit item modal
  const [asking, setAsking] = useState(null);   // "order some of this anyway"

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "items"],
    queryFn: async () => (await inventoryAPI.items()).data.data,
    staleTime: 30_000,
  });

  /**
   * "Order some of this anyway."
   *
   * The requisition lists what is below target, which is the honest default
   * and not the whole story — the charterer is aboard next week, this is the
   * last of it, the next port has nothing worth buying. What the cook must not
   * have to do is distort the item's target until the calculator raises it for
   * him, because that figure drives every future order.
   */
  const ask = useMutation({
    mutationFn: ({ item, quantity, note }) =>
      inventoryAPI.requestOrder({ item_id: item.id, quantity: Number(quantity), note: note || null }),
    onSuccess: (res) => {
      toast.success(res.data.message);
      setAsking(null);
      qc.invalidateQueries({ queryKey: ["inv", "requisition-preview"] });
      qc.invalidateQueries({ queryKey: ["inv", "order-requests"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not add it to the order."),
  });

  /**
   * Take the blank prices off the quotations the office already sent this
   * vessel, matched by the ship's own catalogue code.
   *
   * Every figure the module produces leans on these prices — what an order is
   * worth, whether it fits the budget, what the galley spent — so the blanks
   * are not a cosmetic problem.
   */
  const fillPrices = useMutation({
    mutationFn: () => inventoryAPI.pricesFromOffers(),
    onSuccess: (res) => {
      const { unmatched = [] } = res.data.data ?? {};
      toast.success(res.data.message, { duration: 7000 });

      if (unmatched.length) {
        toast.warning(
          `Could not match: ${unmatched.join(", ")}. Those lines were typed onto the quotation by hand, so there is no catalogue code to match them on.`,
          { duration: 10000 }
        );
      }

      qc.invalidateQueries({ queryKey: ["inv", "items"] });
      qc.invalidateQueries({ queryKey: ["inv", "requisition-preview"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not take the prices.", { duration: 8000 }),
  });

  const groups = data?.groups ?? [];
  const summary = data?.summary;
  const categories = data?.categories ?? [];

  const term = search.trim().toLowerCase();

  // Filtering happens locally: the API already sent every row with its
  // calculated fields, so there is no reason to round-trip for a search box.
  const visible = useMemo(() => {
    return groups
      .map((g) => {
        let items = g.items;

        if (filter !== "All") items = items.filter((i) => i.status === filter);

        if (term) {
          items = items.filter(
            (i) =>
              i.name.toLowerCase().includes(term) ||
              (i.variant || "").toLowerCase().includes(term) ||
              i.code.toLowerCase().includes(term)
          );
        }

        return { ...g, items, count: items.length };
      })
      .filter((g) => g.count > 0);
  }, [groups, filter, term]);

  // A search or a status filter expands the matches automatically — the crew
  // should never have to hunt through 30 collapsed groups.
  const forceOpen = term.length > 0 || filter !== "All";

  const toggle = (id) => setOpenGroups((o) => ({ ...o, [id]: !o[id] }));

  const saveStock = useMutation({
    mutationFn: ({ id, value }) => inventoryAPI.updateStock(id, value),
    onMutate: ({ id }) => setSaving((s) => ({ ...s, [id]: true })),
    onSuccess: (res, { id }) => {
      const { item: fresh, summary: freshSummary } = res.data.data;

      // The row and the summary cards both come back recalculated, so the cache
      // is patched in place: no refetch of the whole catalogue on every count,
      // no flicker, and no lost scroll position.
      qc.setQueryData(["inv", "items"], (old) => {
        if (!old) return old;
        return {
          ...old,
          summary: freshSummary ?? old.summary,
          groups: old.groups.map((g) => ({
            ...g,
            items: g.items.map((i) => (i.id === fresh.id ? fresh : i)),
            to_order: g.items.reduce(
              (n, i) => n + ((i.id === fresh.id ? fresh : i).order_qty > 0 ? 1 : 0),
              0
            ),
          })),
        };
      });

      setDrafts((d) => {
        const next = { ...d };
        delete next[id];
        return next;
      });

      // Other screens read the same numbers; refresh them lazily.
      qc.invalidateQueries({ queryKey: ["inv", "dashboard"] });
      qc.invalidateQueries({ queryKey: ["inv", "requisition-preview"] });
    },
    onError: () => toast.error("Could not save the count."),
    onSettled: (_r, _e, { id }) =>
      setSaving((s) => {
        const next = { ...s };
        delete next[id];
        return next;
      }),
  });

  const commit = (item) => {
    const raw = drafts[item.id];
    if (raw === undefined) return;

    const value = raw === "" ? 0 : Number(raw);
    if (Number.isNaN(value) || value < 0) {
      toast.error("Enter a valid quantity.");
      return;
    }
    if (value === Number(item.current_stock)) {
      setDrafts((d) => {
        const next = { ...d };
        delete next[item.id];
        return next;
      });
      return;
    }

    saveStock.mutate({ id: item.id, value });
  };

  const saveItem = useMutation({
    mutationFn: () => {
      const payload = {
        code: form.code.trim(),
        category_id: form.category_id,
        name: form.name.trim(),
        variant: form.variant?.trim() || null,
        unit: form.unit.trim() || "PCS",
        unit_price: form.unit_price === "" ? null : Number(form.unit_price),
        shelf_life_days: form.shelf_life_days === "" ? null : Number(form.shelf_life_days),
        current_stock: form.current_stock === "" ? 0 : Number(form.current_stock),
        manual_target: form.manual_target === "" ? null : Number(form.manual_target),
        notes: form.notes?.trim() || null,
      };
      return form.id ? inventoryAPI.updateItem(form.id, payload) : inventoryAPI.createItem(payload);
    },
    onSuccess: () => {
      toast.success(form.id ? "Item updated." : "Item added.");
      setForm(null);
      qc.invalidateQueries({ queryKey: ["inv", "items"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the item."),
  });

  const removeItem = useMutation({
    mutationFn: (id) => inventoryAPI.removeItem(id),
    onSuccess: () => {
      toast.success("Item removed.");
      qc.invalidateQueries({ queryKey: ["inv", "items"] });
    },
    onError: () => toast.error("Could not remove the item."),
  });

  const askRemove = async (item) => {
    const ok = await confirm({
      title: "Remove item",
      message: `Remove "${item.name}" from the catalogue? Its stock history goes with it.`,
      confirmText: "Remove",
      tone: "danger",
    });
    if (ok) removeItem.mutate(item.id);
  };

  const startEdit = (item) =>
    setForm({
      id: item.id,
      code: item.code,
      category_id: item.category_id,
      name: item.name,
      variant: item.variant || "",
      unit: item.unit,
      // The stored figure, not the one the table shows: that one may be the
      // cost of the batch on the shelf, which is the ledger's to set and not
      // something to be typed over here.
      unit_price: item.standing_price ?? "",
      shelf_life_days: item.shelf_life_days ?? "",
      current_stock: item.current_stock ?? "",
      manual_target: item.manual_target ?? "",
      notes: item.notes || "",
    });

  if (isLoading) return <PageLoader label="Loading provisions…" />;

  const totalShown = visible.reduce((n, g) => n + g.count, 0);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-[#28364b]">Inventory</h1>
          <p className="text-sm text-slate-500">
            Update the stock on board (ROB). Everything else is worked out for you.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {/* The office already knows these prices — it quoted them. Typing
              them in a second time is how they stay untyped. */}
          {office && Number(summary?.missing_prices) > 0 && (
            <button onClick={() => fillPrices.mutate()} disabled={fillPrices.isLoading} className={btn.ghost}>
              {fillPrices.isLoading ? <Spinner className="h-4 w-4" /> : <Wallet className="h-4 w-4" />}
              Fill prices from quotations
            </button>
          )}
          <button onClick={() => setForm(blankItem())} className={btn.primary}>
            <Plus className="h-4 w-4" /> Add item
          </button>
        </div>
      </div>

      {/* Three cards for the cook, four for anyone shown money. The two he
          keeps are the two he acts on — how much is on the list and how much of
          it needs ordering — and both are counts, not sums. */}
      {summary && (
        <div className={`grid grid-cols-2 gap-3 ${canSeeMoney ? "lg:grid-cols-4" : "lg:grid-cols-3"}`}>
          <StatCard label="Items on list" value={summary.items_total} icon={Boxes} />
          <StatCard
            label="To order"
            value={summary.items_to_order}
            hint={`${summary.order_now} order now · ${summary.low} low`}
            tone={summary.order_now > 0 ? "danger" : "default"}
            icon={ShoppingCart}
          />
          {canSeeMoney && <StatCard label="Order value" value={`$${money(summary.order_value)}`} icon={Wallet} />}
          <StatCard
            label="Missing prices"
            value={summary.missing_prices}
            hint={summary.missing_prices ? "Order value is understated" : "All priced"}
            tone={summary.missing_prices > 0 ? "low" : "good"}
            icon={AlertTriangle}
          />
        </div>
      )}

      {/* Search + status filter */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[240px] flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search an item, code or variant…"
            className={`${inputCls} w-full pl-9`}
          />
          {search && (
            <button
              onClick={() => setSearch("")}
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:bg-slate-100"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          )}
        </div>
        <div className="flex gap-1 rounded-lg border border-slate-200 bg-white p-1">
          {FILTERS.map((f) => (
            <button
              key={f}
              onClick={() => setFilter(f)}
              className={`rounded-md px-3 py-1.5 text-xs font-semibold transition-colors ${
                filter === f ? "bg-[#28364b] text-white" : "text-slate-500 hover:bg-slate-100"
              }`}
            >
              {f}
            </button>
          ))}
        </div>
      </div>

      <p className="text-xs text-slate-400">
        {totalShown} item{totalShown === 1 ? "" : "s"} in {visible.length} categor{visible.length === 1 ? "y" : "ies"}
        {forceOpen ? "" : " — open a category to update its counts"}
      </p>

      {/* Collapsible categories */}
      <div className="space-y-2">
        {visible.map((group) => {
          const open = forceOpen || !!openGroups[group.id];

          return (
            <div key={group.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white">
              <button
                onClick={() => toggle(group.id)}
                className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-slate-50"
              >
                <div className="flex min-w-0 items-center gap-2">
                  <ChevronRight className={`h-4 w-4 shrink-0 text-slate-400 transition-transform ${open ? "rotate-90" : ""}`} />
                  <span className="truncate text-sm font-semibold text-[#28364b]">{group.name}</span>
                  <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                    {group.count}
                  </span>
                </div>
                {group.to_order > 0 && (
                  <span className="shrink-0 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-bold text-red-600 ring-1 ring-red-200">
                    {group.to_order} to order
                  </span>
                )}
              </button>

              {open && (
                <div className="overflow-x-auto border-t border-slate-100">
                  <table className="w-full min-w-[900px] text-sm">
                    <thead>
                      <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                        <th className="px-4 py-2 font-semibold">Code</th>
                        <th className="px-4 py-2 font-semibold">Item</th>
                        <th className="px-2 py-2 font-semibold">Unit</th>
                        <th className="px-2 py-2 text-right font-semibold">Stock (ROB)</th>
                        {canSeeMoney && <th className="px-2 py-2 text-right font-semibold">Unit price</th>}
                        <th className="px-2 py-2 text-right font-semibold">Keeps</th>
                        <th className="px-3 py-2 font-semibold">Status</th>
                        <th className="px-3 py-2" />
                      </tr>
                    </thead>
                    <tbody>
                      {group.items.map((item) => {
                        const draft = drafts[item.id];
                        const dirty = draft !== undefined && Number(draft || 0) !== Number(item.current_stock);

                        return (
                          <tr key={item.id} className={`border-t border-slate-100 ${statusOf(item.status).row}`}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs text-slate-400">{item.code}</td>
                            <td className="px-4 py-2">
                              <span className="font-medium text-[#28364b]">{item.name}</span>
                              {item.variant && <span className="ml-1 text-xs text-slate-400">{item.variant}</span>}
                            </td>
                            <td className="px-2 py-2 text-xs text-slate-500">{item.unit}</td>
                            <td className="px-2 py-2 text-right">
                              <div className="flex items-center justify-end gap-1.5">
                                <input
                                  type="number"
                                  min="0"
                                  step="any"
                                  value={draft ?? item.current_stock}
                                  onChange={(e) => setDrafts((d) => ({ ...d, [item.id]: e.target.value }))}
                                  onBlur={() => commit(item)}
                                  onKeyDown={(e) => {
                                    if (e.key === "Enter") e.currentTarget.blur();
                                    if (e.key === "Escape")
                                      setDrafts((d) => {
                                        const n = { ...d };
                                        delete n[item.id];
                                        return n;
                                      });
                                  }}
                                  className={`${numCls} w-24 py-1 ${dirty ? "border-[#cebd88] bg-[#cebd88]/10" : ""}`}
                                />
                                {saving[item.id] && <Spinner className="h-3.5 w-3.5 text-slate-400" />}
                              </div>
                            </td>
                            {canSeeMoney && (
                              <td className="whitespace-nowrap px-2 py-2 text-right text-slate-500">
                                <UnitPrice item={item} />
                              </td>
                            )}
                            {/* What is left of this item's life.
                                A real batch date beats the shelf-life rule: the
                                rule only ever existed to produce a date when a
                                delivery note did not give one. Once there is
                                stock aboard the honest answer is how long THAT
                                food has, which is the whole point of warning at
                                all — an item can read GOOD against its target
                                and still be going off on Friday. */}
                            <td className="whitespace-nowrap px-2 py-2 text-right text-xs">
                              <Keeps item={item} />
                            </td>
                            <td className="px-3 py-2">
                              <StatusPill status={item.status} />
                            </td>
                            <td className="px-3 py-2">
                              <div className="flex items-center justify-end gap-1">
                                {/* A button that says Order, not an icon that
                                    has to be guessed at.
                                    "SO WE CANT CHOOSE WHICH WE JUST WANT TO
                                    ORDER?" — on every row whatever the status
                                    says, because an item marked GOOD is still
                                    something a cook has reasons to want. */}
                                <button
                                  onClick={() => setAsking({ item, quantity: String(item.order_qty || "") })}
                                  title={`Order some ${item.name}`}
                                  className="mr-1 inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition-colors hover:border-[#28364b] hover:bg-[#28364b] hover:text-white"
                                >
                                  <ShoppingCart className="h-3.5 w-3.5" /> Order
                                </button>
                                <button
                                  onClick={() => startEdit(item)}
                                  className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                                  title="Edit item"
                                >
                                  <Pencil className="h-3.5 w-3.5" />
                                </button>
                                <button
                                  onClick={() => askRemove(item)}
                                  className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                                  title="Remove item"
                                >
                                  <Trash2 className="h-3.5 w-3.5" />
                                </button>
                              </div>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          );
        })}

        {visible.length === 0 && (
          <div className="rounded-xl border border-dashed border-slate-200 bg-white py-16 text-center text-sm text-slate-400">
            Nothing matches that search.
          </div>
        )}
      </div>

      {/* Add / edit item */}
      <Modal open={!!form} onClose={() => setForm(null)} title={form?.id ? "Edit item" : "Add item"} maxWidth="max-w-2xl">
        {form && (
          <form
            className="space-y-4 p-6"
            onSubmit={(e) => {
              e.preventDefault();
              if (!form.code.trim() || !form.name.trim() || !form.category_id) {
                toast.error("Code, item name and category are required.");
                return;
              }
              saveItem.mutate();
            }}
          >
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Code" hint="The vessel's stable item code">
                <input
                  value={form.code}
                  onChange={(e) => setForm((f) => ({ ...f, code: e.target.value }))}
                  className={`${inputCls} w-full`}
                  placeholder="1001012"
                />
              </Field>
              <Field label="Category">
                <Select
                  value={form.category_id}
                  onChange={(v) => setForm((f) => ({ ...f, category_id: v }))}
                  options={categories.map((c) => ({ value: c.id, label: c.name }))}
                  placeholder="Choose a category"
                />
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Item name">
                <input
                  value={form.name}
                  onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                  className={`${inputCls} w-full`}
                  placeholder="Carrots, Fresh"
                />
              </Field>
              <Field label="Variant / spec" hint="Origin, species, size — optional">
                <input
                  value={form.variant}
                  onChange={(e) => setForm((f) => ({ ...f, variant: e.target.value }))}
                  className={`${inputCls} w-full`}
                  placeholder="(Norway), 300-400 GR"
                />
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-4">
              <Field label="Unit">
                <input
                  value={form.unit}
                  onChange={(e) => setForm((f) => ({ ...f, unit: e.target.value }))}
                  className={`${inputCls} w-full`}
                  placeholder="Kg"
                />
              </Field>
              {/* The cook edits the item — its unit, its shelf life, what it
                  is called — but not what it costs. The API drops the field if
                  it arrives from him anyway, so the price he never saw is not
                  cleared by a save he made for another reason. */}
              {canSeeMoney && (
                <Field label="Unit price" hint="Used when nothing is on board">
                  <input
                    type="number"
                    min="0"
                    step="any"
                    value={form.unit_price}
                    onChange={(e) => setForm((f) => ({ ...f, unit_price: e.target.value }))}
                    className={`${numCls} w-full`}
                  />
                </Field>
              )}
              <Field label="Stock (ROB)">
                <input
                  type="number"
                  min="0"
                  step="any"
                  value={form.current_stock}
                  onChange={(e) => setForm((f) => ({ ...f, current_stock: e.target.value }))}
                  className={`${numCls} w-full`}
                />
              </Field>
              <Field label="Shelf life" hint="days">
                <input
                  type="number"
                  min="0"
                  value={form.shelf_life_days}
                  onChange={(e) => setForm((f) => ({ ...f, shelf_life_days: e.target.value }))}
                  className={`${numCls} w-full`}
                />
              </Field>
            </div>

            <Field
              label="Expected quantity per supply"
              hint="Only used for a brand-new item with no consumption history yet — leave blank otherwise"
            >
              <input
                type="number"
                min="0"
                step="any"
                value={form.manual_target}
                onChange={(e) => setForm((f) => ({ ...f, manual_target: e.target.value }))}
                className={`${numCls} w-full sm:w-48`}
              />
            </Field>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setForm(null)} className={btn.ghost}>
                Cancel
              </button>
              <button type="submit" disabled={saveItem.isLoading} className={btn.primary}>
                {saveItem.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />}
                {form.id ? "Save changes" : "Add item"}
              </button>
            </div>
          </form>
        )}
      </Modal>

      {/* Order some of this anyway, whatever the status says. */}
      <Modal open={!!asking} onClose={() => setAsking(null)} title="Add to the next order" maxWidth="max-w-md">
        {asking && (
          <form
            className="space-y-4 p-6"
            onSubmit={(e) => {
              e.preventDefault();
              ask.mutate(asking);
            }}
          >
            <div className="rounded-lg bg-slate-50 px-4 py-3">
              <p className="text-sm font-semibold text-[#28364b]">{asking.item.name}</p>
              {asking.item.variant && <p className="text-xs text-slate-400">{asking.item.variant}</p>}
              <p className="mt-1.5 text-xs text-slate-500">
                {qty(asking.item.current_stock)} {asking.item.unit} on board
                {asking.item.target_qty > 0 && ` · target ${qty(asking.item.target_qty)}`}
                {" · "}
                <span className="font-semibold">{statusOf(asking.item.status).label}</span>
              </p>
            </div>

            <Field label={`How much (${asking.item.unit})`}>
              <input
                type="number"
                min="0.001"
                step="any"
                autoFocus
                value={asking.quantity}
                onChange={(e) => setAsking((a) => ({ ...a, quantity: e.target.value }))}
                onFocus={(e) => e.target.select()}
                className={`${numCls} w-full`}
              />
            </Field>

            <Field label="Why" hint="Optional — the master reads this when he approves the order">
              <input
                value={asking.note || ""}
                onChange={(e) => setAsking((a) => ({ ...a, note: e.target.value }))}
                placeholder="Charterer aboard next week"
                className={`${inputCls} w-full`}
              />
            </Field>

            <p className="flex items-start gap-2 rounded-lg bg-slate-50 px-4 py-3 text-[11px] text-slate-500">
              <ShoppingCart className="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#cebd88]" />
              <span>
                This goes onto the next requisition marked as your request. The item's own target is not changed, so the
                calculation stays honest for every order after this one.
              </span>
            </p>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setAsking(null)} className={btn.ghost}>
                Cancel
              </button>
              <button
                type="submit"
                disabled={!(Number(asking.quantity) > 0) || ask.isLoading}
                className={btn.primary}
              >
                {ask.isLoading ? <Spinner className="h-4 w-4" /> : <ShoppingCart className="h-4 w-4" />}
                Add to the order
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
}

/**
 * What a unit of this item costs — and, when that needs qualifying, the fact
 * that it does.
 *
 * The figure is the oldest batch on the shelf, because that is the one the next
 * withdrawal comes out of. A shelf can hold more than one price, and then a
 * single number is a half-truth: 2 Kg bought at $25 sitting in front of 5 Kg
 * bought at $20 reads as "$25" and looks wrong to anyone who remembers paying
 * $20 last week. It is not wrong, but it does need saying, so the second line
 * says it rather than leaving it to be found on the batch board.
 */
function UnitPrice({ item }) {
  if (item.unit_price === null) {
    return (
      <span className="text-amber-600" title="No price on file">
        —
      </span>
    );
  }

  const low = item.stock_cost_low;
  const high = item.stock_cost_high;
  const mixed = item.stock_batches > 1 && low !== null && high !== null && low !== high;

  if (!mixed) {
    return (
      <span
        title={
          item.stock_cost !== null
            ? "What the batch on the shelf cost — the price the next withdrawal comes out at"
            : "The office's standing price. Nothing on board to cost it against."
        }
      >
        {money(item.unit_price)}
      </span>
    );
  }

  return (
    <span
      className="inline-flex flex-col items-end leading-tight"
      title={
        `${item.stock_batches} batches on board, from ${money(low)} to ${money(high)}. ` +
        `The oldest goes out first, so the next ${item.unit} costs ${money(item.unit_price)}.`
      }
    >
      <span>{money(item.unit_price)}</span>
      <span className="text-[10px] font-medium text-amber-600">
        {item.stock_batches} prices · {money(low)}–{money(high)}
      </span>
    </span>
  );
}

/**
 * How long this item has left, in the order the answers are worth having.
 *
 * 1. A batch aboard that has gone off — food to write off, in red.
 * 2. A batch aboard going off soon — food to cook first, in amber.
 * 3. A batch aboard with time on it — plain, with the date.
 * 4. No stock, only the shelf-life rule — what a delivery would get dated with.
 * 5. Neither — nothing is known, and the dash says so rather than implying it
 *    keeps forever.
 *
 * The window comes from the server (ProvisionCalculator::EXPIRY_WINDOW_DAYS) by
 * way of days_left, so this never decides for itself what "soon" means.
 */
function Keeps({ item }) {
  const days = item.days_left;

  if (days !== null && days !== undefined) {
    const amount = item.expiring_qty > 0 ? `${qty(item.expiring_qty)} ${item.unit}` : null;

    if (days < 0) {
      return (
        <span className="font-semibold text-red-600" title={`Expired ${item.expires_on}`}>
          {amount ? `${amount} · ` : ""}
          {days === -1 ? "1 day over" : `${Math.abs(days)} days over`}
        </span>
      );
    }

    if (days <= (item.expiry_window_days ?? 14)) {
      return (
        <span className="font-semibold text-amber-600" title={`Expires ${item.expires_on} — use this first`}>
          {amount ? `${amount} · ` : ""}
          {days === 0 ? "today" : days === 1 ? "1 day left" : `${days} days left`}
        </span>
      );
    }

    return (
      <span className="text-slate-500" title={`Expires ${item.expires_on}`}>
        {days} d left
      </span>
    );
  }

  if (item.shelf_life_days) {
    return (
      <span className="text-slate-500" title="How long a new delivery of this keeps">
        {item.shelf_life_days} d <span className="text-slate-400">before expiry</span>
      </span>
    );
  }

  return <span className="text-slate-400">—</span>;
}

function Field({ label, hint, children }) {
  return (
    <label className="block">
      <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-[11px] text-slate-400">{hint}</span>}
    </label>
  );
}
