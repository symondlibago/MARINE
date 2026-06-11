import { useState, useRef, useEffect } from "react";
import { AnimatePresence, motion } from "framer-motion";

/**
 * Free-text input with an animated suggestion dropdown.
 * You can type ANY value; suggestions are just shortcuts.
 */
export default function Combobox({ value, onChange, suggestions = [], placeholder = "", className = "", label = "Previously used" }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    const handler = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  const q = (value || "").toLowerCase();
  const filtered = suggestions
    .filter((s) => s && s.toLowerCase().includes(q) && s.toLowerCase() !== q)
    .slice(0, 8);

  return (
    <div ref={ref} className={`relative ${className}`}>
      <input
        value={value}
        onChange={(e) => {
          onChange(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        placeholder={placeholder}
        className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
      />
      <AnimatePresence>
        {open && filtered.length > 0 && (
          <motion.ul
            initial={{ opacity: 0, y: -4 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -4 }}
            transition={{ duration: 0.12 }}
            className="absolute z-50 mt-1 max-h-52 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
          >
            <li className="px-3 py-1 text-[10px] uppercase tracking-wide text-slate-300">{label}</li>
            {filtered.map((s) => (
              <li key={s}>
                <button
                  type="button"
                  onClick={() => {
                    onChange(s);
                    setOpen(false);
                  }}
                  className="block w-full px-3 py-1.5 text-left text-sm text-slate-600 transition-colors hover:bg-slate-50"
                >
                  {s}
                </button>
              </li>
            ))}
          </motion.ul>
        )}
      </AnimatePresence>
    </div>
  );
}
