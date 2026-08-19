import { useState, useRef, useEffect } from "react";
import { Calendar, ChevronLeft, ChevronRight } from "lucide-react";

/**
 * A self-contained date picker for the payroll screens.
 *
 * It deliberately depends on nothing outside this folder, so it can't be broken
 * by a shared-component path that doesn't resolve. It keeps the same contract
 * the screens already used — `value` in and `onChange(value)` out, both the
 * ISO "YYYY-MM-DD" the backend validates as a date — so the call sites did not
 * have to change.
 *
 * Three views:
 *   • days   — the month calendar; clicking a day commits the value.
 *   • months — click the month name in the header to jump straight to a month.
 *   • years  — click the year in the header to jump straight to a year/decade.
 * Picking a year drills into months, picking a month drills into days, so a
 * date years away is a few taps rather than a lot of arrow-clicking.
 *
 * Dates are parsed and formatted as LOCAL dates on purpose: going through
 * toISOString() would shift the day across a timezone and hand back yesterday.
 */

const WEEKDAYS = ["Mo", "Tu", "We", "Th", "Fr", "Sa", "Su"];
const MONTHS = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December",
];
const MONTHS_SHORT = [
  "Jan", "Feb", "Mar", "Apr", "May", "Jun",
  "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
];

const pad = (n) => String(n).padStart(2, "0");
const toISO = (y, mo, d) => `${y}-${pad(mo)}-${pad(d)}`;

/** "YYYY-MM-DD" -> {y, mo, d}, or null if it isn't a real calendar date. */
function parseISO(value) {
  if (!value) return null;
  const m = String(value).slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return null;
  const y = +m[1], mo = +m[2], d = +m[3];
  const dt = new Date(y, mo - 1, d);
  if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return null;
  return { y, mo, d };
}

const fmtDisplay = (parts) =>
  parts
    ? new Date(parts.y, parts.mo - 1, parts.d).toLocaleDateString("en-GB", {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : "";

/** JS getDay() is Sunday-first; the grid is Monday-first. */
const mondayIndex = (jsDay) => (jsDay + 6) % 7;

/** A small header arrow, shared by all three views. */
function NavButton({ onClick, title, children }) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={title}
      className="rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
    >
      {children}
    </button>
  );
}

