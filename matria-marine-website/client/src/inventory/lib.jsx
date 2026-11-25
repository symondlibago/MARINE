/**
 * Shared helpers and small display pieces for the inventory screens.
 *
 * Nothing here calculates anything: the backend owns every number. These only
 * format what the API already returned.
 */
import { AlertTriangle, CheckCircle2, CircleAlert } from "lucide-react";

export const STATUS = {
  GOOD: {
    label: "GOOD",
    chip: "bg-emerald-50 text-emerald-700 ring-emerald-200",
    dot: "bg-emerald-500",
    row: "",
    icon: CheckCircle2,
  },
  LOW: {
    label: "LOW",
    chip: "bg-amber-50 text-amber-700 ring-amber-200",
    dot: "bg-amber-500",
    row: "bg-amber-50/40",
    icon: CircleAlert,
  },
  "ORDER NOW": {
    label: "ORDER NOW",
    chip: "bg-red-50 text-red-700 ring-red-200",
    dot: "bg-red-500",
    row: "bg-red-50/40",
    icon: AlertTriangle,
  },
};

export const statusOf = (s) => STATUS[s] ?? STATUS.GOOD;

/** Money, always two decimals. */
export const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Quantity without trailing zeros — 12.500 reads as 12.5, 12.000 as 12. */
export const qty = (n) => {
  const v = Number(n || 0);
  return Number.isInteger(v) ? String(v) : String(Number(v.toFixed(3)));
};

export const dateOf = (d) => (d ? new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "—");

/** The colour-coded stock status the crew reads at a glance. */
export function StatusPill({ status, className = "" }) {
  const s = statusOf(status);
  return (
    <span
      className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ${s.chip} ${className}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${s.dot}`} />
      {s.label}
    </span>
  );
}

/** Summary card used across the dashboard and the top of the item list. */
export function StatCard({ label, value, hint, tone = "default", icon: Icon }) {
  const tones = {
    default: "border-slate-200 bg-white",
    good: "border-emerald-200 bg-emerald-50/50",
    low: "border-amber-200 bg-amber-50/50",
    danger: "border-red-200 bg-red-50/50",
    navy: "border-[#28364b] bg-[#28364b] text-white",
  };
  const isNavy = tone === "navy";

  return (
    <div className={`rounded-xl border p-4 ${tones[tone] ?? tones.default}`}>
      <div className="flex items-start justify-between gap-2">
        <p className={`text-[11px] font-semibold uppercase tracking-wide ${isNavy ? "text-white/60" : "text-slate-500"}`}>
          {label}
        </p>
        {Icon && <Icon className={`h-4 w-4 shrink-0 ${isNavy ? "text-[#cebd88]" : "text-slate-300"}`} />}
      </div>
      <p className={`mt-1.5 text-2xl font-bold ${isNavy ? "text-white" : "text-[#28364b]"}`}>{value}</p>
      {hint && <p className={`mt-0.5 text-xs ${isNavy ? "text-white/60" : "text-slate-400"}`}>{hint}</p>}
    </div>
  );
}

/** Consistent input styling; `num` also hides the spinner arrows. */
export const inputCls =
  "rounded-lg border border-slate-200 px-3 py-2 text-sm text-[#28364b] outline-none transition-colors focus:border-[#28364b] focus:ring-1 focus:ring-[#28364b]";

export const numCls = `${inputCls} text-right [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none`;

export const btn = {
  primary:
    "inline-flex items-center justify-center gap-2 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-50",
  ghost:
    "inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-[#28364b] transition-colors hover:bg-slate-50 disabled:opacity-50",
  danger:
    "inline-flex items-center justify-center gap-2 rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-600 transition-colors hover:bg-red-50 disabled:opacity-50",
};

/** Download a blob response under a given filename. */
export function downloadBlob(data, filename) {
  const href = URL.createObjectURL(new Blob([data]));
  const a = document.createElement("a");
  a.href = href;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(href);
}
