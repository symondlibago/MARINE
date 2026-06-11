import { useState, useRef, useEffect } from "react";
import { createPortal } from "react-dom";
import { AnimatePresence, motion } from "framer-motion";
import { ChevronDown } from "lucide-react";

/**
 * Styled unit picker: pick from the list OR type your own. The menu renders in
 * a portal (position: fixed) so it floats above the page and is never clipped
 * by a scrollable table — and it shows the full, scrollable list.
 */
export default function UnitSelect({ value, onChange, options = [], placeholder = "unit" }) {
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState(null);
  const wrapRef = useRef(null);
  const inputRef = useRef(null);

  const place = () => {
    if (inputRef.current) {
      const r = inputRef.current.getBoundingClientRect();
      setRect({ left: r.left, top: r.bottom + 4, width: Math.max(r.width, 130) });
    }
  };

  useEffect(() => {
    if (!open) return;
    place();
    // Follow the field on scroll/resize instead of closing the menu.
    const reposition = () => place();
    const onDocDown = (e) => {
      if (wrapRef.current?.contains(e.target)) return;
      if (e.target.closest?.("[data-unit-menu]")) return;
      setOpen(false);
    };
    window.addEventListener("scroll", reposition, true);
    window.addEventListener("resize", reposition);
    document.addEventListener("mousedown", onDocDown);
    return () => {
      window.removeEventListener("scroll", reposition, true);
      window.removeEventListener("resize", reposition);
      document.removeEventListener("mousedown", onDocDown);
    };
  }, [open]);

  const q = (value || "").toLowerCase();
  const matches = options.filter((o) => o.toLowerCase().includes(q));
  const list = matches.length ? matches : options;

  return (
    <div ref={wrapRef} className="relative">
      <div className="flex items-center rounded border border-slate-200 focus-within:border-[#28364b] focus-within:ring-1 focus-within:ring-[#28364b]">
        <input
          ref={inputRef}
          value={value}
          onChange={(e) => { onChange(e.target.value); setOpen(true); place(); }}
          onFocus={() => { setOpen(true); place(); }}
          placeholder={placeholder}
          className="w-full min-w-0 rounded bg-transparent px-2 py-1 text-sm focus:outline-none"
        />
        <button type="button" tabIndex={-1} onClick={() => { setOpen((o) => !o); place(); }} className="shrink-0 px-1 text-slate-400 hover:text-[#28364b]">
          <ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? "rotate-180" : ""}`} />
        </button>
      </div>

      {createPortal(
        <AnimatePresence>
          {open && rect && (
            <motion.ul
              data-unit-menu
              initial={{ opacity: 0, y: -4, scale: 0.98 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: -4, scale: 0.98 }}
              transition={{ duration: 0.12 }}
              style={{ position: "fixed", left: rect.left, top: rect.top, width: rect.width, zIndex: 1000 }}
              className="max-h-60 overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-xl"
            >
              {list.map((o) => (
                <li key={o}>
                  <button
                    type="button"
                    onMouseDown={(e) => { e.preventDefault(); onChange(o); setOpen(false); }}
                    className={`block w-full px-3 py-1.5 text-left text-sm transition-colors hover:bg-slate-50 ${o === value ? "font-medium text-[#28364b]" : "text-slate-600"}`}
                  >
                    {o}
                  </button>
                </li>
              ))}
            </motion.ul>
          )}
        </AnimatePresence>,
        document.body
      )}
    </div>
  );
}
