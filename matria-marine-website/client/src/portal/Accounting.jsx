import { useState, useMemo, Fragment } from "react";
import { useQuery } from "@tanstack/react-query";
import { motion, AnimatePresence } from "framer-motion";
import {
  BookOpen, Receipt, ShoppingCart, Percent, Scale, PieChart, Landmark,
  Clock, Users, Download, ChevronRight, AlertTriangle, Search, Check,
} from "lucide-react";
import { accountingAPI } from "@/pages/api";
import { PageLoader } from "./ui/Loading";
import DatePicker from "./ui/DatePicker";
import { downloadCsv, rangedFilename } from "./ui/exportCsv";
import { useDebounced } from "./ui/useDebounced";

/* ------------------------------ helpers ------------------------------ */

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const pct = (n) => (n == null ? "—" : `${Number(n).toFixed(1)}%`);

const PRESETS = [
  { key: "month", label: "This month" },
  { key: "quarter", label: "This quarter" },
  { key: "year", label: "This year" },
  { key: "all", label: "All time" },
];

const ymd = (d) => {
  const z = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
  return z.toISOString().slice(0, 10);
};

function presetRange(key) {
  const today = new Date();
  if (key === "month") return { from: ymd(new Date(today.getFullYear(), today.getMonth(), 1)), to: ymd(today) };
  if (key === "quarter") {
    // GST is filed quarterly, so "this quarter" means the calendar quarter the
    // return will actually cover — not the last 90 days.
    const q = Math.floor(today.getMonth() / 3) * 3;
    return { from: ymd(new Date(today.getFullYear(), q, 1)), to: ymd(today) };
  }
  if (key === "year") return { from: ymd(new Date(today.getFullYear(), 0, 1)), to: ymd(today) };
  return { from: "", to: "" };
}

function params(range, extra = {}) {
  const p = { ...extra };
  if (range.from) p.from = range.from;
  if (range.to) p.to = range.to;
  Object.keys(p).forEach((k) => (p[k] === "" || p[k] == null) && delete p[k]);
  return p;
}

/* --------------------------- shared pieces --------------------------- */

function Card({ title, subtitle, action, children, className = "" }) {
  return (
    <div className={`rounded-xl border border-slate-200 bg-white ${className}`}>
      {(title || action) && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3.5">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">{title}</h2>
            {subtitle && <p className="mt-0.5 text-xs text-slate-400">{subtitle}</p>}
          </div>
          {action}
        </div>
      )}
      {children}
    </div>
  );
}

/** Wide accounting tables scroll inside their own box; the page never does. */
function Scroller({ children }) {
  return <div className="overflow-x-auto">{children}</div>;
}

/**
 * `...rest` is not optional here: it is what carries `colSpan` and `title`
 * through to the real element. Without it every colSpan on a totals row is
 * dropped, the row silently collapses to one cell per figure, and the totals
 * line up under the wrong headings.
 */
const TH = ({ children, right, className = "", ...rest }) => (
  <th
    {...rest}
    className={`whitespace-nowrap px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500 ${
      right ? "text-right" : "text-left"
    } ${className}`}
  >
    {children}
  </th>
);

const TD = ({ children, right, mono, className = "", ...rest }) => (
  <td
    {...rest}
    className={`whitespace-nowrap px-3 py-2 ${right ? "text-right" : ""} ${
      mono ? "tabular-nums" : ""
    } ${className}`}
  >
    {children}
  </td>
);

/** Red for negatives — a credit note or a loss should read as one at a glance. */
const Amount = ({ value, bold, zeroDash }) => {
  const n = Number(value || 0);
  if (zeroDash && n === 0) return <span className="text-slate-300">—</span>;
  return (
    <span className={`tabular-nums ${n < 0 ? "text-red-600" : bold ? "text-[#28364b]" : "text-slate-700"} ${bold ? "font-semibold" : ""}`}>
      {money(n)}
    </span>
  );
};

function Badge({ children, tone = "slate" }) {
  const tones = {
    slate: "bg-slate-100 text-slate-600",
    green: "bg-green-100 text-green-700",
    amber: "bg-amber-100 text-amber-700",
    red: "bg-red-100 text-red-700",
    blue: "bg-blue-100 text-blue-700",
  };
  return (
    <span className={`inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold ${tones[tone] || tones.slate}`}>
      {children}
    </span>
  );
}

const GST_TONE = { SR: "blue", ZI: "green", ESN: "amber", OS: "slate" };

function Empty({ children = "Nothing in this period." }) {
  return <div className="px-5 py-10 text-center text-sm text-slate-400">{children}</div>;
}

function Note({ children, tone = "slate" }) {
  const tones = {
    slate: "border-slate-200 bg-slate-50 text-slate-500",
    amber: "border-amber-200 bg-amber-50 text-amber-800",
  };
  return (
    <div className={`flex items-start gap-2 rounded-lg border px-3.5 py-2.5 text-xs ${tones[tone]}`}>
      {tone === "amber" && <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />}
      <span>{children}</span>
    </div>
  );
}

function ExportButton({ onClick, disabled }) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className="flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
    >
      <Download className="h-3.5 w-3.5" /> Export CSV
    </button>
  );
}

