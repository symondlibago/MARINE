import { useState, useEffect, useMemo, useRef } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { motion, AnimatePresence } from "framer-motion";
import { Banknote, AlertCircle, Wand2, CheckCircle2, Paperclip, X } from "lucide-react";
import { toast } from "sonner";
import { paymentsAPI } from "@/pages/api";
import Modal from "./ui/Modal";
import Select from "./ui/Select";
import DatePicker from "./ui/DatePicker";
import { Spinner } from "./ui/Loading";

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
const today = () => new Date().toISOString().slice(0, 10);

const METHODS = [
  { value: "bank transfer", label: "Bank transfer" },
  { value: "cheque", label: "Cheque" },
  { value: "cash", label: "Cash" },
  { value: "card", label: "Card" },
  { value: "offset", label: "Offset / contra" },
  { value: "other", label: "Other" },
];

/**
 * Record a bank receipt (customer) or a payment out (vendor) and tick off the
 * documents it settles.
 *
 * The list only ever offers documents that still have a balance, and each row
 * caps at that balance, so the screen cannot build a payment the server will
 * reject. The server re-checks all of it regardless.
 */
export default function PaymentModal({ open, onClose, type, party, onSaved }) {
  const qc = useQueryClient();
  const isCustomer = type === "customer";

  const [date, setDate] = useState(today());
  const [currency, setCurrency] = useState("");
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState("bank transfer");
  const [reference, setReference] = useState("");
  const [bankAccount, setBankAccount] = useState("");
  const [notes, setNotes] = useState("");
  const [picks, setPicks] = useState({});   // { documentId: "amount as typed" }
  // Files are staged here and uploaded once the payment has an id.
  const [files, setFiles] = useState([]);
  const fileInput = useRef(null);

  const { data, isLoading } = useQuery({
    queryKey: ["payment-open-docs", type, party?.id],
    queryFn: async () => (await paymentsAPI.openDocuments(type, party.id)).data.data,
    enabled: open && !!party?.id,
  });

  const documents = useMemo(() => data?.documents || [], [data]);

  // Currencies present among the open documents — a payment settles documents
  // in its own currency only, so the currency choice drives the whole list.
  const currencies = useMemo(
    () => [...new Set(documents.map((d) => d.currency))].sort(),
    [documents]
  );

  // Reset each time the modal opens; default to whichever currency is owed most.
  useEffect(() => {
    if (!open) return;
    setDate(today());
    setAmount("");
    setMethod("bank transfer");
    setReference("");
    setBankAccount("");
    setNotes("");
    setPicks({});
    setFiles([]);
  }, [open, party?.id]);

  useEffect(() => {
    if (!open || currencies.length === 0) return;
    setCurrency((c) => (currencies.includes(c) ? c : currencies[0]));
  }, [open, currencies]);

  // Switching currency invalidates every tick — those documents are gone.
  useEffect(() => { setPicks({}); }, [currency]);

  const visible = documents.filter((d) => d.currency === currency);

  const applied = round2(
    Object.entries(picks).reduce((sum, [, v]) => sum + (parseFloat(v) || 0), 0)
  );
  const paymentAmount = round2(parseFloat(amount) || 0);
  const unapplied = round2(paymentAmount - applied);
  const overApplied = applied > paymentAmount + 0.005;

  const setPick = (doc, raw) => {
    setPicks((p) => {
      const next = { ...p };
      if (raw === "" || raw === null) {
        delete next[doc.id];
        return next;
      }
      // Never let a row exceed what that document actually owes.
      const capped = Math.min(parseFloat(raw) || 0, doc.outstanding);
      next[doc.id] = capped > 0 ? String(round2(capped)) : "";
      if (!next[doc.id]) delete next[doc.id];
      return next;
    });
  };

  const toggle = (doc) => {
    setPicks((p) => {
      const next = { ...p };
      if (next[doc.id] !== undefined) delete next[doc.id];
      else next[doc.id] = String(doc.outstanding);
      return next;
    });
  };

  /** Spread the amount over the oldest documents first — how a bank receipt
   *  is normally reconciled when the customer doesn't say what it's for. */
  const autoApply = () => {
    let left = paymentAmount;
    if (left <= 0) {
      toast.error("Enter the amount received first.");
      return;
    }
    const next = {};
    for (const d of visible) {
      if (left <= 0.005) break;
      const take = round2(Math.min(left, d.outstanding));
      if (take > 0) {
        next[d.id] = String(take);
        left = round2(left - take);
      }
    }
    setPicks(next);
    if (left > 0.005) {
      toast.info(`${money(left)} ${currency} left over — it will sit as unapplied until more invoices are raised.`);
    }
  };

  const save = useMutation({
    mutationFn: async (payload) => {
      const res = await paymentsAPI.create(payload);
      const id = res.data?.data?.id;

      // The payment is already saved at this point. If the bank slip upload
      // fails, say so and keep the payment rather than pretending it all went
      // wrong — the money entry is the part that matters.
      if (id && files.length) {
        try {
          const form = new FormData();
          files.forEach((f) => form.append("files[]", f));
          form.append("kind", "bank_slip");
          await paymentsAPI.uploadFiles(id, form);
        } catch (e) {
          toast.error(
            e?.response?.data?.message ||
              "The payment was saved, but the file(s) did not upload. Attach them again from the statement."
          );
        }
      }
      return res;
    },
    onSuccess: (res) => {
      toast.success(res.data.message || "Payment recorded.");
      qc.invalidateQueries({ queryKey: ["statement"] });
      qc.invalidateQueries({ queryKey: ["statement-parties"] });
      qc.invalidateQueries({ queryKey: ["payment-open-docs"] });
      onSaved?.();
      onClose();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not record the payment."),
  });

  const addFiles = (list) => {
    const picked = Array.from(list || []);
    const tooBig = picked.filter((f) => f.size > 10 * 1024 * 1024);
    if (tooBig.length) toast.error(`${tooBig[0].name} is over 10 MB.`);
    setFiles((prev) => [...prev, ...picked.filter((f) => f.size <= 10 * 1024 * 1024)].slice(0, 10));
    if (fileInput.current) fileInput.current.value = "";
  };

  const submit = () => {
    if (paymentAmount <= 0) return toast.error("Enter the amount that hit the bank.");
    if (overApplied) return toast.error("You have applied more than the payment amount.");

    save.mutate({
      party_type: type,
      party_id: party.id,
      payment_date: date,
      currency,
      amount: paymentAmount,
      method,
      reference: reference || null,
      bank_account: bankAccount || null,
      notes: notes || null,
      allocations: Object.entries(picks)
        .map(([id, v]) => ({ document_id: Number(id), amount: round2(parseFloat(v) || 0) }))
        .filter((a) => a.amount > 0),
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      maxWidth="max-w-4xl"
      title={isCustomer ? `Record a payment received — ${party?.name || ""}` : `Record a payment made — ${party?.name || ""}`}
    >
      <div className="space-y-5 p-6">
        {/* ---------- what hit the bank ---------- */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Date received">
            <DatePicker value={date} onChange={setDate} placeholder="Date" />
          </Field>

          <Field label="Currency">
            {currencies.length > 1 ? (
              <Select value={currency} onChange={setCurrency} options={currencies} placeholder="Currency" />
            ) : (
              <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-[#28364b]">
                {currency || "—"}
              </div>
            )}
          </Field>

          <Field label="Amount">
            <input
              type="number"
              step="0.01"
              min="0"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              placeholder="0.00"
              className="w-full rounded-lg border border-slate-200 px-3 py-2 text-right text-sm font-semibold tabular-nums focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
            />
          </Field>

          <Field label="Method">
            <Select value={method} onChange={setMethod} options={METHODS} placeholder="Method" />
          </Field>

          <Field label="Bank reference" hint="from the bank slip">
            <input
              value={reference}
              onChange={(e) => setReference(e.target.value)}
              placeholder="e.g. 2019-USD-1591"
              className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
            />
          </Field>

          <Field label="Bank account">
            <input
              value={bankAccount}
              onChange={(e) => setBankAccount(e.target.value)}
              placeholder="e.g. DBS - USD"
              className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
            />
          </Field>
        </div>

        {/* ---------- what it settles ---------- */}
        <div>
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <div className="text-xs font-bold uppercase tracking-wider text-slate-400">
              Apply to {isCustomer ? "invoices" : "purchase orders"}
            </div>
            <button
              type="button"
              onClick={autoApply}
              disabled={visible.length === 0}
              className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-40"
            >
              <Wand2 className="h-3.5 w-3.5" /> Apply to oldest first
            </button>
          </div>

          <div className="max-h-72 overflow-y-auto rounded-xl border border-slate-200">
            {isLoading ? (
              <div className="flex items-center justify-center py-10"><Spinner className="h-5 w-5" /></div>
            ) : visible.length === 0 ? (
              <p className="px-4 py-10 text-center text-sm text-slate-400">
                Nothing outstanding in {currency || "this currency"}. You can still record the payment — it will sit
                unapplied until there is something to match it to.
              </p>
            ) : (
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-slate-50">
                  <tr className="border-b border-slate-200 text-left text-[11px] uppercase tracking-wide text-slate-500">
                    <th className="px-3 py-2 font-semibold">{isCustomer ? "Invoice" : "PO"}</th>
                    <th className="px-3 py-2 font-semibold">Due</th>
                    <th className="px-3 py-2 text-right font-semibold">Outstanding</th>
                    <th className="px-3 py-2 text-right font-semibold">Apply</th>
                  </tr>
                </thead>
                <tbody>
                  {visible.map((d) => {
                    const on = picks[d.id] !== undefined;
                    return (
                      <tr key={d.id} className={`border-b border-slate-100 last:border-0 ${on ? "bg-[#28364b]/5" : ""}`}>
                        <td className="px-3 py-2">
                          <button
                            type="button"
                            onClick={() => toggle(d)}
                            className="flex items-start gap-2 text-left"
                          >
                            <span
                              className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border transition-colors ${
                                on ? "border-[#28364b] bg-[#28364b] text-white" : "border-slate-300"
                              }`}
                            >
                              {on && <CheckCircle2 className="h-3 w-3" />}
                            </span>
                            <span>
                              <span className="font-medium text-[#28364b]">{d.number}</span>
                              {d.allocated > 0 && (
                                <span className="ml-1.5 rounded bg-blue-50 px-1 py-0.5 text-[10px] font-semibold text-blue-700">
                                  part-paid
                                </span>
                              )}
                              {d.vessel && <span className="block text-[11px] text-slate-400">{d.vessel}</span>}
                            </span>
                          </button>
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{d.due_date || d.date || "—"}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-right text-xs tabular-nums text-slate-500">
                          {money(d.outstanding)}
                        </td>
                        <td className="px-3 py-2 text-right">
                          <input
                            type="number"
                            step="0.01"
                            min="0"
                            max={d.outstanding}
                            value={picks[d.id] ?? ""}
                            onChange={(e) => setPick(d, e.target.value)}
                            placeholder="—"
                            className="w-28 rounded-lg border border-slate-200 px-2 py-1 text-right text-sm tabular-nums focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
                          />
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>

        {/* ---------- running reconciliation ---------- */}
        <div className={`rounded-xl border p-3.5 ${overApplied ? "border-red-200 bg-red-50" : "border-slate-200 bg-slate-50"}`}>
          <div className="grid grid-cols-3 gap-3 text-center">
            <Stat label="Payment" value={`${currency} ${money(paymentAmount)}`} />
            <Stat label="Applied" value={`${currency} ${money(applied)}`} />
            <Stat
              label={unapplied < 0 ? "Over by" : "Unapplied"}
              value={`${currency} ${money(Math.abs(unapplied))}`}
              tone={overApplied ? "bad" : unapplied > 0.005 ? "warn" : "good"}
            />
          </div>
          <AnimatePresence>
            {overApplied && (
              <motion.p
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: "auto" }}
                exit={{ opacity: 0, height: 0 }}
                className="mt-2 flex items-center justify-center gap-1.5 text-xs font-medium text-red-700"
              >
                <AlertCircle className="h-3.5 w-3.5" />
                You have applied more than the payment. Reduce a line, or raise the amount.
              </motion.p>
            )}
          </AnimatePresence>
          {!overApplied && unapplied > 0.005 && (
            <p className="mt-2 text-center text-xs text-slate-500">
              {money(unapplied)} {currency} will be held on account against this {type}.
            </p>
          )}
        </div>

        {/* ---------- bank slip / invoice copy ---------- */}
        <div>
          <div className="mb-1.5 text-xs font-bold uppercase tracking-wider text-slate-400">
            Bank slip / invoice copy
            <span className="ml-1 font-normal normal-case tracking-normal text-slate-300">· optional</span>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => fileInput.current?.click()}
              className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-xs font-medium text-slate-500 transition-colors hover:border-[#28364b] hover:text-[#28364b]"
            >
              <Paperclip className="h-3.5 w-3.5" /> Attach file
            </button>
            <input
              ref={fileInput}
              type="file"
              multiple
              accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp"
              onChange={(e) => addFiles(e.target.files)}
              className="hidden"
            />
            {files.map((f, i) => (
              <span
                key={`${f.name}-${i}`}
                className="inline-flex max-w-[220px] items-center gap-1.5 rounded-lg bg-slate-100 py-1.5 pl-2.5 pr-1.5 text-xs text-slate-600"
              >
                <span className="truncate">{f.name}</span>
                <button
                  type="button"
                  onClick={() => setFiles((prev) => prev.filter((_, j) => j !== i))}
                  className="rounded p-0.5 text-slate-400 transition-colors hover:bg-slate-200 hover:text-red-600"
                >
                  <X className="h-3 w-3" />
                </button>
              </span>
            ))}
          </div>
        </div>

        <textarea
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          rows={2}
          placeholder="Notes (optional) — e.g. what the bank slip says"
          className="w-full resize-y rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
        />

        <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button
            onClick={onClose}
            className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            onClick={submit}
            disabled={save.isLoading || paymentAmount <= 0 || overApplied}
            className="inline-flex items-center gap-1.5 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#3c4a63] disabled:opacity-50"
          >
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Banknote className="h-4 w-4" />}
            Record payment
          </button>
        </div>
      </div>
    </Modal>
  );
}

function Field({ label, hint, children }) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-semibold text-slate-500">
        {label}
        {hint && <span className="ml-1 font-normal text-slate-300">{hint}</span>}
      </span>
      {children}
    </label>
  );
}

function Stat({ label, value, tone }) {
  const color =
    tone === "bad" ? "text-red-700" : tone === "warn" ? "text-amber-700" : tone === "good" ? "text-green-700" : "text-[#28364b]";
  return (
    <div>
      <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{label}</div>
      <div className={`text-base font-bold tabular-nums ${color}`}>{value}</div>
    </div>
  );
}