export default function DatePicker({ value, onChange, placeholder = "Pick a date", disabled = false }) {
  const parts = parseISO(value); // the currently-selected value, if any
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState("days"); // "days" | "months" | "years"
  const [view, setView] = useState(() => {
    const base =
      parts ??
      (() => {
        const n = new Date();
        return { y: n.getFullYear(), mo: n.getMonth() + 1 };
      })();
    return { y: base.y, mo: base.mo };
  });
  const ref = useRef(null);

  // Follow the value if it changes from outside (e.g. loading an existing record).
  useEffect(() => {
    if (parts) setView({ y: parts.y, mo: parts.mo });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  // Every time it opens, start on the day view.
  useEffect(() => {
    if (open) setMode("days");
  }, [open]);

  // Close on outside click or Escape.
  useEffect(() => {
    if (!open) return;
    const onDoc = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDoc);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDoc);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const now = new Date();
  const todayY = now.getFullYear();
  const todayMo = now.getMonth() + 1;
  const today = toISO(todayY, todayMo, now.getDate());
  const selectedISO = value ? String(value).slice(0, 10) : "";

  const daysInMonth = new Date(view.y, view.mo, 0).getDate();
  const leading = mondayIndex(new Date(view.y, view.mo - 1, 1).getDay());
  const cells = [
    ...Array(leading).fill(null),
    ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
  ];
  const decadeStart = Math.floor(view.y / 10) * 10;

  const shiftMonth = (delta) =>
    setView((v) => {
      const nd = new Date(v.y, v.mo - 1 + delta, 1);
      return { y: nd.getFullYear(), mo: nd.getMonth() + 1 };
    });
  const shiftYear = (delta) => setView((v) => ({ ...v, y: v.y + delta }));

  const pick = (iso) => {
    onChange?.(iso);
    setOpen(false);
  };
  const chooseMonth = (mo) => {
    setView((v) => ({ ...v, mo }));
    setMode("days");
  };
  const chooseYear = (y) => {
    setView((v) => ({ ...v, y }));
    setMode("months");
  };

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen((o) => !o)}
        className={`flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors ${
          disabled
            ? "cursor-not-allowed border-slate-200 bg-slate-50 text-slate-400"
            : "border-slate-200 hover:border-slate-300 focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
        } ${open ? "border-[#28364b] ring-1 ring-[#28364b]" : ""}`}
      >
        <span className={parts ? "text-[#28364b]" : "text-slate-400"}>
          {parts ? fmtDisplay(parts) : placeholder}
        </span>
        <Calendar className="h-4 w-4 shrink-0 text-slate-400" />
      </button>

      {open && !disabled && (
        <div className="absolute left-0 z-50 mt-1 w-[17.5rem] rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
          {/* Header — differs per view, but always [prev] [label] [next] */}
          {mode === "days" && (
            <div className="mb-2 flex items-center justify-between">
              <NavButton onClick={() => shiftMonth(-1)} title="Previous month">
                <ChevronLeft className="h-4 w-4" />
              </NavButton>
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  onClick={() => setMode("months")}
                  className="rounded-lg px-2 py-1 text-sm font-semibold text-[#28364b] transition-colors hover:bg-slate-100"
                  title="Choose a month"
                >
                  {MONTHS[view.mo - 1]}
                </button>
                <button
                  type="button"
                  onClick={() => setMode("years")}
                  className="rounded-lg px-2 py-1 text-sm font-semibold text-[#28364b] transition-colors hover:bg-slate-100"
                  title="Choose a year"
                >
                  {view.y}
                </button>
              </div>
              <NavButton onClick={() => shiftMonth(1)} title="Next month">
                <ChevronRight className="h-4 w-4" />
              </NavButton>
            </div>
          )}

          {mode === "months" && (
            <div className="mb-2 flex items-center justify-between">
              <NavButton onClick={() => shiftYear(-1)} title="Previous year">
                <ChevronLeft className="h-4 w-4" />
              </NavButton>
              <button
                type="button"
                onClick={() => setMode("years")}
                className="rounded-lg px-3 py-1 text-sm font-semibold text-[#28364b] transition-colors hover:bg-slate-100"
                title="Choose a year"
              >
                {view.y}
              </button>
              <NavButton onClick={() => shiftYear(1)} title="Next year">
                <ChevronRight className="h-4 w-4" />
              </NavButton>
            </div>
          )}

          {mode === "years" && (
            <div className="mb-2 flex items-center justify-between">
              <NavButton onClick={() => shiftYear(-10)} title="Previous decade">
                <ChevronLeft className="h-4 w-4" />
              </NavButton>
              <div className="px-3 py-1 text-sm font-semibold text-[#28364b]">
                {decadeStart} – {decadeStart + 9}
              </div>
              <NavButton onClick={() => shiftYear(10)} title="Next decade">
                <ChevronRight className="h-4 w-4" />
              </NavButton>
            </div>
          )}

          {/* Day grid */}
          {mode === "days" && (
            <>
              <div className="grid grid-cols-7 gap-0.5 text-center text-[11px] font-medium text-slate-400">
                {WEEKDAYS.map((w) => (
                  <div key={w} className="py-1">
                    {w}
                  </div>
                ))}
              </div>
              <div className="grid grid-cols-7 gap-0.5">
                {cells.map((d, i) => {
                  if (d === null) return <div key={`blank-${i}`} />;
                  const iso = toISO(view.y, view.mo, d);
                  const selected = selectedISO === iso;
                  const isToday = iso === today;
                  return (
                    <button
                      type="button"
                      key={iso}
                      onClick={() => pick(iso)}
                      className={`h-8 rounded-lg text-sm transition-colors ${
                        selected
                          ? "bg-[#28364b] font-semibold text-white"
                          : isToday
                          ? "text-[#28364b] ring-1 ring-inset ring-[#cebd88] hover:bg-slate-100"
                          : "text-slate-600 hover:bg-slate-100"
                      }`}
                    >
                      {d}
                    </button>
                  );
                })}
              </div>
            </>
          )}

          {/* Month grid */}
          {mode === "months" && (
            <div className="grid grid-cols-3 gap-1">
              {MONTHS_SHORT.map((label, idx) => {
                const mo = idx + 1;
                const selected = parts && parts.y === view.y && parts.mo === mo;
                const isThisMonth = view.y === todayY && mo === todayMo;
                const isCurrentView = mo === view.mo;
                return (
                  <button
                    type="button"
                    key={label}
                    onClick={() => chooseMonth(mo)}
                    className={`h-10 rounded-lg text-sm transition-colors ${
                      selected
                        ? "bg-[#28364b] font-semibold text-white"
                        : isThisMonth
                        ? "text-[#28364b] ring-1 ring-inset ring-[#cebd88] hover:bg-slate-100"
                        : isCurrentView
                        ? "bg-slate-100 font-medium text-[#28364b]"
                        : "text-slate-600 hover:bg-slate-100"
                    }`}
                  >
                    {label}
                  </button>
                );
              })}
            </div>
          )}

          {/* Year grid — the decade, plus one year either side (dimmed) */}
          {mode === "years" && (
            <div className="grid grid-cols-3 gap-1">
              {Array.from({ length: 12 }, (_, i) => decadeStart - 1 + i).map((yr) => {
                const selected = parts && parts.y === yr;
                const isThisYear = yr === todayY;
                const isCurrentView = yr === view.y;
                const outside = yr < decadeStart || yr > decadeStart + 9;
                return (
                  <button
                    type="button"
                    key={yr}
                    onClick={() => chooseYear(yr)}
                    className={`h-10 rounded-lg text-sm transition-colors ${
                      selected
                        ? "bg-[#28364b] font-semibold text-white"
                        : isThisYear
                        ? "text-[#28364b] ring-1 ring-inset ring-[#cebd88] hover:bg-slate-100"
                        : isCurrentView
                        ? "bg-slate-100 font-medium text-[#28364b]"
                        : outside
                        ? "text-slate-300 hover:bg-slate-100"
                        : "text-slate-600 hover:bg-slate-100"
                    }`}
                  >
                    {yr}
                  </button>
                );
              })}
            </div>
          )}

          {/* Footer shortcuts, available from any view */}
          <div className="mt-2 flex items-center justify-between border-t border-slate-100 pt-2">
            <button
              type="button"
              onClick={() => pick(today)}
              className="text-xs font-medium text-[#28364b] hover:underline"
            >
              Today
            </button>
            {parts && (
              <button
                type="button"
                onClick={() => pick("")}
                className="text-xs font-medium text-slate-400 transition-colors hover:text-red-500 hover:underline"
              >
                Clear
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}