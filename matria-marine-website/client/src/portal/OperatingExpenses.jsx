import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { motion } from "framer-motion";
import { Wallet, Plus, Pencil, Trash2, X, Save, CalendarRange } from "lucide-react";
import { toast } from "sonner";
import { operatingExpensesAPI } from "@/pages/api";
import { fetchRates, rateToBase } from "@/lib/fx";
import { PageLoader, Spinner } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";
import Select from "./ui/Select";
import DatePicker from "./ui/DatePicker";

const BASE = "USD"; // company base currency (config procurement.base_currency)
const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];
const CATEGORIES = ["Rent", "Salaries", "Utilities", "Software", "Bank charges", "Insurance", "Transport", "Marketing", "Other"];

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pad = (n) => String(n).padStart(2, "0");
const ymdOf = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const firstOfMonth = () => { const d = new Date(); return ymdOf(new Date(d.getFullYear(), d.getMonth(), 1)); };
const lastOfMonth = () => { const d = new Date(); return ymdOf(new Date(d.getFullYear(), d.getMonth() + 1, 0)); };

const blankItem = () => ({ name: "", category: "", amount: "" });
const blankForm = () => ({ id: null, label: "", period_start: firstOfMonth(), period_end: lastOfMonth(), currency: BASE, exchange_rate: 1, notes: "", items: [blankItem(), blankItem()] });

