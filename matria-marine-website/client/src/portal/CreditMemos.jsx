import { useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { motion } from "framer-motion";
import { Link } from "wouter";
import { ArrowLeft, Download, FileMinus, Plus, Save, Search, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { creditMemosAPI } from "@/pages/api";
import { PageLoader, Spinner } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";
import DatePicker from "./ui/DatePicker";
import { AccountCodeCell } from "./ui/AccountSelect";

/**
 * Credit memos, on their own screen.
 *
 * A memo credits part of an already-issued invoice back to the customer, so
 * everything starts from an invoice: pick one, say how much of each line goes
 * back, save. Still one memo per invoice — choosing an invoice that already has
 * one reopens it rather than starting a second.
 */

const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const cellInput = "rounded border border-slate-200 px-2 py-1 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  issued: "bg-green-100 text-green-700",
};


export default function CreditMemos() {
  const confirm = useConfirm();
  // null = the list; an invoice id = the editor for that invoice's memo.
  const [editing, setEditing] = useState(null);
  const [search, setSearch] = useState("");

  const memos = useQuery({
    queryKey: ["credit-memos"],
    queryFn: async () => (await creditMemosAPI.list()).data.data,
  });

  if (memos.isLoading) return <PageLoader />;

  if (editing !== null) {
    return <MemoEditor invoiceId={editing} onClose={() => { setEditing(null); memos.refetch(); }} />;
  }

  const rows = (memos.data || []).filter((m) => {
    const q = search.trim().toLowerCase();
    if (!q) return true;
    return [m.cm_number, m.invoice_number, m.customer_name].some((v) => (v || "").toLowerCase().includes(q));
  });

  const remove = async (m) => {
    if (m.status !== "draft") {
      toast.error("Only draft credit memos can be deleted.");
      return;
    }
    const ok = await confirm({
      title: `Delete ${m.cm_number}?`,
      message: `This removes the credit against ${m.invoice_number || "its invoice"}, so that sale goes back to its full value in the reports.`,
      confirmText: "Delete",
      tone: "danger",
    });
    if (!ok) return;
    try {
      await creditMemosAPI.remove(m.id);
      toast.success("Credit memo deleted.");
      memos.refetch();
    } catch (e) {
      toast.error(e?.response?.data?.message || "Could not delete the credit memo.");
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Credit Memos</h1>
          <p className="text-sm text-slate-400">
            {rows.length} memo{rows.length === 1 ? "" : "s"} — credits raised against issued invoices.
          </p>
        </div>
        <button
          onClick={() => setEditing(0)}
          className="inline-flex items-center gap-1.5 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63]"
        >
          <Plus className="h-4 w-4" /> New credit memo
        </button>
      </div>

      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search memo number, invoice or customer…"
          className="w-full rounded-lg border border-slate-200 py-2.5 pl-10 pr-3 text-sm outline-none focus:border-[#28364b] focus:ring-1 focus:ring-[#28364b]"
        />
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-4 py-3 font-semibold">Memo</th>
              <th className="px-4 py-3 font-semibold">Date</th>
              <th className="px-4 py-3 font-semibold">Against invoice</th>
              <th className="px-4 py-3 font-semibold">Customer</th>
              <th className="px-4 py-3 font-semibold">Reason</th>
              <th className="px-4 py-3 text-right font-semibold">Credit</th>
              <th className="px-4 py-3 font-semibold">Status</th>
              <th className="w-24 px-4 py-3"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {rows.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-10 text-center text-slate-400">
                  {search ? "Nothing matches that." : "No credit memos yet. Raise one against an issued invoice."}
                </td>
              </tr>
            ) : rows.map((m) => (
              <tr key={m.id} className="hover:bg-slate-50/60">
                <td className="px-4 py-3 font-medium text-[#28364b]">{m.cm_number}</td>
                <td className="px-4 py-3 text-slate-500">{m.memo_date || "—"}</td>
                <td className="px-4 py-3">
                  {m.invoice_id ? (
                    <Link href={`/invoices/${m.invoice_id}`} className="text-[#28364b] underline-offset-2 hover:underline">
                      {m.invoice_number}
                    </Link>
                  ) : "—"}
                </td>
                <td className="px-4 py-3 text-slate-600">{m.customer_name || "—"}</td>
                <td className="px-4 py-3 max-w-[240px] truncate text-slate-500" title={m.reason || ""}>{m.reason || "—"}</td>
                <td className="px-4 py-3 text-right font-semibold text-red-600 whitespace-nowrap">
                  − {money(m.grand_total)} {m.currency}
                </td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[m.status] || "bg-slate-100 text-slate-600"}`}>
                    {m.status}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center justify-end gap-1">
                    <button
                      onClick={() => setEditing(m.invoice_id)}
                      title="Open this credit memo"
                      className="rounded-lg border border-slate-200 px-2 py-1 text-xs font-medium text-slate-600 transition-colors hover:border-[#28364b] hover:text-[#28364b]"
                    >
                      Open
                    </button>
                    {m.status === "draft" && (
                      <button onClick={() => remove(m)} title="Delete this draft" className="rounded-lg p-1.5 text-slate-300 transition-colors hover:bg-red-50 hover:text-red-600">
                        <Trash2 className="h-4 w-4" />
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </motion.div>
  );
}

/**
 * The editor. `invoiceId` of 0 means "none chosen yet" — pick one first, since
 * a memo only exists in relation to an invoice.
 */
function MemoEditor({ invoiceId, onClose }) {
  const confirm = useConfirm();
  const [picked, setPicked] = useState(invoiceId || null);
  const [search, setSearch] = useState("");

  if (!picked) {
    return <InvoicePicker search={search} setSearch={setSearch} onPick={setPicked} onClose={onClose} />;
  }
  return <MemoForm invoiceId={picked} onClose={onClose} confirm={confirm} />;
}

function InvoicePicker({ search, setSearch, onPick, onClose }) {
  const invoices = useQuery({
    queryKey: ["credit-memos", "creditable"],
    queryFn: async () => (await creditMemosAPI.creditableInvoices()).data.data,
  });

  if (invoices.isLoading) return <PageLoader />;

  const rows = (invoices.data || []).filter((i) => {
    const q = search.trim().toLowerCase();
    if (!q) return true;
    return [i.invoice_number, i.customer_name].some((v) => (v || "").toLowerCase().includes(q));
  });

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="space-y-5">
      <button onClick={onClose} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> Back to credit memos
      </button>

      <div>
        <h1 className="text-2xl font-bold text-[#28364b]">Which invoice is being credited?</h1>
        <p className="text-sm text-slate-400">
          Only issued invoices are listed — a draft has not been billed, so there is nothing to credit back.
        </p>
      </div>

      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search invoice number or customer…"
          className="w-full rounded-lg border border-slate-200 py-2.5 pl-10 pr-3 text-sm outline-none focus:border-[#28364b] focus:ring-1 focus:ring-[#28364b]"
        />
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-4 py-3 font-semibold">Invoice</th>
              <th className="px-4 py-3 font-semibold">Date</th>
              <th className="px-4 py-3 font-semibold">Customer</th>
              <th className="px-4 py-3 text-right font-semibold">Total</th>
              <th className="w-40 px-4 py-3"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {rows.length === 0 ? (
              <tr><td colSpan={5} className="px-4 py-10 text-center text-slate-400">No issued invoices match that.</td></tr>
            ) : rows.map((i) => (
              <tr key={i.id} className="hover:bg-slate-50/60">
                <td className="px-4 py-3 font-medium text-[#28364b]">{i.invoice_number}</td>
                <td className="px-4 py-3 text-slate-500">{i.issue_date || "—"}</td>
                <td className="px-4 py-3 text-slate-600">{i.customer_name || "—"}</td>
                <td className="px-4 py-3 text-right whitespace-nowrap">{money(i.grand_total)} {i.currency}</td>
                <td className="px-4 py-3 text-right">
                  <button
                    onClick={() => onPick(i.id)}
                    className="rounded-lg border border-[#28364b] px-3 py-1 text-xs font-semibold text-[#28364b] transition-colors hover:bg-slate-50"
                  >
                    {/* One memo per invoice: this reopens the existing one. */}
                    {i.existing_memo ? `Open ${i.existing_memo.cm_number}` : "Credit this"}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </motion.div>
  );
}

function MemoForm({ invoiceId, onClose, confirm }) {
  const [lines, setLines] = useState(null);
  const [reason, setReason] = useState("");
  const [memoDate, setMemoDate] = useState("");

  const invoice = useQuery({
    queryKey: ["credit-memos", "lines", invoiceId],
    queryFn: async () => (await creditMemosAPI.invoiceLines(invoiceId)).data.data,
    onSuccess: (d) => {
      setLines(d.lines.map((l) => ({ ...l, qty: String(l.qty ?? 0), unit_price: String(l.unit_price ?? 0) })));
      setReason(d.memo?.reason || "");
      setMemoDate(d.memo?.memo_date || new Date().toISOString().slice(0, 10));
    },
  });

  const save = useMutation({
    mutationFn: () =>
      creditMemosAPI.saveForInvoice(invoiceId, {
        memo_date: memoDate || null,
        reason: reason || null,
        lines: (lines || []).map((l) => ({
          customer_invoice_item_id: l.customer_invoice_item_id,
          qty: Number(l.qty) || 0,
          unit_price: Number(l.unit_price) || 0,
          account_code: l.account_code || null,
          reason: l.reason || null,
        })),
      }),
    onSuccess: (res) => { toast.success(res.data.message || "Credit memo saved."); invoice.refetch(); },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the credit memo."),
  });

  const setStatus = useMutation({
    mutationFn: ({ cmId, status }) => creditMemosAPI.update(cmId, { status }),
    onSuccess: (_r, v) => {
      toast.success(v.status === "issued"
        ? "Credit memo issued — it now reduces that sale in the reports."
        : "Back to draft.");
      invoice.refetch();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not update the credit memo."),
  });

  if (invoice.isLoading || lines === null) return <PageLoader />;

  const d = invoice.data;
  const memo = d.memo;
  const issued = memo?.status === "issued";
  const subtotal = lines.reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.unit_price) || 0), 0);
  const tax = (subtotal * (Number(d.tax_rate) || 0)) / 100;
  const total = subtotal + tax;

  const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  const downloadPdf = async () => {
    try {
      const res = await creditMemosAPI.pdf(memo.id);
      const url = URL.createObjectURL(new Blob([res.data], { type: "application/pdf" }));
      const a = document.createElement("a");
      a.href = url;
      a.download = `${memo.cm_number}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download the PDF.");
    }
  };

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="space-y-5">
      <button onClick={onClose} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-[#28364b]">
        <ArrowLeft className="h-4 w-4" /> Back to credit memos
      </button>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-[#28364b]">
            <FileMinus className="h-6 w-6" />
            {memo ? memo.cm_number : "New credit memo"}
            {memo && (
              <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[memo.status]}`}>{memo.status}</span>
            )}
          </h1>
          <p className="text-sm text-slate-400">
            Against{" "}
            <Link href={`/invoices/${d.id}`} className="text-[#28364b] underline-offset-2 hover:underline">{d.invoice_number}</Link>
            {d.customer_name ? ` · ${d.customer_name}` : ""} · {money(d.grand_total)} {d.currency}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {memo && (
            <button onClick={downloadPdf} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50">
              <Download className="h-4 w-4" /> PDF
            </button>
          )}
          {memo && (issued ? (
            <button
              onClick={async () => {
                const ok = await confirm({
                  title: `Put ${memo.cm_number} back to draft?`,
                  message: "The credit stops reducing that sale in the reports until it is issued again.",
                  confirmText: "Back to draft",
                });
                if (ok) setStatus.mutate({ cmId: memo.id, status: "draft" });
              }}
              className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50"
            >
              Back to draft
            </button>
          ) : (
            <button
              onClick={async () => {
                const ok = await confirm({
                  title: `Issue ${memo.cm_number}?`,
                  message: `${money(total)} ${d.currency} comes off ${d.invoice_number} in the reports. The lines are locked once it is issued.`,
                  confirmText: "Issue it",
                });
                if (ok) setStatus.mutate({ cmId: memo.id, status: "issued" });
              }}
              className="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-green-700"
            >
              Issue
            </button>
          ))}
          {!issued && (
            <button
              onClick={() => save.mutate()}
              disabled={save.isLoading}
              className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-70"
            >
              {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save credit memo
            </button>
          )}
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <div className="space-y-1.5">
          <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">Memo date</label>
          <DatePicker value={memoDate} onChange={setMemoDate} disabled={issued} />
        </div>
        <div className="space-y-1.5">
          <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">Overall reason (prints on the memo)</label>
          <input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            disabled={issued}
            placeholder="e.g. Goods damaged in transit"
            className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#28364b] focus:ring-1 focus:ring-[#28364b] disabled:bg-slate-50 disabled:text-slate-400"
          />
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="border-b border-slate-100 px-4 py-3">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Lines to credit</h2>
          <p className="text-xs text-slate-400">
            Set a quantity against the lines being credited. Leave the rest at 0 — a memo with nothing on it is removed when saved.
          </p>
        </div>
        <table className="w-full text-sm">
          <thead className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-4 py-3 font-semibold">Description</th>
              <th className="px-4 py-3 font-semibold">Invoiced</th>
              {/* Defaults to the invoice line's account, because a credit
                  normally undoes the sale where it was made — but it stays
                  editable for the times it has to go somewhere else. */}
              <th className="w-64 px-3 py-3 font-semibold" title="Where this credit is booked — defaults to the account the invoice line was on">Account code</th>
              <th className="w-28 px-3 py-3 text-right font-semibold">Credit qty</th>
              <th className="w-32 px-3 py-3 text-right font-semibold">Credit unit price</th>
              <th className="w-32 px-3 py-3 text-right font-semibold">Credit</th>
              <th className="px-4 py-3 font-semibold">Reason</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {lines.map((l, i) => {
              const credit = (Number(l.qty) || 0) * (Number(l.unit_price) || 0);
              return (
                <tr key={l.customer_invoice_item_id} className="align-top">
                  <td className="px-4 py-3 text-slate-700 whitespace-pre-line">{l.description}</td>
                  <td className="px-4 py-3 text-slate-500 whitespace-nowrap">
                    {Number(l.invoiced_qty)} {l.unit} × {money(l.invoiced_price)}
                  </td>
                  <td className="px-3 py-3">
                    <AccountCodeCell
                      full
                      className={`w-full ${l.account_code ? "" : "ring-1 ring-amber-300"}`}
                      value={l.account_code || ""}
                      onChange={(v) => setLine(i, { account_code: v })}
                      placeholder="Account…"
                    />
                  </td>
                  <td className="px-3 py-3 text-right">
                    {/* Capped at what was invoiced — the server caps it too. */}
                    <input
                      type="number" step="0.001" min="0" max={l.invoiced_qty} disabled={issued}
                      value={l.qty}
                      onChange={(e) => setLine(i, { qty: e.target.value })}
                      className={cellInput + " w-24 text-right disabled:bg-slate-50 disabled:text-slate-400"}
                    />
                  </td>
                  <td className="px-3 py-3 text-right">
                    {/* A discount line was billed as a negative, so its credit
                        runs from the invoiced figure up to zero, not down. */}
                    <input
                      type="number" step="0.01" disabled={issued}
                      min={Number(l.invoiced_price) < 0 ? l.invoiced_price : 0}
                      max={Number(l.invoiced_price) < 0 ? 0 : l.invoiced_price}
                      value={l.unit_price}
                      onChange={(e) => setLine(i, { unit_price: e.target.value })}
                      className={cellInput + " w-28 text-right disabled:bg-slate-50 disabled:text-slate-400"}
                    />
                  </td>
                  <td className="px-3 py-3 text-right font-medium whitespace-nowrap">
                    {credit > 0 ? <span className="text-red-600">− {money(credit)}</span> : <span className="text-slate-300">—</span>}
                  </td>
                  <td className="px-4 py-3">
                    <input
                      value={l.reason || ""}
                      onChange={(e) => setLine(i, { reason: e.target.value })}
                      disabled={issued}
                      placeholder="e.g. damaged / not delivered"
                      className={cellInput + " w-full disabled:bg-slate-50 disabled:text-slate-400"}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
          <tfoot className="border-t-2 border-slate-200 bg-slate-50/60">
            <tr>
              <td colSpan={5} className="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Subtotal</td>
              <td className="px-3 py-2 text-right font-semibold whitespace-nowrap">{money(subtotal)}</td>
              <td />
            </tr>
            {Number(d.tax_rate) > 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-2 text-right text-xs text-slate-500">GST {Number(d.tax_rate)}%</td>
                <td className="px-3 py-2 text-right whitespace-nowrap">{money(tax)}</td>
                <td />
              </tr>
            )}
            <tr className="border-t border-slate-200">
              <td colSpan={5} className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">
                Total credited ({d.currency})
              </td>
              <td className="px-3 py-3 text-right text-base font-bold text-red-600 whitespace-nowrap">− {money(total)}</td>
              <td />
            </tr>
          </tfoot>
        </table>
      </div>
    </motion.div>
  );
}
