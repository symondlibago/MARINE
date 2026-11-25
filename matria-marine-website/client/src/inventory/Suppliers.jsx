import { useMemo, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Store, Plus, Pencil, Trash2, Save, Search, BadgeCheck, DownloadCloud } from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { money, btn, inputCls, numCls } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";
import Modal from "@/portal/ui/Modal";
import Select from "@/portal/ui/Select";

const blank = () => ({
  id: null,
  name: "",
  port: "",
  contact_name: "",
  email: "",
  phone: "",
  currency: "USD",
  exchange_rate: 1,
  notes: "",
  is_active: true,
});

/**
 * Price comparison across suppliers — office use.
 *
 * Prices are entered in each supplier's own currency; the cheapest column is
 * decided on the USD equivalent so it is a like-for-like comparison.
 */
export default function Suppliers() {
  const qc = useQueryClient();
  const confirm = useConfirm();

  const [form, setForm] = useState(null);
  const [categoryId, setCategoryId] = useState("");
  const [search, setSearch] = useState("");
  const [edits, setEdits] = useState({});     // `${supplierId}:${itemId}` -> typed price

  const { data: suppliers, isLoading } = useQuery({
    queryKey: ["inv", "suppliers"],
    queryFn: async () => (await inventoryAPI.suppliers()).data.data.suppliers,
  });

  const { data: comparison, isFetching } = useQuery({
    queryKey: ["inv", "comparison", categoryId, search],
    queryFn: async () =>
      (await inventoryAPI.comparison({ category_id: categoryId || undefined, search: search || undefined })).data.data,
    keepPreviousData: true,
  });

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        name: form.name.trim(),
        port: form.port?.trim() || null,
        contact_name: form.contact_name?.trim() || null,
        email: form.email?.trim() || null,
        phone: form.phone?.trim() || null,
        currency: form.currency.trim() || "USD",
        exchange_rate: Number(form.exchange_rate) || 1,
        notes: form.notes?.trim() || null,
        is_active: !!form.is_active,
      };
      return form.id ? inventoryAPI.updateSupplier(form.id, payload) : inventoryAPI.createSupplier(payload);
    },
    onSuccess: () => {
      toast.success(form.id ? "Supplier updated." : "Supplier added.");
      setForm(null);
      qc.invalidateQueries({ queryKey: ["inv", "suppliers"] });
      qc.invalidateQueries({ queryKey: ["inv", "comparison"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the supplier."),
  });

  const remove = useMutation({
    mutationFn: (id) => inventoryAPI.removeSupplier(id),
    onSuccess: () => {
      toast.success("Supplier removed.");
      qc.invalidateQueries({ queryKey: ["inv", "suppliers"] });
      qc.invalidateQueries({ queryKey: ["inv", "comparison"] });
    },
    onError: () => toast.error("Could not remove the supplier."),
  });

  const savePrices = useMutation({
    mutationFn: async () => {
      // Group the typed cells back into one request per supplier.
      const bySupplier = {};
      for (const [key, value] of Object.entries(edits)) {
        const [supplierId, itemId] = key.split(":").map(Number);
        (bySupplier[supplierId] ??= []).push({ item_id: itemId, price: value === "" ? null : Number(value) });
      }
      for (const [supplierId, prices] of Object.entries(bySupplier)) {
        await inventoryAPI.savePrices(supplierId, { prices });
      }
    },
    onSuccess: () => {
      toast.success("Prices saved.");
      setEdits({});
      qc.invalidateQueries({ queryKey: ["inv", "comparison"] });
    },
    onError: () => toast.error("Could not save the prices."),
  });

  const applyPrices = useMutation({
    mutationFn: (id) => inventoryAPI.applyPrices(id),
    onSuccess: (res) => {
      toast.success(res.data.message);
      qc.invalidateQueries({ queryKey: ["inv", "items"] });
      qc.invalidateQueries({ queryKey: ["inv", "comparison"] });
      qc.invalidateQueries({ queryKey: ["inv", "dashboard"] });
    },
    onError: () => toast.error("Could not apply the prices."),
  });

  const askRemove = async (s) => {
    const ok = await confirm({
      title: "Remove supplier",
      message: `Remove ${s.name}? Their quoted prices go with them.`,
      confirmText: "Remove",
      tone: "danger",
    });
    if (ok) remove.mutate(s.id);
  };

  const askApply = async (s) => {
    const ok = await confirm({
      title: `Use ${s.name}'s prices`,
      message: `Every item ${s.name} has quoted will take that price (converted to USD) as its catalogue price. Order values are recalculated from it.`,
      confirmText: "Apply prices",
    });
    if (ok) applyPrices.mutate(s.id);
  };

  const active = useMemo(() => (comparison?.suppliers ?? []).filter((s) => s.is_active !== false), [comparison]);
  const dirty = Object.keys(edits).length > 0;

  if (isLoading) return <PageLoader label="Loading suppliers…" />;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-[#28364b]">Suppliers</h1>
          <p className="text-sm text-slate-500">Compare quotes per item and pick the cheapest. Office use.</p>
        </div>
        <button onClick={() => setForm(blank())} className={btn.primary}>
          <Plus className="h-4 w-4" /> Add supplier
        </button>
      </div>

      {/* Supplier cards */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {(suppliers ?? []).map((s) => (
          <div key={s.id} className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="truncate font-semibold text-[#28364b]">{s.name}</p>
                <p className="truncate text-xs text-slate-400">
                  {s.port || "—"} · {s.currency}
                  {Number(s.exchange_rate) !== 1 ? ` @ ${s.exchange_rate}` : ""}
                </p>
              </div>
              <div className="flex shrink-0 gap-1">
                <button
                  onClick={() => setForm({ ...blank(), ...s })}
                  className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                >
                  <Pencil className="h-3.5 w-3.5" />
                </button>
                <button
                  onClick={() => askRemove(s)}
                  className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                >
                  <Trash2 className="h-3.5 w-3.5" />
                </button>
              </div>
            </div>
            <div className="mt-3 flex items-center justify-between gap-2">
              <span className="text-xs text-slate-400">{s.prices_count} quoted</span>
              <button
                onClick={() => askApply(s)}
                disabled={!s.prices_count}
                className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-[#28364b] transition-colors hover:bg-slate-50 disabled:opacity-40"
              >
                <DownloadCloud className="h-3 w-3" /> Use these prices
              </button>
            </div>
          </div>
        ))}
        {!suppliers?.length && (
          <div className="rounded-xl border border-dashed border-slate-200 bg-white p-8 text-center text-sm text-slate-400 sm:col-span-2 lg:col-span-3">
            <Store className="mx-auto mb-2 h-8 w-8 text-slate-200" />
            No suppliers yet. Add the ports you buy provisions from.
          </div>
        )}
      </div>

      {/* Price comparison */}
      <div className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative min-w-[220px] flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Find items to price…"
              className={`${inputCls} w-full pl-9`}
            />
          </div>
          <div className="min-w-[200px]">
            <Select
              value={categoryId}
              onChange={setCategoryId}
              options={[
                { value: "", label: "All categories" },
                ...(comparison?.categories ?? []).map((c) => ({ value: c.id, label: c.name })),
              ]}
              placeholder="All categories"
              searchable
              searchPlaceholder="Find a category…"
            />
          </div>
          {dirty && (
            <button onClick={() => savePrices.mutate()} disabled={savePrices.isLoading} className={btn.primary}>
              {savePrices.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save prices
            </button>
          )}
        </div>

        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-2 font-semibold">Item</th>
                  <th className="px-2 py-2 font-semibold">Unit</th>
                  <th className="px-2 py-2 text-right font-semibold">In use</th>
                  {active.map((s) => (
                    <th key={s.id} className="px-2 py-2 text-right font-semibold">
                      {s.name}
                      <span className="ml-1 font-normal normal-case text-slate-400">{s.currency}</span>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {(comparison?.rows ?? []).map((row) => (
                  <tr key={row.id} className="border-t border-slate-100">
                    <td className="px-4 py-2">
                      <span className="font-medium text-[#28364b]">{row.name}</span>
                      {row.variant && <span className="ml-1 text-xs text-slate-400">{row.variant}</span>}
                    </td>
                    <td className="px-2 py-2 text-xs text-slate-500">{row.unit}</td>
                    <td className="px-2 py-2 text-right text-slate-500">
                      {row.unit_price === null ? <span className="text-amber-600">—</span> : money(row.unit_price)}
                    </td>
                    {active.map((s) => {
                      const key = `${s.id}:${row.id}`;
                      const stored = row.prices?.[s.id]?.price;
                      const value = edits[key] ?? (stored ?? "");
                      const best = row.best_supplier_id === s.id && stored !== undefined;

                      return (
                        <td key={s.id} className="px-2 py-2 text-right">
                          <div className="flex items-center justify-end gap-1">
                            {best && <BadgeCheck className="h-3.5 w-3.5 shrink-0 text-emerald-600" title="Cheapest" />}
                            <input
                              type="number"
                              min="0"
                              step="any"
                              value={value}
                              onChange={(e) => setEdits((p) => ({ ...p, [key]: e.target.value }))}
                              className={`${numCls} w-24 py-1 ${best ? "border-emerald-300 bg-emerald-50/60" : ""}`}
                            />
                          </div>
                        </td>
                      );
                    })}
                  </tr>
                ))}
                {!comparison?.rows?.length && (
                  <tr>
                    <td colSpan={3 + active.length} className="px-4 py-16 text-center text-sm text-slate-400">
                      {isFetching ? "Loading…" : "No items match. Pick a category or search for an item to start pricing."}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
        {!active.length && (
          <p className="text-xs text-slate-400">Add a supplier above to start comparing prices.</p>
        )}
      </div>

      {/* Add / edit supplier */}
      <Modal open={!!form} onClose={() => setForm(null)} title={form?.id ? "Edit supplier" : "Add supplier"}>
        {form && (
          <form
            className="space-y-4 p-6"
            onSubmit={(e) => {
              e.preventDefault();
              if (!form.name.trim()) {
                toast.error("A supplier name is required.");
                return;
              }
              save.mutate();
            }}
          >
            <div className="grid gap-4 sm:grid-cols-2">
              <L label="Name">
                <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} className={`${inputCls} w-full`} />
              </L>
              <L label="Port">
                <input value={form.port ?? ""} onChange={(e) => setForm((f) => ({ ...f, port: e.target.value }))} className={`${inputCls} w-full`} />
              </L>
              <L label="Contact">
                <input
                  value={form.contact_name ?? ""}
                  onChange={(e) => setForm((f) => ({ ...f, contact_name: e.target.value }))}
                  className={`${inputCls} w-full`}
                />
              </L>
              <L label="Email">
                <input value={form.email ?? ""} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} className={`${inputCls} w-full`} />
              </L>
              <L label="Phone">
                <input value={form.phone ?? ""} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} className={`${inputCls} w-full`} />
              </L>
              <div className="grid grid-cols-2 gap-3">
                <L label="Currency">
                  <input
                    value={form.currency}
                    onChange={(e) => setForm((f) => ({ ...f, currency: e.target.value.toUpperCase() }))}
                    className={`${inputCls} w-full`}
                  />
                </L>
                <L label="Rate to USD">
                  <input
                    type="number"
                    step="any"
                    min="0"
                    value={form.exchange_rate}
                    onChange={(e) => setForm((f) => ({ ...f, exchange_rate: e.target.value }))}
                    className={`${numCls} w-full`}
                  />
                </L>
              </div>
            </div>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setForm(null)} className={btn.ghost}>
                Cancel
              </button>
              <button type="submit" disabled={save.isLoading} className={btn.primary}>
                {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
}

function L({ label, children }) {
  return (
    <label className="block">
      <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</span>
      {children}
    </label>
  );
}
