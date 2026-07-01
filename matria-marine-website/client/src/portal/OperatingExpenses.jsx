import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { motion } from "framer-motion";
import { Wallet, Plus, Pencil, Trash2, X, Repeat, Save } from "lucide-react";
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
const today = () => new Date().toISOString().slice(0, 10);

const blank = () => ({ id: null, name: "", category: "", amount: "", currency: BASE, exchange_rate: 1, effective_date: today(), recurring: false, end_date: "", notes: "" });

export default function OperatingExpenses() {
  const confirm = useConfirm();
  const [form, setForm] = useState(blank());
  const [rateLoading, setRateLoading] = useState(false);

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["operating-expenses"],
    queryFn: async () => (await operatingExpensesAPI.list()).data.data,
  });

  const rows = data ?? [];
  const editing = form.id !== null;
  const set = (patch) => setForm((f) => ({ ...f, ...patch }));

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
        name: form.name.trim(),
        category: form.category || null,
        amount: Number(form.amount) || 0,
        currency: form.currency,
        exchange_rate: Number(form.exchange_rate) || 1,
        effective_date: form.effective_date,
        recurring: !!form.recurring,
        end_date: form.recurring ? (form.end_date || null) : null,
        notes: form.notes || null,
      };
      return editing ? operatingExpensesAPI.update(form.id, payload) : operatingExpensesAPI.create(payload);
    },
    onSuccess: () => { toast.success(editing ? "Expense updated." : "Expense added."); setForm(blank()); refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save."),
  });

  const del = useMutation({
    mutationFn: (id) => operatingExpensesAPI.remove(id),
    onSuccess: () => { toast.success("Expense removed."); refetch(); },
    onError: () => toast.error("Could not remove."),
  });

  const startEdit = (r) => {
    setForm({
      id: r.id,
      name: r.name,
      category: r.category || "",
      amount: String(r.amount ?? ""),
      currency: r.currency || BASE,
      exchange_rate: r.exchange_rate ?? 1,
      effective_date: r.effective_date || today(),
      recurring: !!r.recurring,
      end_date: r.end_date || "",
      notes: r.notes || "",
    });
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  const remove = async (r) => {
    const ok = await confirm({ title: `Delete "${r.name}"?`, message: "This removes the overhead entry.", confirmText: "Delete", tone: "danger" });
    if (ok) del.mutate(r.id);
  };

  const canSave = form.name.trim() !== "" && Number(form.amount) > 0 && form.effective_date;

  // Quick summary: monthly recurring run-rate + count of one-offs (base currency).
  const monthlyRunRate = rows.filter((r) => r.recurring).reduce((s, r) => s + (Number(r.amount) || 0) * (Number(r.exchange_rate) || 1), 0);

  if (isLoading) return <PageLoader />;

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-[#28364b]"><Wallet className="h-6 w-6" /> Operating expenses</h1>
        <p className="mt-1 text-sm text-slate-500">Business overhead — rent, salaries, software, bank charges. These are subtracted from gross profit in the Accounting report to give your true net profit.</p>
      </div>

      {/* Add / edit form */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-bold text-[#28364b]">{editing ? "Edit expense" : "Add an expense"}</h2>
          {editing && (
            <button onClick={() => setForm(blank())} className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-[#28364b]"><X className="h-3.5 w-3.5" /> Cancel edit</button>
          )}
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Name *">
            <input value={form.name} onChange={(e) => set({ name: e.target.value })} placeholder="e.g. Office rent" className={input} list="oe-cats" />
          </Field>
          <Field label="Category">
            <input value={form.category} onChange={(e) => set({ category: e.target.value })} placeholder="Rent / Salaries / Software…" className={input} list="oe-cats" />
            <datalist id="oe-cats">{CATEGORIES.map((c) => <option key={c} value={c} />)}</datalist>
          </Field>
          <Field label="Amount *">
            <div className="flex items-center gap-1">
              <input type="number" step="0.01" value={form.amount} onChange={(e) => set({ amount: e.target.value })} placeholder="0.00" className={`${input} text-right`} />
              <div className="w-24 shrink-0"><Select value={form.currency} onChange={changeCurrency} options={CURRENCIES} /></div>
            </div>
            {form.currency !== BASE && (
              <div className="mt-1 flex items-center gap-1 text-[10px] text-slate-400">
                × <input type="number" step="0.0001" value={form.exchange_rate} onChange={(e) => set({ exchange_rate: e.target.value })} className="w-20 rounded border border-slate-200 px-1 py-0.5 text-xs" /> → {BASE}
                {rateLoading && <Spinner className="h-3 w-3" />}
              </div>
            )}
          </Field>
          <Field label="Month / date *">
            <DatePicker value={form.effective_date} onChange={(v) => set({ effective_date: v })} placeholder="Effective date" />
          </Field>
          <Field label="Recurring">
            <label className="mt-1 inline-flex cursor-pointer items-center gap-2 text-sm text-slate-600">
              <input type="checkbox" checked={form.recurring} onChange={(e) => set({ recurring: e.target.checked })} className="h-4 w-4 rounded border-slate-300" />
              <span className="inline-flex items-center gap-1"><Repeat className="h-3.5 w-3.5" /> Repeats every month</span>
            </label>
          </Field>
          {form.recurring && (
            <Field label="Until (optional)">
              <DatePicker value={form.end_date} onChange={(v) => set({ end_date: v })} placeholder="No end date" />
            </Field>
          )}
          <Field label="Notes" className="sm:col-span-2 lg:col-span-3">
            <input value={form.notes} onChange={(e) => set({ notes: e.target.value })} placeholder="Optional" className={input} />
          </Field>
        </div>
        <div className="mt-4 flex justify-end">
          <button onClick={() => save.mutate()} disabled={!canSave || save.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-60">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : editing ? <Save className="h-4 w-4" /> : <Plus className="h-4 w-4" />} {editing ? "Save changes" : "Add expense"}
          </button>
        </div>
      </div>

      {/* Summary */}
      {monthlyRunRate > 0 && (
        <div className="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm text-slate-600">
          Recurring overhead run-rate: <span className="font-semibold text-[#28364b]">{money(monthlyRunRate)} {BASE}</span> / month
        </div>
      )}

      {/* List */}
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3 font-semibold">Name</th>
              <th className="px-3 py-3 font-semibold">Category</th>
              <th className="px-3 py-3 text-right font-semibold">Amount</th>
              <th className="px-3 py-3 font-semibold">Date</th>
              <th className="px-3 py-3 font-semibold">Recurring</th>
              <th className="w-20 px-3 py-3"></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                <td className="px-4 py-2.5">
                  <div className="font-medium text-[#28364b]">{r.name}</div>
                  {r.notes && <div className="text-xs text-slate-400">{r.notes}</div>}
                </td>
                <td className="px-3 py-2.5 text-slate-600">{r.category || "—"}</td>
                <td className="px-3 py-2.5 text-right text-slate-700">
                  {money(r.amount)} {r.currency}
                  {r.currency !== BASE && <div className="text-[10px] text-slate-400">≈ {money(Number(r.amount) * (Number(r.exchange_rate) || 1))} {BASE}</div>}
                </td>
                <td className="px-3 py-2.5 text-slate-600">{r.effective_date}</td>
                <td className="px-3 py-2.5">
                  {r.recurring ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700"><Repeat className="h-3 w-3" /> monthly{r.end_date ? ` · to ${r.end_date}` : ""}</span>
                  ) : (
                    <span className="text-xs text-slate-400">one-off</span>
                  )}
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex justify-end gap-1">
                    <button onClick={() => startEdit(r)} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-[#28364b]" title="Edit"><Pencil className="h-4 w-4" /></button>
                    <button onClick={() => remove(r)} className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" title="Delete"><Trash2 className="h-4 w-4" /></button>
                  </div>
                </td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr><td colSpan={6} className="py-10 text-center text-slate-400">No overhead recorded yet. Add rent, salaries, etc. above.</td></tr>
            )}
          </tbody>
        </table>
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
