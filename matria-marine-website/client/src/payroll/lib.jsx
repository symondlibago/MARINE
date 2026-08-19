/** Money, always two decimals. */
export const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Zero shown quietly, so the eye goes to the figures that matter. */
export const dim = (n) => (Number(n || 0) === 0 ? "text-slate-300" : "");

export const dateOf = (d) =>
  d ? new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "—";

export const monthOf = (d) =>
  d ? new Date(d).toLocaleDateString("en-GB", { month: "long", year: "numeric" }) : "—";

/** The month input wants YYYY-MM; the API wants a date. */
export const toMonthInput = (d) => (d ? String(d).slice(0, 7) : "");
export const fromMonthInput = (m) => (m ? `${m}-01` : "");

export const inputCls =
  "rounded-lg border border-slate-200 px-3 py-2 text-sm text-[#28364b] outline-none transition-colors focus:border-[#28364b] focus:ring-1 focus:ring-[#28364b]";

export const numCls = `${inputCls} text-right [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none`;

/** The compact cell input used in the payroll grid. */
export const cellCls =
  "w-24 rounded border border-transparent bg-white/70 px-2 py-1 text-right text-sm text-[#28364b] outline-none transition-colors hover:border-slate-200 focus:border-[#28364b] focus:bg-white focus:ring-1 focus:ring-[#28364b] disabled:bg-transparent disabled:text-slate-400 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none";

export const btn = {
  primary:
    "inline-flex items-center justify-center gap-2 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-50",
  ghost:
    "inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-[#28364b] transition-colors hover:bg-slate-50 disabled:opacity-50",
  danger:
    "inline-flex items-center justify-center gap-2 rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-600 transition-colors hover:bg-red-50 disabled:opacity-50",
};

/** Summary card used across the payroll screens. */
export function StatCard({ label, value, hint, tone = "default", icon: Icon, currency }) {
  const tones = {
    default: "border-slate-200 bg-white",
    good: "border-emerald-200 bg-emerald-50/50",
    warn: "border-amber-200 bg-amber-50/50",
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
      <p className={`mt-1.5 text-2xl font-bold ${isNavy ? "text-white" : "text-[#28364b]"}`}>
        {currency ? (
          <span className={`mr-1 align-baseline text-sm font-semibold ${isNavy ? "text-white/70" : "text-slate-400"}`}>
            {currency}
          </span>
        ) : null}
        {value}
      </p>
      {hint && <p className={`mt-0.5 text-xs ${isNavy ? "text-white/60" : "text-slate-400"}`}>{hint}</p>}
    </div>
  );
}

export function StatusPill({ status }) {
  const finalised = status === "finalised";
  return (
    <span
      className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ${
        finalised ? "bg-emerald-50 text-emerald-700 ring-emerald-200" : "bg-amber-50 text-amber-700 ring-amber-200"
      }`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${finalised ? "bg-emerald-500" : "bg-amber-500"}`} />
      {finalised ? "FINALISED" : "DRAFT"}
    </span>
  );
}

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