export default function OperatingExpenses() {
  const confirm = useConfirm();
  const [form, setForm] = useState(blankForm());
  const [rateLoading, setRateLoading] = useState(false);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["operating-expenses"],
    queryFn: async () => (await operatingExpensesAPI.list()).data.data,
  });

  const groups = data ?? [];
  const editing = form.id !== null;
  const set = (patch) => setForm((f) => ({ ...f, ...patch }));
  const setItem = (i, patch) => setForm((f) => ({ ...f, items: f.items.map((it, idx) => (idx === i ? { ...it, ...patch } : it)) }));
  const addItem = () => setForm((f) => ({ ...f, items: [...f.items, blankItem()] }));
  const removeItem = (i) => setForm((f) => ({ ...f, items: f.items.filter((_, idx) => idx !== i) }));

  const changeCurrency = async (cur) => {
    set({ currency: cur });
    if (cur === BASE) { set({ exchange_rate: 1 }); return; }
    setRateLoading(true);
    try {
      const rates = await fetchRates(BASE);
      const r = rateToBase(rates, cur);
      if (r) set({ exchange_rate: Number(r.toFixed(6)) });
      else toast.error(`No live rate for ${cur} — enter it manually.`);
    } catch {
      toast.error("Could not fetch a live rate — enter it manually.");
    } finally {
      setRateLoading(false);
    }
  };

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        label: form.label || null,
        period_start: form.period_start,
        period_end: form.period_end,
        currency: form.currency,
        exchange_rate: Number(form.exchange_rate) || 1,
        notes: form.notes || null,
        items: form.items
          .filter((it) => (it.name || "").trim() !== "" || it.amount !== "")
          .map((it) => ({ name: it.name, category: it.category || null, amount: it.amount === "" ? 0 : Number(it.amount) })),
      };
      return editing ? operatingExpensesAPI.update(form.id, payload) : operatingExpensesAPI.create(payload);
    },
    onSuccess: () => { toast.success(editing ? "Expenses updated." : "Expenses saved."); setForm(blankForm()); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save."),
  });

  const del = useMutation({
    mutationFn: (id) => operatingExpensesAPI.remove(id),
    onSuccess: () => { toast.success("Removed."); refetch(); },
    onError: () => toast.error("Could not remove."),
  });

  const startEdit = (g) => {
    setForm({
      id: g.id,
      label: g.label || "",
      period_start: g.period_start,
      period_end: g.period_end,
      currency: g.currency || BASE,
      exchange_rate: g.exchange_rate ?? 1,
      notes: g.notes || "",
      items: (g.items || []).map((it) => ({ name: it.name ?? "", category: it.category ?? "", amount: it.amount != null ? String(Number(it.amount)) : "" })),
    });
    if (!(g.items || []).length) addItem();
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  const remove = async (g) => {
    const label = g.label || `${g.period_start} → ${g.period_end}`;
    if (await confirm({ title: `Delete "${label}"?`, message: "This removes the whole expense group and its lines.", confirmText: "Delete", tone: "danger" })) del.mutate(g.id);
  };

  const formTotal = form.items.reduce((s, it) => s + (Number(it.amount) || 0), 0);
  const hasItem = form.items.some((it) => (it.name || "").trim() !== "" || Number(it.amount) > 0);
  const canSave = form.period_start && form.period_end && form.period_end >= form.period_start && hasItem;

  // Filter the saved groups by an overlapping from–to window, and total them.
  const overlaps = (g) => (!from || g.period_end >= from) && (!to || g.period_start <= to);
  const ranged = !!(from || to);
  const visible = groups.filter(overlaps);
  const grandTotal = visible.reduce((s, g) => s + (Number(g.total_base) || 0), 0);

  if (isLoading) return <PageLoader />;

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-[#28364b]"><Wallet className="h-6 w-6" /> Operating expenses</h1>
        <p className="mt-1 text-sm text-slate-500">Pick a period, list all the overhead for it (rent, salaries, software…), and save it as one group. Each group is subtracted from gross profit in the Accounting report for the periods it covers.</p>
      </div>

      {/* Add / edit a period group */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="flex items-center gap-2 text-sm font-bold text-[#28364b]"><CalendarRange className="h-4 w-4" /> {editing ? "Edit expense group" : "New expense group"}</h2>
          {editing && <button onClick={() => setForm(blankForm())} className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-[#28364b]"><X className="h-3.5 w-3.5" /> Cancel edit</button>}
        </div>

        <div className="flex flex-wrap gap-4">
          <Field label="Period — from *" className="w-40"><DatePicker value={form.period_start} onChange={(v) => set({ period_start: v })} placeholder="Start" /></Field>
          <Field label="Period — to *" className="w-40"><DatePicker value={form.period_end} onChange={(v) => set({ period_end: v })} placeholder="End" /></Field>
          <Field label="Label (optional)" className="w-56"><input value={form.label} onChange={(e) => set({ label: e.target.value })} placeholder="e.g. July 2026" className={input} /></Field>
          <Field label="Currency" className="w-36">
            <Select value={form.currency} onChange={changeCurrency} options={CURRENCIES} />
            {form.currency !== BASE && (
              <div className="mt-1 flex items-center gap-1 text-[10px] text-slate-400">
                × <input type="number" step="0.0001" value={form.exchange_rate} onChange={(e) => set({ exchange_rate: e.target.value })} className="w-20 rounded border border-slate-200 px-1 py-0.5 text-xs" /> → {BASE}
                {rateLoading && <Spinner className="h-3 w-3" />}
              </div>
            )}
          </Field>
        </div>

        {/* Line items */}
        <div className="mt-4">
          <div className="flex items-center justify-between">
            <label className="text-xs font-bold uppercase tracking-wider text-slate-400">Expenses in this period</label>
            <button type="button" onClick={addItem} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50"><Plus className="h-3.5 w-3.5" /> Add expense</button>
          </div>
          <div className="mt-2 space-y-2">
            <div className="grid grid-cols-[22rem_9rem_7rem_1.5rem] gap-2 px-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
              <span>Name</span>
              <span>Category</span>
              <span className="text-right">Amount</span>
              <span></span>
            </div>
            {form.items.map((it, i) => (
              <div key={i} className="grid grid-cols-[22rem_9rem_7rem_1.5rem] items-center gap-2">
                <input value={it.name} onChange={(e) => setItem(i, { name: e.target.value })} placeholder="e.g. Office rent" className={input} list="oe-cats" />
                <input value={it.category} onChange={(e) => setItem(i, { category: e.target.value })} placeholder="Category" className={input} list="oe-cats" />
                <input type="number" step="0.01" value={it.amount} onChange={(e) => setItem(i, { amount: e.target.value })} placeholder="0.00" className={`${input} text-right`} />
                <button type="button" onClick={() => removeItem(i)} className="flex justify-center text-slate-400 hover:text-red-600" title="Remove"><X className="h-4 w-4" /></button>
              </div>
            ))}
            <datalist id="oe-cats">{CATEGORIES.map((c) => <option key={c} value={c} />)}</datalist>
            <div className="flex items-center justify-between border-t border-slate-100 pt-2 text-sm">
              <span className="text-slate-500">Group total</span>
              <span className="font-semibold text-[#28364b]">
                {money(formTotal)} {form.currency}
                {form.currency !== BASE && <span className="ml-1.5 text-xs font-normal text-slate-400">≈ {money(formTotal * (Number(form.exchange_rate) || 1))} {BASE}</span>}
              </span>
            </div>
          </div>
        </div>

        <div className="mt-4">
          <label className="text-xs font-bold uppercase tracking-wider text-slate-400">Notes</label>
          <input value={form.notes} onChange={(e) => set({ notes: e.target.value })} placeholder="Optional" className={`${input} mt-1 max-w-2xl`} />
        </div>

        <div className="mt-4 flex justify-end">
          <button onClick={() => save.mutate()} disabled={!canSave || save.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-60">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} {editing ? "Save changes" : "Save group"}
          </button>
        </div>
      </div>

      {/* Track saved groups by range */}
      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <div>
          <label className="text-xs font-bold uppercase tracking-wider text-slate-400">View from</label>
          <div className="mt-1 w-40"><DatePicker value={from} onChange={setFrom} placeholder="Any" /></div>
        </div>
        <div>
          <label className="text-xs font-bold uppercase tracking-wider text-slate-400">View to</label>
          <div className="mt-1 w-40"><DatePicker value={to} onChange={setTo} placeholder="Any" /></div>
        </div>
        {ranged && <button onClick={() => { setFrom(""); setTo(""); }} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-500 hover:bg-slate-50">Clear</button>}
        <div className="ml-auto text-right">
          <div className="text-xs font-bold uppercase tracking-wider text-slate-400">{ranged ? "Total in view" : "All overhead"}</div>
          <div className="text-2xl font-bold text-[#28364b]">{money(grandTotal)} <span className="text-sm font-medium text-slate-400">{BASE}</span></div>
        </div>
      </div>

      {/* Saved groups */}
      <div className="space-y-3">
        {visible.length === 0 ? (
          <div className="rounded-xl border border-slate-200 bg-white py-10 text-center text-slate-400">
            {groups.length === 0 ? "No expense groups yet. Set a period above, add your overhead lines, and Save group." : "No expense groups in this view range."}
          </div>
        ) : (
          visible.map((g) => (
            <div key={g.id} className="rounded-xl border border-slate-200 bg-white">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                <div>
                  <div className="flex items-center gap-2 font-semibold text-[#28364b]">
                    <CalendarRange className="h-4 w-4 text-slate-400" />
                    {g.label || "Overhead"}
                    <span className="text-xs font-normal text-slate-400">{g.period_start} → {g.period_end}</span>
                  </div>
                  {g.notes && <div className="mt-0.5 text-xs text-slate-400">{g.notes}</div>}
                </div>
                <div className="flex items-center gap-3">
                  <div className="text-right">
                    <div className="font-bold text-[#28364b]">{money(g.total)} {g.currency}</div>
                    {g.currency !== BASE && <div className="text-[10px] text-slate-400">≈ {money(g.total_base)} {BASE}</div>}
                  </div>
                  <button onClick={() => startEdit(g)} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-[#28364b]" title="Edit"><Pencil className="h-4 w-4" /></button>
                  <button onClick={() => remove(g)} className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" title="Delete"><Trash2 className="h-4 w-4" /></button>
                </div>
              </div>
              <ul className="divide-y divide-slate-50">
                {(g.items || []).map((it) => (
                  <li key={it.id} className="flex items-center justify-between px-5 py-2 text-sm">
                    <span className="text-slate-700">{it.name}{it.category ? <span className="ml-1 text-xs text-slate-400">· {it.category}</span> : null}</span>
                    <span className="text-slate-600">{money(it.amount)} {g.currency}</span>
                  </li>
                ))}
              </ul>
            </div>
          ))
        )}
      </div>
    </motion.div>
  );
}

const input = "w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

function Field({ label, children, className = "" }) {
  return (
    <div className={className}>
      <label className="text-xs font-bold uppercase tracking-wider text-slate-400">{label}</label>
      <div className="mt-1">{children}</div>
    </div>
  );
}
