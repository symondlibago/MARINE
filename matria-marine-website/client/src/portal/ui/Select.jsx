import { useState, useRef, useEffect, useLayoutEffect } from "react";
import { createPortal } from "react-dom";
import { AnimatePresence, motion } from "framer-motion";
import { ChevronDown, Check, Search } from "lucide-react";

/**
 * Animated dropdown replacing native <select>.
 * options: array of strings, or { value, label }.
 *
 * An option may also carry `short`, shown on the closed trigger instead of
 * `label` — so a narrow grid cell can read "5000" while its menu still reads
 * "5000 — Cost of Sales".
 *
 * Two optional props, both off by default so every existing use is unchanged:
 *
 *   portal      render the menu into <body>, fixed-positioned against the
 *               trigger. Needed inside a line-item grid: the row sits in an
 *               `overflow-x-auto` container, and CSS forces overflow-y to
 *               `auto` when either axis is not `visible`, so an absolutely
 *               positioned menu is clipped to the row. Same approach, and the
 *               same pitfalls, as DatePicker — AnimatePresence goes INSIDE the
 *               portal, and the outside-click check has to know about the menu
 *               as well as the trigger, or clicking an option closes the menu
 *               before the click can land on it.
 *   menuWidth   pixels, when the menu must be wider than its trigger.
 */
export default function Select({
  value,
  onChange,
  options = [],
  placeholder = "Select",
  className = "",
  triggerClassName = "px-3 py-2",
  portal = false,
  menuWidth,
  searchable = false,
  searchPlaceholder = "Search...",
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [coords, setCoords] = useState(null);
  const ref = useRef(null);
  const btnRef = useRef(null);
  const menuRef = useRef(null);

  useEffect(() => {
    const handler = (e) => {
      // The portaled menu lives outside `ref`, so it needs checking by hand —
      // otherwise mousedown on an option closes the menu and the click never
      // reaches the option.
      if (menuRef.current?.contains(e.target)) return;
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  // Keep a portaled menu pinned to its trigger while the page moves under it.
  useLayoutEffect(() => {
    if (!portal || !open) return;

    const place = () => {
      const r = btnRef.current?.getBoundingClientRect();
      if (!r) return;

      const width = menuWidth ?? r.width;
      const spaceBelow = window.innerHeight - r.bottom;
      // Flip above when there is more room there — a menu opening off the
      // bottom of the window is a menu you cannot use.
      const flip = spaceBelow < 240 && r.top > spaceBelow;

      setCoords({
        left: Math.max(8, Math.min(r.left, window.innerWidth - width - 8)),
        width,
        ...(flip ? { bottom: window.innerHeight - r.top + 4 } : { top: r.bottom + 4 }),
      });
    };

    place();
    window.addEventListener("scroll", place, true);
    window.addEventListener("resize", place);
    return () => {
      window.removeEventListener("scroll", place, true);
      window.removeEventListener("resize", place);
    };
  }, [portal, open, menuWidth]);

  const opts = options.map((o) => (typeof o === "string" ? { value: o, label: o } : o));
  const selected = opts.find((o) => o.value === value);
  const visibleOpts = searchable && search.trim()
    ? opts.filter((o) => String(o.label).toLowerCase().includes(search.trim().toLowerCase()))
    : opts;

  const items = visibleOpts.map((o) => (
    <li key={o.value}>
      <button
        type="button"
        onClick={() => {
          onChange(o.value);
          setOpen(false);
        }}
        className={`flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm transition-colors hover:bg-slate-50 ${
          o.value === value ? "font-medium text-[#28364b]" : "text-slate-600"
        }`}
      >
        <span className="truncate">{o.label}</span>
        {o.value === value && <Check className="h-3.5 w-3.5 shrink-0" />}
      </button>
    </li>
  ));

  const menuContents = (
    <>
      {searchable && (
        <li className="sticky top-0 z-10 border-b border-slate-100 bg-white p-2">
          <div className="flex items-center gap-2 rounded-md border border-slate-200 px-2.5 py-1.5 focus-within:border-[#28364b] focus-within:ring-1 focus-within:ring-[#28364b]">
            <Search className="h-4 w-4 shrink-0 text-slate-400" />
            <input
              autoFocus
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              onKeyDown={(event) => event.stopPropagation()}
              placeholder={searchPlaceholder}
              className="min-w-0 flex-1 bg-transparent text-sm text-slate-700 outline-none placeholder:text-slate-400"
            />
          </div>
        </li>
      )}
      {items.length > 0 ? items : <li className="px-3 py-3 text-center text-sm text-slate-400">No matches found.</li>}
    </>
  );

  const MENU_CLASS = "max-h-56 overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg";
  const MOTION = {
    initial: { opacity: 0, y: -4, scale: 0.98 },
    animate: { opacity: 1, y: 0, scale: 1 },
    exit: { opacity: 0, y: -4, scale: 0.98 },
    transition: { duration: 0.14 },
  };

  return (
    <div ref={ref} className={`relative ${className}`}>
      <button
        ref={btnRef}
        type="button"
        onClick={() => {
          setSearch("");
          setOpen((o) => !o);
        }}
        className={`flex w-full items-center justify-between gap-1 rounded-lg border border-slate-200 bg-white text-sm transition-colors hover:border-slate-300 focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b] ${triggerClassName}`}
      >
        <span className={`truncate ${selected ? "text-[#28364b]" : "text-slate-400"}`}>
          {selected ? selected.short ?? selected.label : placeholder}
        </span>
        <ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 ${open ? "rotate-180" : ""}`} />
      </button>

      {portal ? (
        typeof document !== "undefined" &&
        createPortal(
          <AnimatePresence>
            {open && coords && (
              <motion.ul
                ref={menuRef}
                {...MOTION}
                style={{ position: "fixed", top: coords.top, bottom: coords.bottom, left: coords.left, width: coords.width, zIndex: 60 }}
                className={MENU_CLASS}
              >
                {menuContents}
              </motion.ul>
            )}
          </AnimatePresence>,
          document.body
        )
      ) : (
        <AnimatePresence>
          {open && (
            <motion.ul
              ref={menuRef}
              {...MOTION}
              style={menuWidth ? { width: menuWidth } : undefined}
              className={`absolute z-50 mt-1 ${menuWidth ? "" : "w-full"} ${MENU_CLASS}`}
            >
              {menuContents}
            </motion.ul>
          )}
        </AnimatePresence>
      )}
    </div>
  );
}
