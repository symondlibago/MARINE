import { useState, useRef, useEffect, useLayoutEffect } from "react";
import { createPortal } from "react-dom";
import { AnimatePresence, motion } from "framer-motion";
import { DayPicker } from "react-day-picker";
import "react-day-picker/style.css";
import { Calendar as CalendarIcon, X } from "lucide-react";

function toYMD(d) {
  if (!d) return "";
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

const POPOVER_WIDTH = 300; // approx calendar width — used to keep it on-screen
const POPOVER_HEIGHT = 340; // approx calendar height — used to decide flip-up

/**
 * Animated calendar popover. value/onChange use 'yyyy-mm-dd' strings.
 * The popover is portaled to <body> and fixed-positioned so it is never
 * clipped by an ancestor's overflow (e.g. cards with overflow-hidden).
 */
export default function DatePicker({ value, onChange, placeholder = "Select date" }) {
  const [open, setOpen] = useState(false);
  const [coords, setCoords] = useState(null); // { left, top?, bottom? }
  const btnRef = useRef(null);
  const popRef = useRef(null);

  const place = () => {
    const el = btnRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const spaceBelow = window.innerHeight - r.bottom;
    const up = spaceBelow < POPOVER_HEIGHT && r.top > spaceBelow;
    const left = Math.max(8, Math.min(r.left, window.innerWidth - POPOVER_WIDTH - 8));
    setCoords({
      left,
      top: up ? undefined : r.bottom + 4,
      bottom: up ? window.innerHeight - r.top + 4 : undefined,
    });
  };

  // Position on open, and keep it anchored while scrolling / resizing.
  useLayoutEffect(() => {
    if (!open) return;
    place();
    const onMove = () => place();
    window.addEventListener("scroll", onMove, true);
    window.addEventListener("resize", onMove);
    return () => {
      window.removeEventListener("scroll", onMove, true);
      window.removeEventListener("resize", onMove);
    };
  }, [open]);

  // Close on outside click — the popover lives in a portal, so check both refs.
  useEffect(() => {
    if (!open) return;
    const handler = (e) => {
      if (btnRef.current?.contains(e.target)) return;
      if (popRef.current?.contains(e.target)) return;
      setOpen(false);
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [open]);

  const selected = value ? new Date(`${value}T00:00:00`) : undefined;

  return (
    <div className="relative">
      <button
        ref={btnRef}
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm transition-colors hover:border-slate-300 focus:border-[#28364b] focus:outline-none"
      >
        <span className={value ? "text-[#28364b]" : "text-slate-400"}>{value || placeholder}</span>
        <span className="flex items-center gap-1">
          {value && (
            <X
              className="h-3.5 w-3.5 text-slate-400 hover:text-red-500"
              onClick={(e) => {
                e.stopPropagation();
                onChange("");
              }}
            />
          )}
          <CalendarIcon className="h-4 w-4 text-slate-400" />
        </span>
      </button>
      {typeof document !== "undefined" &&
        createPortal(
          <AnimatePresence>
            {open && coords && (
              <motion.div
                ref={popRef}
                initial={{ opacity: 0, y: -4, scale: 0.98 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                exit={{ opacity: 0, y: -4, scale: 0.98 }}
                transition={{ duration: 0.14 }}
                style={{ position: "fixed", top: coords.top, bottom: coords.bottom, left: coords.left, zIndex: 60 }}
                className="rounded-xl border border-slate-200 bg-white p-2 shadow-lg"
              >
                <DayPicker
                  mode="single"
                  selected={selected}
                  defaultMonth={selected}
                  onSelect={(d) => {
                    onChange(toYMD(d));
                    setOpen(false);
                  }}
                  styles={{ caption: { color: "#28364b" } }}
                  modifiersClassNames={{ selected: "!bg-[#28364b] !text-white" }}
                />
              </motion.div>
            )}
          </AnimatePresence>,
          document.body
        )}
    </div>
  );
}