function SearchBox({ value, onChange, placeholder }) {
  return (
    <div className="relative">
      <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
      <input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-56 rounded-lg border border-slate-200 py-1.5 pl-8 pr-2.5 text-xs outline-none focus:border-[#28364b]"
      />
    </div>
  );
}

/** One query per section, with the shell handling loading and errors once. */
function useSection(key, fn, range, extra = {}) {
  return useQuery({
    queryKey: ["accounting", key, range.from, range.to, JSON.stringify(extra)],
    queryFn: async () => (await fn(params(range, extra))).data.data,
    keepPreviousData: true,
  });
}

function Section({ query, children }) {
  if (query.isLoading) return <PageLoader label="Loading…" />;
  if (query.isError) {
    return (
      <Note tone="amber">
        Could not load this section. {query.error?.response?.data?.message || query.error?.message || ""}
      </Note>
    );
  }
  return children(query.data);
}

/* =========================== 1. Chart =========================== */

function ChartOfAccounts() {
  const q = useQuery({
    queryKey: ["accounting", "chart"],
    queryFn: async () => (await accountingAPI.chart()).data.data,
  });

  return (
    <Section query={q}>
      {(d) => (
        <div className="space-y-4">
          <Note>
            The account code on a document is the only thing recorded — its GST treatment is read
            from this chart when a report runs, so the two can never disagree.
          </Note>

          <Card
            title="Chart of accounts"
            subtitle={`${d.accounts.length} accounts`}
            action={
              <ExportButton
                onClick={() =>
                  downloadCsv(
                    "chart-of-accounts.csv",
                    ["Code", "Name", "Type", "GST code", "GST treatment", "Normal balance", "Statement"],
                    d.accounts.map((a) => [a.code, a.name, a.type_label, a.gst_code, a.gst_label, a.normal_balance, a.statement])
                  )
                }
              />
            }
          >
            <Scroller>
              <table className="w-full text-sm">
                <thead className="border-b border-slate-100 bg-slate-50/60">
                  <tr>
                    <TH>Code</TH><TH>Account</TH><TH>Type</TH><TH>GST</TH>
                    <TH>Treatment</TH><TH>Normal balance</TH><TH>Statement</TH><TH>Description</TH>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {d.accounts.map((a) => (
                    <tr key={a.code} className="hover:bg-slate-50/60">
                      <TD mono className="font-semibold text-[#28364b]">{a.code}</TD>
                      <TD className="font-medium">{a.name}</TD>
                      <TD className="text-slate-500">{a.type_label}</TD>
                      <TD><Badge tone={GST_TONE[a.gst_code]}>{a.gst_code}</Badge></TD>
                      <TD className="text-slate-500">{a.gst_label}</TD>
                      <TD className="capitalize text-slate-500">{a.normal_balance}</TD>
                      <TD className="text-slate-500">
                        {a.statement === "balance_sheet" ? "Balance sheet" : "Income statement"}
                      </TD>
                      <TD className="max-w-md whitespace-normal text-xs text-slate-400">{a.description}</TD>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Scroller>
          </Card>

          <Card title="GST codes" subtitle="Fixed by IRAS — which return box each one feeds">
            <Scroller>
              <table className="w-full text-sm">
                <thead className="border-b border-slate-100 bg-slate-50/60">
                  <tr><TH>Code</TH><TH>Treatment</TH><TH>Sales box</TH><TH>Purchase box</TH><TH>Meaning</TH></tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {d.gst_codes.map((g) => (
                    <tr key={g.code}>
                      <TD><Badge tone={GST_TONE[g.code]}>{g.code}</Badge></TD>
                      <TD className="font-medium">{g.label}</TD>
                      <TD className="text-slate-500">{g.sales_box ? `Box ${g.sales_box}` : "—"}</TD>
                      <TD className="text-slate-500">{g.purchase_box ? `Box ${g.purchase_box}` : "—"}</TD>
                      <TD className="max-w-lg whitespace-normal text-xs text-slate-400">{g.description}</TD>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Scroller>
          </Card>
        </div>
      )}
    </Section>
  );
}

/* ====================== 2 & 3. Registers ====================== */

function CurrencyWarning({ data }) {
  if (!data.multi_currency) return null;
  return (
    <Note tone="amber">
      These documents are in {data.currencies.join(", ")}. Totals add the face values together —
      customer invoices do not yet carry an exchange rate, so treat a mixed-currency total as
      indicative until the posting rate is recorded.
    </Note>
  );
}

function UnclassifiedWarning({ count, what }) {
  if (!count) return null;
  return (
    <Note tone="amber">
      {count} {what} {count === 1 ? "is" : "are"} on an account code outside the chart. They appear
      here but are left out of every GST box — pick a real account on the document to bring them in.
    </Note>
  );
}

function SalesRegister({ range }) {
  const [term, setTerm] = useState("");
  const search = useDebounced(term, 300);
  const q = useSection("sales", accountingAPI.salesInvoices, range, { q: search });

  return (
    <Section query={q}>
      {(d) => (
        <div className="space-y-4">
          <CurrencyWarning data={d} />
          <UnclassifiedWarning count={d.unclassified} what="sales document(s)" />

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Documents" value={`${d.totals.invoices} inv · ${d.totals.credit_notes} CN`} />
            <Stat label="Net of GST" value={money(d.totals.net)} />
            <Stat label="GST charged" value={money(d.totals.tax)} />
            <Stat label="Outstanding" value={money(d.totals.outstanding)} tone={d.totals.outstanding > 0 ? "amber" : "green"} />
          </div>

          <Card
            title="Sales invoices & income credits"
            subtitle={`${d.totals.count} document(s) · gross ${money(d.totals.gross)}`}
            action={
              <div className="flex items-center gap-2">
                <SearchBox value={term} onChange={setTerm} placeholder="Invoice, customer, vessel…" />
                <ExportButton
                  disabled={!d.rows.length}
                  onClick={() =>
                    downloadCsv(
                      rangedFilename("sales-invoices", range),
                      ["Date", "Type", "Number", "Party no", "Customer / Vendor", "Customer ref", "Enquiry", "Vessel",
                        "Currency", "Account", "Account name", "GST code", "F5 box", "Subtotal", "Delivery",
                        "Net", "GST %", "GST amount", "Gross", "Status", "Settled", "Outstanding"],
                      d.rows.map((r) => [r.date, r.kind_label, r.number, r.party_no, r.party_name, r.party_reference,
                        r.reference, r.vessel, r.currency, r.account_code, r.account_name, r.gst_code, r.f5_box,
                        r.subtotal, r.delivery, r.net, r.tax_rate, r.tax_amount, r.gross, r.status, r.settled, r.outstanding])
                    )
                  }
                />
              </div>
            }
          >
            {!d.rows.length ? (
              <Empty />
            ) : (
              <Scroller>
                <table className="w-full text-sm">
                  <thead className="border-b border-slate-100 bg-slate-50/60">
                    <tr>
                      <TH>Date</TH><TH>Type</TH><TH>Number</TH><TH>Party #</TH><TH>Customer / Vendor</TH>
                      <TH>Cust. ref</TH><TH>Enquiry</TH><TH>Vessel</TH><TH>Cur</TH>
                      <TH>Account</TH><TH>GST</TH><TH>Box</TH>
                      <TH right>Subtotal</TH><TH right>Delivery</TH><TH right>Net</TH>
                      <TH right>GST %</TH><TH right>GST</TH><TH right>Gross</TH>
                      {/* Net/Gross above are the SALE. These two say what the
                          customer was actually invoiced and how much of that
                          was never ours — a CTM principal, for instance. */}
                      <TH right title="What the customer was invoiced, including anything only passing through">Billed</TH>
                      <TH right title="Collected on someone else's behalf — not a sale, not in any GST box">Pass-thru</TH>
                      <TH>Status</TH><TH right>Settled</TH><TH right>Outstanding</TH>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50">
                    {d.rows.map((r) => (
                      <tr key={`${r.kind}-${r.id}`} className="hover:bg-slate-50/60">
                        <TD className="text-slate-500">{r.date || "—"}</TD>
                        <TD>
                          {r.kind === "vendor_credit_note"
                            ? <Badge tone="green">Vendor credit</Badge>
                            : r.kind === "credit_memo"
                              ? <Badge tone="red">Credit note</Badge>
                              : <Badge tone="blue">Invoice</Badge>}
                        </TD>
                        <TD mono className="font-medium text-[#28364b]">{r.number}</TD>
                        <TD mono className="text-slate-500">{r.party_no || "—"}</TD>
                        <TD className="max-w-[16rem] truncate" title={r.party_name}>{r.party_name || "—"}</TD>
                        <TD className="text-slate-500">{r.party_reference || "—"}</TD>
                        <TD className="text-slate-500">{r.reference || "—"}</TD>
                        <TD className="text-slate-500">{r.vessel || "—"}</TD>
                        <TD className="text-slate-500">{r.currency}</TD>
                        <TD>
                          <span className="font-medium text-[#28364b]">{r.account_code || "—"}</span>
                          <span className="ml-1.5 text-xs text-slate-400">{r.account_name || "not on chart"}</span>
                        </TD>
                        <TD>{r.gst_code ? <Badge tone={GST_TONE[r.gst_code]}>{r.gst_code}</Badge> : <Badge tone="red">?</Badge>}</TD>
                        <TD className="text-slate-500">{r.f5_box ? `Box ${r.f5_box}` : "—"}</TD>
                        <TD right><Amount value={r.subtotal} /></TD>
                        <TD right><Amount value={r.delivery} zeroDash /></TD>
                        <TD right><Amount value={r.net} bold /></TD>
                        <TD right className="text-slate-500">{Number(r.tax_rate || 0)}%</TD>
                        <TD right><Amount value={r.tax_amount} zeroDash /></TD>
                        <TD right><Amount value={r.gross} /></TD>
                        <TD right className={Number(r.pass_through_net) ? "" : "text-slate-400"}>
                          <Amount value={r.billed_gross ?? r.gross} />
                        </TD>
                        <TD right>
                          {Number(r.pass_through_net)
                            ? <span className="font-medium text-amber-700"><Amount value={r.pass_through_net} /></span>
                            : <span className="text-slate-300">—</span>}
                        </TD>
                        <TD>
                          <Badge tone={r.paid ? "green" : r.status === "draft" ? "slate" : "amber"}>{r.status}</Badge>
                        </TD>
                        <TD right><Amount value={r.settled} zeroDash /></TD>
                        <TD right><Amount value={r.outstanding} zeroDash /></TD>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="border-t-2 border-slate-200 bg-slate-50/60 font-semibold">
                    <tr>
                      <TD className="text-xs uppercase tracking-wide text-slate-500" colSpan={12}>Total</TD>
                      <TD right colSpan={2} />
                      <TD right><Amount value={d.totals.net} bold /></TD>
                      <TD right />
                      <TD right><Amount value={d.totals.tax} bold /></TD>
                      <TD right><Amount value={d.totals.gross} bold /></TD>
                      <TD right><Amount value={d.totals.billed} bold /></TD>
                      <TD right>
                        {Number(d.totals.pass_through)
                          ? <span className="font-bold text-amber-700"><Amount value={d.totals.pass_through} /></span>
                          : <span className="text-slate-300">—</span>}
                      </TD>
                      <TD />
                      <TD right><Amount value={d.totals.settled} bold /></TD>
                      <TD right><Amount value={d.totals.outstanding} bold /></TD>
                    </tr>
                  </tfoot>
                </table>
              </Scroller>
            )}
          </Card>

          <ByAccount rows={d.by_account} />
        </div>
      )}
    </Section>
  );
}

function PurchaseRegister({ range }) {
  const [term, setTerm] = useState("");
  const search = useDebounced(term, 300);
  const q = useSection("purchases", accountingAPI.purchaseInvoices, range, { q: search });

  return (
    <Section query={q}>
      {(d) => (
        <div className="space-y-4">
          <CurrencyWarning data={d} />
          <UnclassifiedWarning count={d.unclassified} what="purchase document(s)" />

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Documents" value={`${d.totals.orders} PO · ${d.totals.expenses} overhead`} />
            <Stat label="Net of GST" value={money(d.totals.net)} />
            <Stat label="GST paid" value={money(d.totals.tax)} />
            <Stat label="Unpaid" value={money(d.totals.outstanding)} tone={d.totals.outstanding > 0 ? "amber" : "green"} />
          </div>

          <Card
            title="Purchase invoices"
            subtitle={`${d.totals.count} document(s) · job expenses ${money(d.totals.job_expenses)}`}
            action={
              <div className="flex items-center gap-2">
                <SearchBox value={term} onChange={setTerm} placeholder="PO, vendor, vessel…" />
                <ExportButton
                  disabled={!d.rows.length}
                  onClick={() =>
                    downloadCsv(
                      rangedFilename("purchase-invoices", range),
                      ["Date", "Type", "Number", "Vendor invoice", "Vendor no", "Vendor", "Enquiry", "Vessel",
                        "Currency", "Rate", "Account", "Account name", "GST code", "F5 box", "Net", "Expenses",
                        "GST %", "GST amount", "Gross", "Status", "Settled", "Outstanding"],
                      d.rows.map((r) => [r.date, r.kind_label, r.number, r.vendor_invoice_number, r.party_no,
                        r.party_name, r.reference, r.vessel, r.currency, r.exchange_rate, r.account_code,
                        r.account_name, r.gst_code, r.f5_box, r.net, r.expenses, r.tax_rate, r.tax_amount,
                        r.gross, r.status, r.settled, r.outstanding])
                    )
                  }
                />
              </div>
            }
          >
            {!d.rows.length ? (
              <Empty />
            ) : (
              <Scroller>
                <table className="w-full text-sm">
                  <thead className="border-b border-slate-100 bg-slate-50/60">
                    <tr>
                      <TH>Date</TH><TH>Type</TH><TH>Number</TH><TH>Vendor inv.</TH><TH>Vend #</TH>
                      <TH>Vendor</TH><TH>Enquiry</TH><TH>Vessel</TH><TH>Cur</TH><TH right>Rate</TH>
                      <TH>Account</TH><TH>GST</TH><TH>Box</TH>
                      <TH right>Net</TH><TH right>Expenses</TH><TH right>GST %</TH><TH right>GST</TH>
                      <TH right>Gross</TH><TH>Status</TH><TH right>Outstanding</TH>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50">
                    {d.rows.map((r) => (
                      <tr key={`${r.kind}-${r.id}`} className="hover:bg-slate-50/60">
                        <TD className="text-slate-500">{r.date || "—"}</TD>
                        <TD>
                          <Badge tone={r.kind === "operating_expense" ? "amber" : "slate"}>
                            {r.kind === "operating_expense" ? "Overhead" : r.kind === "payroll" ? "Payroll" : "PO"}
                          </Badge>
                        </TD>
                        <TD mono className="font-medium text-[#28364b]">{r.number}</TD>
                        <TD mono className="text-slate-500">{r.vendor_invoice_number || "—"}</TD>
                        <TD mono className="text-slate-500">{r.party_no || "—"}</TD>
                        <TD className="max-w-[16rem] truncate" title={r.party_name}>{r.party_name || "—"}</TD>
                        <TD className="text-slate-500">{r.reference || "—"}</TD>
                        <TD className="text-slate-500">{r.vessel || "—"}</TD>
                        <TD className="text-slate-500">{r.currency}</TD>
                        <TD right className="tabular-nums text-slate-400">{Number(r.exchange_rate || 1).toFixed(4)}</TD>
                        <TD>
                          <span className="font-medium text-[#28364b]">{r.account_code || "—"}</span>
                          <span className="ml-1.5 text-xs text-slate-400">{r.account_name || "not on chart"}</span>
                        </TD>
                        <TD>{r.gst_code ? <Badge tone={GST_TONE[r.gst_code]}>{r.gst_code}</Badge> : <Badge tone="red">?</Badge>}</TD>
                        <TD className="text-slate-500">{r.f5_box ? `Box ${r.f5_box}` : "—"}</TD>
                        <TD right><Amount value={r.net} bold /></TD>
                        <TD right><Amount value={r.expenses} zeroDash /></TD>
                        <TD right className="text-slate-500">{Number(r.tax_rate || 0)}%</TD>
                        <TD right><Amount value={r.tax_amount} zeroDash /></TD>
                        <TD right><Amount value={r.gross} /></TD>
                        <TD><Badge tone={r.paid ? "green" : "amber"}>{r.paid ? "paid" : r.status}</Badge></TD>
                        <TD right><Amount value={r.outstanding} zeroDash /></TD>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="border-t-2 border-slate-200 bg-slate-50/60 font-semibold">
                    <tr>
                      <TD className="text-xs uppercase tracking-wide text-slate-500" colSpan={13}>Total</TD>
                      <TD right><Amount value={d.totals.net} bold /></TD>
                      <TD right><Amount value={d.totals.job_expenses} bold /></TD>
                      <TD right />
                      <TD right><Amount value={d.totals.tax} bold /></TD>
                      <TD right><Amount value={d.totals.gross} bold /></TD>
                      <TD />
                      <TD right><Amount value={d.totals.outstanding} bold /></TD>
                    </tr>
                  </tfoot>
                </table>
              </Scroller>
            )}
          </Card>

          <ByAccount rows={d.by_account} />
        </div>
      )}
    </Section>
  );
}

function ByAccount({ rows }) {
  if (!rows?.length) return null;
  return (
    <Card title="Subtotals by account" subtitle="Where each figure above was booked">
      <Scroller>
        <table className="w-full text-sm">
          <thead className="border-b border-slate-100 bg-slate-50/60">
            <tr><TH>Account</TH><TH>Name</TH><TH>GST</TH><TH right>Documents</TH><TH right>Net</TH><TH right>GST</TH><TH right>Gross</TH></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {rows.map((g) => (
              <tr key={g.account_code || "none"}>
                <TD mono className="font-semibold text-[#28364b]">{g.account_code || "—"}</TD>
                <TD>{g.account_name}</TD>
                <TD>{g.gst_code ? <Badge tone={GST_TONE[g.gst_code]}>{g.gst_code}</Badge> : <Badge tone="red">?</Badge>}</TD>
                <TD right className="tabular-nums text-slate-500">{g.count}</TD>
                <TD right><Amount value={g.net} bold /></TD>
                <TD right><Amount value={g.tax} zeroDash /></TD>
                <TD right><Amount value={g.gross} /></TD>
              </tr>
            ))}
          </tbody>
        </table>
      </Scroller>
    </Card>
  );
}

function Stat({ label, value, tone = "slate" }) {
  const tones = { slate: "text-[#28364b]", green: "text-green-600", amber: "text-amber-600" };
  return (
    <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
      <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
      <p className={`mt-1 text-lg font-bold tabular-nums ${tones[tone]}`}>{value}</p>
    </div>
  );
}

/* =========================== 4. GST summary =========================== */

function GstSummary({ range }) {
  const q = useSection("gst", accountingAPI.gstSummary, range);

  return (
    <Section query={q}>
      {(d) => (
        <div className="space-y-4">
          {d.warnings.map((w, i) => (
            <Note key={i} tone="amber">{w}</Note>
          ))}
          <CurrencyWarning data={d} />

          <div className="grid gap-3 sm:grid-cols-3">
            <Stat label="Output tax (sales)" value={money(d.output.tax)} />
            <Stat label="Input tax (purchases)" value={money(d.input.tax)} />
            <Stat
              label={d.payable ? "Net GST payable" : "Net GST refundable"}
              value={money(Math.abs(d.net_gst))}
              tone={d.payable ? "amber" : "green"}
            />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <GstSide title="GST from sales" subtitle="Output tax" side={d.output} />
            <GstSide title="GST from purchases" subtitle="Input tax" side={d.input} />
          </div>

          <Card
            title="IRAS GST F5 return"
            subtitle={`${d.period.from || "start"} to ${d.period.to || "today"}`}
            action={
              <ExportButton
                onClick={() =>
                  downloadCsv(
                    rangedFilename("gst-f5", range),
                    ["Box", "Description", "Amount"],
                    d.boxes.map((b) => [b.box, b.label, b.amount])
                  )
                }
              />
            }
          >
            <Scroller>
              <table className="w-full text-sm">
                <tbody className="divide-y divide-slate-50">
                  {d.boxes.map((b) => (
                    <tr key={b.box} className={b.computed ? "bg-slate-50/60 font-semibold" : ""}>
                      <TD className="w-16 font-semibold text-slate-400">Box {b.box}</TD>
                      <TD className={b.computed ? "text-[#28364b]" : "text-slate-600"}>{b.label}</TD>
                      <TD right className="w-40"><Amount value={b.amount} bold={b.computed} /></TD>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Scroller>
            <div className="border-t border-slate-100 px-5 py-3">
              <Note>
                Each box is built from the account code on the document, so a zero-rated sale
                always reaches Box 2. Anything on an unrecognised account is excluded and reported
                above rather than added to a box.
              </Note>
            </div>
          </Card>
        </div>
      )}
    </Section>
  );
}

function GstSide({ title, subtitle, side }) {
  const [open, setOpen] = useState(null);

  return (
    <Card title={title} subtitle={`${subtitle} · ${side.count} document(s)`}>
      {!side.groups.length ? (
        <Empty />
      ) : (
        <Scroller>
          <table className="w-full text-sm">
            <thead className="border-b border-slate-100 bg-slate-50/60">
              <tr><TH>GST code</TH><TH>Box</TH><TH right>Docs</TH><TH right>Net value</TH><TH right>GST</TH><TH /></tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {side.groups.map((g) => (
                <Fragment key={g.gst_code}>
                  <tr
                    className="cursor-pointer hover:bg-slate-50/60"
                    onClick={() => setOpen(open === g.gst_code ? null : g.gst_code)}
                  >
                    <TD>
                      <Badge tone={GST_TONE[g.gst_code]}>{g.gst_code}</Badge>
                      <span className="ml-2 text-slate-600">{g.gst_label}</span>
                    </TD>
                    <TD className="text-slate-500">{g.f5_box ? `Box ${g.f5_box}` : "—"}</TD>
                    <TD right className="tabular-nums text-slate-500">{g.count}</TD>
                    <TD right><Amount value={g.net} bold /></TD>
                    <TD right><Amount value={g.tax} zeroDash /></TD>
                    <TD right>
                      <ChevronRight className={`h-3.5 w-3.5 text-slate-400 transition-transform ${open === g.gst_code ? "rotate-90" : ""}`} />
                    </TD>
                  </tr>
                  {open === g.gst_code &&
                    g.accounts.map((a) => (
                      <tr key={a.account_code} className="bg-slate-50/40 text-xs">
                        <TD className="pl-8 text-slate-500">
                          <span className="font-semibold text-[#28364b]">{a.account_code}</span> {a.account_name}
                        </TD>
                        <TD />
                        <TD right className="tabular-nums text-slate-400">{a.count}</TD>
                        <TD right><Amount value={a.net} /></TD>
                        <TD right><Amount value={a.tax} zeroDash /></TD>
                        <TD />
                      </tr>
                    ))}
                </Fragment>
              ))}
            </tbody>
            <tfoot className="border-t-2 border-slate-200 bg-slate-50/60 font-semibold">
              <tr>
                <TD className="text-xs uppercase tracking-wide text-slate-500" colSpan={3}>Total</TD>
                <TD right><Amount value={side.net} bold /></TD>
                <TD right><Amount value={side.tax} bold /></TD>
                <TD />
              </tr>
              {side.unclassified.count > 0 && (
                <tr className="text-amber-700">
                  <TD colSpan={2} className="text-xs">Unclassified — not in any box</TD>
                  <TD right className="tabular-nums text-xs">{side.unclassified.count}</TD>
                  <TD right><Amount value={side.unclassified.net} /></TD>
                  <TD right><Amount value={side.unclassified.tax} zeroDash /></TD>
                  <TD />
                </tr>
              )}
            </tfoot>
          </table>
        </Scroller>
      )}
    </Card>
  );
}

/* ====================== 5. Income statement ====================== */

function IncomeStatement({ range }) {
  const q = useSection("income", accountingAPI.incomeStatement, range);

  return (
    <Section query={q}>
      {(d) => (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Revenue" value={money(d.revenue.total)} />
            <Stat label="Cost of sales" value={money(d.cost_of_sales.total)} />
            <Stat label="Gross profit" value={`${money(d.gross_profit)} (${pct(d.gross_margin)})`} tone={d.gross_profit >= 0 ? "green" : "amber"} />
            <Stat label="Net profit" value={money(d.net_profit)} tone={d.net_profit >= 0 ? "green" : "amber"} />
          </div>

          <Card
            title="Income statement"
            subtitle={`${d.period.from || "start"} to ${d.period.to || "today"} · ${d.base_currency}`}
            action={
              <ExportButton
                onClick={() =>
                  downloadCsv(
                    rangedFilename("income-statement", range),
                    ["Section", "Account", "Name", "Documents", "Amount"],
                    [
                      ...d.revenue.by_account.map((g) => ["Revenue", g.account_code, g.account_name, g.count, g.net]),
                      ["Revenue", "", "Total revenue", "", d.revenue.total],
                      ...d.cost_of_sales.by_account.map((g) => ["Cost of sales", g.account_code, g.account_name, g.count, g.net]),
                      ["Cost of sales", "", "Job expenses", "", d.cost_of_sales.job_expenses],
                      ["Cost of sales", "", "Total cost of sales", "", d.cost_of_sales.total],
                      ["", "", "Gross profit", "", d.gross_profit],
                      ...d.operating_expenses.by_account.map((g) => ["Operating expenses", g.account_code, g.account_name, g.count, g.net]),
                      ["Operating expenses", "", "Total operating expenses", "", d.operating_expenses.total],
                      ["", "", "Net profit", "", d.net_profit],
                    ]
                  )
                }
              />
            }
          >
            <Scroller>
              <table className="w-full text-sm">
                <tbody>
                  <StatementGroup label="Revenue" rows={d.revenue.by_account} total={d.revenue.total} />
                  <StatementGroup
                    label="Cost of sales"
                    rows={d.cost_of_sales.by_account}
                    extra={d.cost_of_sales.job_expenses ? [{ name: "Job expenses (carried on purchase orders)", net: d.cost_of_sales.job_expenses }] : []}
                    total={d.cost_of_sales.total}
                  />
                  <tr className="border-y-2 border-slate-200 bg-slate-50">
                    <TD colSpan={3} className="font-bold uppercase tracking-wide text-[#28364b]">Gross profit</TD>
                    <TD right className="w-40"><Amount value={d.gross_profit} bold /></TD>
                  </tr>
                  <StatementGroup label="Operating expenses" rows={d.operating_expenses.by_account} total={d.operating_expenses.total} />
                  <tr className="border-t-2 border-[#28364b] bg-[#28364b]/5">
                    <TD colSpan={3} className="py-3 font-bold uppercase tracking-wide text-[#28364b]">Net profit</TD>
                    <TD right className="py-3"><Amount value={d.net_profit} bold /></TD>
                  </tr>
                </tbody>
              </table>
            </Scroller>
          </Card>
        </div>
      )}
    </Section>
  );
}

function StatementGroup({ label, rows, total, extra = [] }) {
  return (
    <>
      <tr className="bg-slate-50/60">
        <TD colSpan={4} className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</TD>
      </tr>
      {!rows.length && !extra.length && (
        <tr><TD colSpan={4} className="pl-8 text-sm text-slate-400">None in this period.</TD></tr>
      )}
      {rows.map((g) => (
        <tr key={g.account_code || g.account_name} className="border-b border-slate-50">
          <TD mono className="w-20 pl-8 font-semibold text-[#28364b]">{g.account_code || "—"}</TD>
          <TD>{g.account_name}</TD>
          <TD className="w-24 text-right text-xs text-slate-400">{g.count} doc{g.count === 1 ? "" : "s"}</TD>
          <TD right className="w-40"><Amount value={g.net} /></TD>
        </tr>
      ))}
      {extra.map((g) => (
        <tr key={g.name} className="border-b border-slate-50">
          <TD className="w-20 pl-8" />
          <TD className="text-slate-500">{g.name}</TD>
          <TD />
          <TD right><Amount value={g.net} /></TD>
        </tr>
      ))}
      <tr className="border-b border-slate-100">
        <TD />
        <TD colSpan={2} className="font-semibold text-slate-600">Total {label.toLowerCase()}</TD>
        <TD right><Amount value={total} bold /></TD>
      </tr>
    </>
  );
}

/* ====================== 6. Trial balance ====================== */

function TrialBalance({ range }) {
  const q = useSection("trial", accountingAPI.trialBalance, range);

  return (
    <Section query={q}>
      {(d) => (
        <div className="space-y-4">
          {!d.totals.balanced && (
            <Note tone="amber">
              The trial balance is out by {money(d.totals.difference)}. Every figure is derived from
              documents, so a difference means something is recorded that the chart has no account
              for.
            </Note>
          )}
          <Note>{d.note}</Note>

          <Card
            title="Trial balance"
            subtitle={`Balance-sheet accounts as at ${d.as_of} · income and expenses for the period`}
            action={
              <ExportButton
                onClick={() =>
                  downloadCsv(
                    rangedFilename("trial-balance", range),
                    ["Code", "Account", "Type", "GST", "Statement", "Debit", "Credit"],
                    d.rows.map((r) => [r.code, r.name, r.type_label, r.gst_code, r.statement, r.debit, r.credit])
                  )
                }
              />
            }
          >
            <Scroller>
              <table className="w-full text-sm">
                <thead className="border-b border-slate-100 bg-slate-50/60">
                  <tr>
                    <TH>Code</TH><TH>Account</TH><TH>Type</TH><TH>GST</TH><TH>Statement</TH>
                    <TH right>Debit</TH><TH right>Credit</TH>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {d.rows.map((r, i) => (
                    <tr key={`${r.code}-${i}`} className={r.off_chart ? "bg-amber-50/50" : "hover:bg-slate-50/60"}>
                      <TD mono className="font-semibold text-[#28364b]">{r.code}</TD>
                      <TD className="font-medium">
                        {r.name}
                        {r.off_chart && <span className="ml-2"><Badge tone="amber">derived</Badge></span>}
                      </TD>
                      <TD className="text-slate-500">{r.type_label}</TD>
                      <TD>{r.gst_code ? <Badge tone={GST_TONE[r.gst_code]}>{r.gst_code}</Badge> : "—"}</TD>
                      <TD className="text-slate-500">{r.statement === "balance_sheet" ? "Balance sheet" : "Income statement"}</TD>
                      <TD right><Amount value={r.debit} zeroDash /></TD>
                      <TD right><Amount value={r.credit} zeroDash /></TD>
                    </tr>
                  ))}
                </tbody>
                <tfoot className="border-t-2 border-slate-200 bg-slate-50/60 font-semibold">
                  <tr>
                    <TD colSpan={5} className="text-xs uppercase tracking-wide text-slate-500">Totals</TD>
                    <TD right><Amount value={d.totals.debit} bold /></TD>
                    <TD right><Amount value={d.totals.credit} bold /></TD>
                  </tr>
                  <tr>
                    <TD colSpan={5} className="text-xs uppercase tracking-wide text-slate-500">Difference</TD>
                    <TD right colSpan={2}>
                      {d.totals.balanced ? (
                        <span className="inline-flex items-center gap-1 text-xs font-semibold text-green-600">
                          <Check className="h-3.5 w-3.5" /> Balanced
                        </span>
                      ) : (
                        <Amount value={d.totals.difference} bold />
                      )}
                    </TD>
                  </tr>
                </tfoot>
              </table>
            </Scroller>
          </Card>
        </div>
      )}
    </Section>
  );
}

/* ====================== 7. Balance sheet ====================== */

function BalanceSheet({ range }) {
  const q = useSection("balance", accountingAPI.balanceSheet, range);

  return (
    <Section query={q}>
      {(d) => (
        <div className="space-y-4">
          <Note tone={d.check.balanced ? "slate" : "amber"}>{d.note}</Note>

          <Card
            title="Balance sheet"
            subtitle={`As at ${d.as_of} · ${d.base_currency}`}
            action={
              <ExportButton
                onClick={() =>
                  downloadCsv(
                    `balance-sheet-${d.as_of}.csv`,
                    ["Section", "Code", "Account", "Amount"],
                    [
                      ...d.assets.cash.map((c) => ["Assets", c.code, c.name, c.amount]),
                      ["Assets", d.assets.receivables.code, d.assets.receivables.name, d.assets.receivables.amount],
                      ["Assets", "", "Total assets", d.assets.total],
                      ["Liabilities", d.liabilities.payables.code, d.liabilities.payables.name, d.liabilities.payables.amount],
                      ["Liabilities", "", d.liabilities.gst_payable.name, d.liabilities.gst_payable.amount],
                      ["Liabilities", "", d.liabilities.accrued_expenses?.name, d.liabilities.accrued_expenses?.amount],
                      ["Liabilities", "", "Total liabilities", d.liabilities.total],
                      ["Equity", d.equity.owner.code, d.equity.owner.name, d.equity.owner.amount],
                      ["Equity", "", "Retained earnings", d.equity.retained_earnings],
                      ["Equity", "", "Total equity", d.equity.total],
                    ]
                  )
                }
              />
            }
          >
            <Scroller>
              <table className="w-full text-sm">
                <tbody>
                  <tr className="bg-slate-50/60">
                    <TD colSpan={3} className="text-xs font-semibold uppercase tracking-wide text-slate-500">Assets</TD>
                  </tr>
                  {d.assets.cash.map((c) => (
                    <BsRow key={c.code} code={c.code} name={c.name} amount={c.amount} />
                  ))}
                  <BsRow code={d.assets.receivables.code} name={d.assets.receivables.name} amount={d.assets.receivables.amount} />
                  <BsTotal label="Total assets" amount={d.assets.total} />

                  <tr className="bg-slate-50/60">
                    <TD colSpan={3} className="text-xs font-semibold uppercase tracking-wide text-slate-500">Liabilities</TD>
                  </tr>
                  <BsRow code={d.liabilities.payables.code} name={d.liabilities.payables.name} amount={d.liabilities.payables.amount} />
                  <BsRow code="—" name={d.liabilities.gst_payable.name} amount={d.liabilities.gst_payable.amount} derived />
                  {d.liabilities.accrued_expenses && (
                    <BsRow code="—" name={d.liabilities.accrued_expenses.name} amount={d.liabilities.accrued_expenses.amount} derived />
                  )}
                  <BsTotal label="Total liabilities" amount={d.liabilities.total} />

                  <tr className="bg-slate-50/60">
                    <TD colSpan={3} className="text-xs font-semibold uppercase tracking-wide text-slate-500">Equity</TD>
                  </tr>
                  <BsRow code={d.equity.owner.code} name={d.equity.owner.name} amount={d.equity.owner.amount} />
                  <BsRow code="—" name="Retained earnings" amount={d.equity.retained_earnings} />
                  <BsTotal label="Total equity" amount={d.equity.total} />

                  <tr className="border-t-2 border-[#28364b] bg-[#28364b]/5">
                    <TD colSpan={2} className="py-3 font-bold uppercase tracking-wide text-[#28364b]">
                      Liabilities + equity
                    </TD>
                    <TD right className="w-44 py-3"><Amount value={d.check.liabilities_and_equity} bold /></TD>
                  </tr>
                  <tr>
                    <TD colSpan={2} className="font-semibold text-slate-600">Assets − liabilities − equity</TD>
                    <TD right>
                      {d.check.balanced ? (
                        <span className="inline-flex items-center gap-1 text-xs font-semibold text-green-600">
                          <Check className="h-3.5 w-3.5" /> Balanced
                        </span>
                      ) : (
                        <Amount value={d.check.difference} bold />
                      )}
                    </TD>
                  </tr>
                </tbody>
              </table>
            </Scroller>
          </Card>
        </div>
      )}
    </Section>
  );
}

const BsRow = ({ code, name, amount, derived }) => (
  <tr className={`border-b border-slate-50 ${derived ? "bg-amber-50/40" : ""}`}>
    <TD mono className="w-20 pl-8 font-semibold text-[#28364b]">{code}</TD>
    <TD>
      {name}
      {derived && <span className="ml-2"><Badge tone="amber">derived</Badge></span>}
    </TD>
    <TD right className="w-44"><Amount value={amount} /></TD>
  </tr>
);

const BsTotal = ({ label, amount }) => (
  <tr className="border-b border-slate-100">
    <TD />
    <TD className="font-semibold text-slate-600">{label}</TD>
    <TD right><Amount value={amount} bold /></TD>
  </tr>
);

/* ====================== 8 & 9. Ageing ====================== */

const BUCKETS = [
  { key: "current", label: "Current" },
  { key: "d1_30", label: "1–30 days" },
  { key: "d31_60", label: "31–60 days" },
  { key: "d61_90", label: "61–90 days" },
  { key: "d90_plus", label: "90+ days" },
];

function Ageing({ range, type }) {
  const fn = type === "customer" ? accountingAPI.arAgeing : accountingAPI.apAgeing;
  const q = useSection(`ageing-${type}`, fn, range);
  const [open, setOpen] = useState(null);
  const isAr = type === "customer";

  return (
    <Section query={q}>
      {(d) => (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <Stat label={isAr ? "Total receivable" : "Total payable"} value={money(d.total)} />
            {BUCKETS.map((b) => (
              <Stat
                key={b.key}
                label={b.label}
                value={money(d.buckets[b.key])}
                tone={b.key === "d90_plus" && d.buckets[b.key] > 0 ? "amber" : "slate"}
              />
            ))}
          </div>

          <Card
            title={isAr ? "AR ageing" : "AP ageing"}
            subtitle={`${d.party_count} ${isAr ? "customer" : "vendor"}(s) as at ${d.as_of} · account ${d.account}`}
            action={
              <ExportButton
                disabled={!d.parties.length}
                onClick={() =>
                  downloadCsv(
                    `${isAr ? "ar" : "ap"}-ageing-${d.as_of}.csv`,
                    [isAr ? "Customer no" : "Vendor no", "Name", ...BUCKETS.map((b) => b.label), "Total", "Oldest (days)"],
                    d.parties.map((p) => [p.party_no, p.name, ...BUCKETS.map((b) => p.buckets[b.key]), p.total, p.oldest_days])
                  )
                }
              />
            }
          >
            {!d.parties.length ? (
              <Empty>{isAr ? "Nobody owes anything as at this date." : "Nothing owed to vendors as at this date."}</Empty>
            ) : (
              <Scroller>
                <table className="w-full text-sm">
                  <thead className="border-b border-slate-100 bg-slate-50/60">
                    <tr>
                      <TH>{isAr ? "Cust #" : "Vend #"}</TH><TH>{isAr ? "Customer" : "Vendor"}</TH>
                      {BUCKETS.map((b) => <TH key={b.key} right>{b.label}</TH>)}
                      <TH right>Total</TH><TH right>Oldest</TH><TH />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50">
                    {d.parties.map((p) => (
                      <Fragment key={p.id}>
                        <tr className="cursor-pointer hover:bg-slate-50/60" onClick={() => setOpen(open === p.id ? null : p.id)}>
                          <TD mono className="text-slate-500">{p.party_no || "—"}</TD>
                          <TD className="max-w-[18rem] truncate font-medium" title={p.name}>{p.name}</TD>
                          {BUCKETS.map((b) => (
                            <TD key={b.key} right>
                              <Amount value={p.buckets[b.key]} zeroDash />
                            </TD>
                          ))}
                          <TD right><Amount value={p.total} bold /></TD>
                          <TD right>
                            {p.oldest_days > 0
                              ? <Badge tone={p.oldest_days > 90 ? "red" : p.oldest_days > 30 ? "amber" : "slate"}>{p.oldest_days}d</Badge>
                              : <span className="text-slate-300">—</span>}
                          </TD>
                          <TD right>
                            <ChevronRight className={`h-3.5 w-3.5 text-slate-400 transition-transform ${open === p.id ? "rotate-90" : ""}`} />
                          </TD>
                        </tr>
                        {open === p.id &&
                          p.entries.map((e) => (
                            <tr key={`${e.kind}-${e.id}`} className="bg-slate-50/40 text-xs">
                              <TD />
                              <TD className="pl-6 text-slate-500">
                                <span className="font-semibold text-[#28364b]">{e.number}</span>
                                {e.reference && <span className="ml-2">{e.reference}</span>}
                                {e.vessel && <span className="ml-2 text-slate-400">{e.vessel}</span>}
                              </TD>
                              <TD colSpan={4} className="text-slate-400">
                                {e.date}{e.due_date ? ` · due ${e.due_date}` : ""} · {e.currency}
                              </TD>
                              <TD right className="text-slate-500">{BUCKETS.find((b) => b.key === e.bucket)?.label}</TD>
                              <TD right><Amount value={e.outstanding} /></TD>
                              <TD right className="text-slate-400">{e.overdue_days > 0 ? `${e.overdue_days}d` : "—"}</TD>
                              <TD />
                            </tr>
                          ))}
                      </Fragment>
                    ))}
                  </tbody>
                  <tfoot className="border-t-2 border-slate-200 bg-slate-50/60 font-semibold">
                    <tr>
                      <TD colSpan={2} className="text-xs uppercase tracking-wide text-slate-500">Total</TD>
                      {BUCKETS.map((b) => (
                        <TD key={b.key} right><Amount value={d.buckets[b.key]} bold /></TD>
                      ))}
                      <TD right><Amount value={d.total} bold /></TD>
                      <TD colSpan={2} />
                    </tr>
                  </tfoot>
                </table>
              </Scroller>
            )}
          </Card>
        </div>
      )}
    </Section>
  );
}

/* ====================== 10. Party numbers ====================== */

function PartyNumbers() {
  const [type, setType] = useState("customer");
  const [term, setTerm] = useState("");
  const [page, setPage] = useState(1);
  const debounced = useDebounced(term, 300);

  const q = useQuery({
    queryKey: ["accounting", "parties", type, debounced, page],
    queryFn: async () =>
      (await accountingAPI.parties({ type, q: debounced || undefined, page, per_page: 50 })).data.data,
    keepPreviousData: true,
  });

  const isCustomer = type === "customer";

  return (
    <Section query={q}>
      {(d) => (
        <div className="space-y-4">
          <Note>
            Numbers are issued by the system in the order parties were created — customers from
            10001, vendors from 20001 — and never reused. They are a stable key for the accounting
            reports, so the name can be corrected without breaking anything that refers to them.
          </Note>

          <Card
            title={isCustomer ? "Customer numbers" : "Vendor numbers"}
            subtitle={`${d.meta.total} record(s) · ${d.series.issued} numbered from ${d.series.starts_at}${
              d.series.unnumbered ? ` · ${d.series.unnumbered} without a number` : ""
            }`}
            action={
              <div className="flex flex-wrap items-center gap-2">
                <div className="flex rounded-lg border border-slate-200 p-0.5">
                  {["customer", "vendor"].map((t) => (
                    <button
                      key={t}
                      onClick={() => { setType(t); setPage(1); }}
                      className={`rounded-md px-3 py-1 text-xs font-medium capitalize transition-colors ${
                        type === t ? "bg-[#28364b] text-white" : "text-slate-500 hover:bg-slate-100"
                      }`}
                    >
                      {t}s
                    </button>
                  ))}
                </div>
                <SearchBox value={term} onChange={(v) => { setTerm(v); setPage(1); }} placeholder="Number, name, email…" />
                <ExportButton
                  disabled={!d.rows.length}
                  onClick={() =>
                    downloadCsv(
                      `${type}-numbers.csv`,
                      [isCustomer ? "Customer no" : "Vendor no", "Name", "Contact", "Email", "Phone", "Currency", "Active", "Created"],
                      d.rows.map((r) => [r.party_no, r.name, r.contact_name, r.email, r.phone, r.currency, r.is_active ? "yes" : "no", r.created_at])
                    )
                  }
                />
              </div>
            }
          >
            {!d.rows.length ? (
              <Empty>No {type}s match that search.</Empty>
            ) : (
              <Scroller>
                <table className="w-full text-sm">
                  <thead className="border-b border-slate-100 bg-slate-50/60">
                    <tr>
                      <TH>{isCustomer ? "Cust #" : "Vend #"}</TH><TH>Name</TH>
                      {!isCustomer && <TH>Contact</TH>}
                      <TH>Email</TH><TH>Phone</TH><TH>Currency</TH><TH>Status</TH><TH>Created</TH>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50">
                    {d.rows.map((r) => (
                      <tr key={r.id} className="hover:bg-slate-50/60">
                        <TD mono className="font-semibold text-[#28364b]">{r.party_no || <Badge tone="red">none</Badge>}</TD>
                        <TD className="max-w-[22rem] truncate font-medium" title={r.name}>{r.name}</TD>
                        {!isCustomer && <TD className="text-slate-500">{r.contact_name || "—"}</TD>}
                        <TD className="max-w-[16rem] truncate text-slate-500" title={r.email}>{r.email || "—"}</TD>
                        <TD className="text-slate-500">{r.phone || "—"}</TD>
                        <TD className="text-slate-500">{r.currency || "—"}</TD>
                        <TD><Badge tone={r.is_active ? "green" : "slate"}>{r.is_active ? "active" : "inactive"}</Badge></TD>
                        <TD className="text-slate-500">{r.created_at || "—"}</TD>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Scroller>
            )}

            {d.meta.last_page > 1 && (
              <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                <span>Page {d.meta.current_page} of {d.meta.last_page}</span>
                <div className="flex gap-2">
                  <button
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    disabled={d.meta.current_page <= 1}
                    className="rounded-lg border border-slate-200 px-3 py-1 font-medium transition-colors hover:bg-slate-50 disabled:opacity-40"
                  >
                    Previous
                  </button>
                  <button
                    onClick={() => setPage((p) => p + 1)}
                    disabled={d.meta.current_page >= d.meta.last_page}
                    className="rounded-lg border border-slate-200 px-3 py-1 font-medium transition-colors hover:bg-slate-50 disabled:opacity-40"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </Card>
        </div>
      )}
    </Section>
  );
}

/* ============================== shell ============================== */

const TABS = [
  { key: "sales", label: "Sales invoices", icon: Receipt, Component: SalesRegister, ranged: true },
  { key: "purchases", label: "Purchase invoices", icon: ShoppingCart, Component: PurchaseRegister, ranged: true },
  { key: "gst", label: "GST summary", icon: Percent, Component: GstSummary, ranged: true },
  { key: "income", label: "Income statement", icon: PieChart, Component: IncomeStatement, ranged: true },
  { key: "trial", label: "Trial balance", icon: Scale, Component: TrialBalance, ranged: true },
  { key: "balance", label: "Balance sheet", icon: Landmark, Component: BalanceSheet, ranged: true },
  { key: "ar", label: "AR ageing", icon: Clock, Component: (p) => <Ageing {...p} type="customer" />, ranged: true },
  { key: "ap", label: "AP ageing", icon: Clock, Component: (p) => <Ageing {...p} type="vendor" />, ranged: true },
  { key: "parties", label: "Customer / vendor numbers", icon: Users, Component: PartyNumbers, ranged: false },
  { key: "chart", label: "Chart of accounts", icon: BookOpen, Component: ChartOfAccounts, ranged: false },
];

export default function Accounting() {
  const [tab, setTab] = useState("sales");
  const [preset, setPreset] = useState("year");
  const [custom, setCustom] = useState({ from: "", to: "" });

  // A custom date overrides the preset; clearing both falls back to the preset.
  const range = useMemo(() => {
    if (custom.from || custom.to) return custom;
    return presetRange(preset);
  }, [preset, custom]);

  const active = TABS.find((t) => t.key === tab) || TABS[0];
  const Active = active.Component;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Accounting</h1>
          <p className="text-sm text-slate-500">
            Sales, purchases, GST and the statements — generated from the documents, not retyped.
          </p>
        </div>

        {active.ranged && (
          <div className="flex flex-wrap items-center gap-2">
            <div className="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1">
              {PRESETS.map((p) => (
                <button
                  key={p.key}
                  onClick={() => { setPreset(p.key); setCustom({ from: "", to: "" }); }}
                  className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                    preset === p.key && !custom.from && !custom.to
                      ? "bg-[#28364b] text-white"
                      : "text-slate-500 hover:bg-slate-100"
                  }`}
                >
                  {p.label}
                </button>
              ))}
            </div>
            <div className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 py-1">
              <DatePicker
                value={range.from}
                onChange={(v) => setCustom((c) => ({ ...c, from: v }))}
                placeholder="From"
              />
              <span className="text-xs text-slate-400">to</span>
              <DatePicker
                value={range.to}
                onChange={(v) => setCustom((c) => ({ ...c, to: v }))}
                placeholder="To"
              />
            </div>
          </div>
        )}
      </div>

      <div className="flex gap-1 overflow-x-auto border-b border-slate-200">
        {TABS.map((t) => {
          const Icon = t.icon;
          const isActive = tab === t.key;
          return (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`relative flex shrink-0 items-center gap-1.5 px-4 py-2.5 text-sm font-medium transition-colors ${
                isActive ? "text-[#28364b]" : "text-slate-400 hover:text-slate-600"
              }`}
            >
              <Icon className="h-4 w-4" /> {t.label}
              {isActive && (
                <motion.div layoutId="accounting-tab" className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-[#28364b]" />
              )}
            </button>
          );
        })}
      </div>

      <AnimatePresence mode="wait">
        <motion.div
          key={tab}
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.2 }}
        >
          <Active range={range} />
        </motion.div>
      </AnimatePresence>
    </div>
  );
}
