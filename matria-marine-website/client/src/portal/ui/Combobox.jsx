import { useState, useRef, useEffect } from "react";
import { AnimatePresence, motion } from "framer-motion";

/**
 * Free-text input with an animated suggestion dropdown.
 * You can type ANY value; suggestions are just shortcuts.
 */
export default function Combobox({
  value,
  onChange,
  suggestions = [],
  placeholder = "",
  className = "",
  style,
  label = "Previously used",
  multiline = false,
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const boxRef = useRef(null);

  useEffect(() => {
    const handler = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  // A multiline box grows with its content so a long spec stays fully visible
  // instead of scrolling inside a one-line field.
  useEffect(() => {
    if (!multiline || !boxRef.current) return;
    boxRef.current.style.height = "auto";
    // Two lines tall by default, so it reads as a box that takes a spec line
    // rather than a one-line field.
    boxRef.current.style.height = `${Math.max(58, boxRef.current.scrollHeight)}px`;
  }, [value, multiline]);

  const q = (value || "").toLowerCase();
  const filtered = suggestions
    .filter((s) => s && s.toLowerCase().includes(q) && s.toLowerCase() !== q)
    .slice(0, 8);

  const shared = {
    value,
    onChange: (e) => {
      onChange(e.target.value);
      setOpen(true);
    },
    onFocus: () => setOpen(true),
    placeholder,
    className:
      "w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]",
  };

  return (
    <div ref={ref} style={style} className={`relative ${className}`}>
      {multiline ? (
        // Keeps every pasted line — an item's second description line used to be
        // silently dropped by the single-line input.
        <textarea ref={boxRef} rows={1} {...shared} className={`${shared.className} resize-y leading-snug`} />
      ) : (
        <input {...shared} />
      )}
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
