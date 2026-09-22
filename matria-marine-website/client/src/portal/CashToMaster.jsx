import { useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { motion } from "framer-motion";
import { Banknote, Pencil, Plus, Save, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { cashToMasterAPI, customersAPI } from "@/pages/api";
import { PageLoader, Spinner } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";
import AccountSelect from "./ui/AccountSelect";
import DatePicker from "./ui/DatePicker";
import Select from "./ui/Select";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];
const today = () => new Date().toISOString().slice(0, 10);
const blankForm = () => ({
  id: null,
  transaction_date: today(),
  customer_id: "",
  vessel: "",
  currency: "USD",
  exchange_rate: 1,
  cash_delivered: "",
  transit_cash_incoming: "",
  transit_cash_outgoing: "",
  fee_account_code: "4200",
  fx_account_code: "5200",
  notes: "",
});
const amount = (value) => Number(value) || 0;
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const input = "w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#28364b] focus:ring-1 focus:ring-[#28364b]";

export default function CashToMaster() {
  const confirm = useConfirm();
  const [form, setForm] = useState(blankForm());
  const editing = form.id !== null;
  const set = (patch) => setForm((current) => ({ ...current, ...patch }));

  const recordsQuery = useQuery({
    queryKey: ["cash-to-master"],
    queryFn: async () => (await cashToMasterAPI.list()).data.data,
  });
  const customersQuery = useQuery({
    queryKey: ["customers", "ctm-picker"],
    queryFn: async () => (await customersAPI.list({ active: 1 })).data.data,
  });

  const delivered = amount(form.cash_delivered);
  const incoming = amount(form.transit_cash_incoming);
  const outgoing = amount(form.transit_cash_outgoing);
  const fee = incoming - delivered;
  const feePercentage = delivered > 0 ? (fee / delivered) * 100 : 0;
  const fxExpense = outgoing;
  const profit = fee - fxExpense;

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        transaction_date: form.transaction_date,
        customer_id: Number(form.customer_id),
        vessel: form.vessel.trim() || null,
        currency: form.currency,
        exchange_rate: Number(form.exchange_rate) || 1,
        cash_delivered: delivered,
        transit_cash_incoming: incoming,
        transit_cash_outgoing: outgoing,
        fee_account_code: form.fee_account_code,
        fx_account_code: form.fx_account_code,
        notes: form.notes.trim() || null,
      };
      return editing ? cashToMasterAPI.update(form.id, payload) : cashToMasterAPI.create(payload);
    },
    onSuccess: () => {
      toast.success(editing ? "CTM record updated." : "CTM record saved.");
      setForm(blankForm());
      recordsQuery.refetch();
    },
    onError: (error) => toast.error(error?.response?.data?.message || "Could not save the CTM record."),
  });

  const remove = useMutation({
    mutationFn: (id) => cashToMasterAPI.remove(id),
    onSuccess: () => { toast.success("CTM record removed."); recordsQuery.refetch(); },
    onError: () => toast.error("Could not remove the CTM record."),
  });

  const edit = (record) => {
    setForm({
      id: record.id,
      transaction_date: record.transaction_date,
      customer_id: String(record.customer_id),
      vessel: record.vessel || "",
      currency: record.currency || "USD",
      exchange_rate: record.exchange_rate || 1,
      cash_delivered: String(Number(record.cash_delivered)),
      transit_cash_incoming: String(Number(record.transit_cash_incoming)),
      transit_cash_outgoing: String(Number(record.transit_cash_outgoing)),
      fee_account_code: record.fee_account_code || "4200",
      fx_account_code: record.fx_account_code || "5200",
      notes: record.notes || "",
    });
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  const removeRecord = async (record) => {
    if (await confirm({ title: `Delete ${record.reference}?`, message: "This removes its Sales and FX entries from Accounting.", confirmText: "Delete", tone: "danger" })) {
      remove.mutate(record.id);
    }
  };

  const records = recordsQuery.data || [];
  const totals = records.reduce((sum, record) => ({
    fee: sum.fee + amount(record.fee_amount) * amount(record.exchange_rate || 1),
    fx: sum.fx + amount(record.fx_expense ?? record.fx_variance) * amount(record.exchange_rate || 1),
    profit: sum.profit + amount(record.net_profit) * amount(record.exchange_rate || 1),
  }), { fee: 0, fx: 0, profit: 0 });
  const canSave = form.transaction_date && form.customer_id && delivered >= 0 && incoming >= delivered && outgoing >= 0 && form.cash_delivered !== "" && form.transit_cash_incoming !== "" && form.transit_cash_outgoing !== "";

  if (recordsQuery.isLoading || customersQuery.isLoading) return <PageLoader />;

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="space-y-5">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-[#28364b]"><Banknote className="h-6 w-6" /> Cash to Master</h1>
        <p className="mt-1 text-sm text-slate-500">Record completed CTM transactions. Only Matria&apos;s fee becomes Sales; the entered FX or transfer cost becomes an Expense.</p>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="flex items-center gap-2 text-sm font-bold text-[#28364b]"><Plus className="h-4 w-4" /> {editing ? "Edit CTM record" : "New CTM record"}</h2>
          {editing && <button onClick={() => setForm(blankForm())} className="inline-flex items-center gap-1 text-xs text-slate-500"><X className="h-4 w-4" /> Cancel edit</button>}
        </div>

        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <Field label="Transaction date *"><DatePicker value={form.transaction_date} onChange={(value) => set({ transaction_date: value })} /></Field>
          <Field label="Customer *">
            <Select
              value={form.customer_id}
              onChange={(value) => set({ customer_id: value })}
              placeholder="Select customer"
              searchable
              searchPlaceholder="Search customer number or name..."
              options={(customersQuery.data || []).map((customer) => ({ value: String(customer.id), label: `${customer.customer_no || "—"} — ${customer.name}` }))}
            />
          </Field>
          <Field label="Vessel"><input value={form.vessel} onChange={(e) => set({ vessel: e.target.value })} placeholder="e.g. MV BBC Peru" className={input} /></Field>
          <Field label="Currency">
            <Select value={form.currency} onChange={(value) => set({ currency: value })} options={CURRENCIES} />
          </Field>
        </div>

        <div className="mt-4 grid gap-4 md:grid-cols-3">
          <MoneyField label="Cash delivered to master *" value={form.cash_delivered} onChange={(value) => set({ cash_delivered: value })} currency={form.currency} />
          <MoneyField label="Transit cash incoming *" value={form.transit_cash_incoming} onChange={(value) => set({ transit_cash_incoming: value })} currency={form.currency} />
          <MoneyField label="Transit cash outgoing / FX expense *" value={form.transit_cash_outgoing} onChange={(value) => set({ transit_cash_outgoing: value })} currency={form.currency} />
        </div>
        <p className="mt-2 text-xs text-slate-500">Transit cash outgoing is only the FX loss, transfer fee, or bank charge—not the cash delivered to the master.</p>

        <div className="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-3">
          <Result label={`CTM fee (${feePercentage.toFixed(2)}%)`} value={fee} currency={form.currency} tone="text-blue-700" />
          <Result label="FX loss / transfer cost" value={fxExpense} currency={form.currency} tone="text-amber-700" />
          <Result label="Net CTM profit" value={profit} currency={form.currency} tone={profit >= 0 ? "text-emerald-700" : "text-red-600"} />
        </div>

        <div className="mt-4 grid gap-4 md:grid-cols-2">
          <AccountSelect side="sales" label="CTM fee sales account" value={form.fee_account_code} onChange={(value) => set({ fee_account_code: value })} hint="Only the calculated fee is posted here." />
          <AccountSelect side="purchase" label="FX expense account" value={form.fx_account_code} onChange={(value) => set({ fx_account_code: value })} hint="The entered transit cash outgoing is posted here as an expense." />
        </div>

        <div className="mt-4 grid gap-4 md:grid-cols-[10rem_1fr]">
          <Field label="Rate to base"><input type="number" min="0.00000001" step="0.0001" value={form.exchange_rate} onChange={(e) => set({ exchange_rate: e.target.value })} className={input} /></Field>
          <Field label="Notes"><input value={form.notes} onChange={(e) => set({ notes: e.target.value })} placeholder="Optional transaction notes" className={input} /></Field>
        </div>

        {incoming < delivered && form.transit_cash_incoming !== "" && <p className="mt-3 text-sm text-red-600">Incoming funds cannot be lower than the cash delivered.</p>}
        <div className="mt-4 flex justify-end">
          <button onClick={() => save.mutate()} disabled={!canSave || save.isLoading} className="inline-flex items-center gap-2 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} {editing ? "Save changes" : "Save CTM record"}
          </button>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <Summary label="CTM fee income" value={totals.fee} />
        <Summary label="FX / transfer expense" value={totals.fx} />
        <Summary label="Net CTM profit" value={totals.profit} strong />
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="border-b border-slate-100 px-5 py-3"><h2 className="font-semibold text-[#28364b]">CTM records</h2></div>
        {records.length === 0 ? (
          <div className="py-12 text-center text-sm text-slate-400">No CTM records yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1100px] text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><Th>Date</Th><Th>Reference</Th><Th>Customer</Th><Th>Vessel</Th><Th right>Incoming</Th><Th right>Delivered</Th><Th right>FX expense</Th><Th right>Fee</Th><Th right>FX / transfer cost</Th><Th right>Profit</Th><Th /></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {records.map((record) => (
                  <tr key={record.id} className="hover:bg-slate-50/60">
                    <Td>{record.transaction_date}</Td><Td strong>{record.reference}</Td><Td>{record.customer_name}</Td><Td>{record.vessel || "—"}</Td>
                    <Td right>{money(record.transit_cash_incoming)} {record.currency}</Td><Td right>{money(record.cash_delivered)}</Td><Td right>{money(record.transit_cash_outgoing)}</Td>
                    <Td right className="text-blue-700">{money(record.fee_amount)}</Td><Td right className="text-amber-700">{money(record.fx_expense ?? record.fx_variance)}</Td><Td right strong className={record.net_profit >= 0 ? "text-emerald-700" : "text-red-600"}>{money(record.net_profit)}</Td>
                    <Td><div className="flex justify-end gap-1"><button onClick={() => edit(record)} className="rounded p-1.5 text-slate-400 hover:bg-slate-100"><Pencil className="h-4 w-4" /></button><button onClick={() => removeRecord(record)} className="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"><Trash2 className="h-4 w-4" /></button></div></Td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </motion.div>
  );
}

function Field({ label, children }) { return <div><label className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</label>{children}</div>; }
function MoneyField({ label, value, onChange, currency }) { return <Field label={`${label} (${currency})`}><input type="number" min="0" step="0.01" value={value} onChange={(e) => onChange(e.target.value)} placeholder="0.00" className={`${input} text-right`} /></Field>; }
function Result({ label, value, currency, tone }) { return <div><div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</div><div className={`mt-1 text-xl font-bold ${tone}`}>{money(value)} <span className="text-xs font-medium text-slate-400">{currency}</span></div></div>; }
function Summary({ label, value, strong }) { return <div className={`rounded-xl border p-4 ${strong ? "border-emerald-200 bg-emerald-50" : "border-slate-200 bg-white"}`}><div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</div><div className={`mt-1 text-2xl font-bold ${strong ? "text-emerald-700" : "text-[#28364b]"}`}>{money(value)} <span className="text-xs text-slate-400">base</span></div></div>; }
function Th({ children, right }) { return <th className={`px-4 py-3 font-semibold ${right ? "text-right" : ""}`}>{children}</th>; }
function Td({ children, right, strong, className = "" }) { return <td className={`px-4 py-3 ${right ? "text-right tabular-nums" : ""} ${strong ? "font-semibold text-[#28364b]" : "text-slate-600"} ${className}`}>{children}</td>; }
