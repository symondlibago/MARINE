import { useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ChevronDown, Check, Search } from "lucide-react";

/**
 * Server-side searchable single-select for large master lists (customers, vendors).
 * Instead of loading the whole table into a <select>, it fetches ~20 matches as
 * you type — so it stays fast whether there are 50 records or 50,000.
 *
 * api      : must expose list({ search, per_page }) and get(id)
 * value    : selected id (string|number) or "" when nothing is chosen
 * onChange : (idString, entity|null) — entity is the full record (or null on clear)
 * queryKey : cache namespace, e.g. "customers" / "vendors"
 */
export default function EntityPicker({
  api,
  value,
  onChange,
  placeholder = "Select",
  queryKey = "entities",
  className = "",
}) {
  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState("");
  const [debounced, setDebounced] = useState("");
  const [label, setLabel] = useState("");
  const ref = useRef(null);

  // Close on outside click.
  useEffect(() => {
    const handler = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  // Debounce the search term so we don't hit the server on every keystroke.
  useEffect(() => {
    const t = setTimeout(() => setDebounced(term), 250);
    return () => clearTimeout(t);
  }, [term]);

  // Resolve the label for the currently selected id (one tiny request).
  useEffect(() => {
    let cancelled = false;
    if (!value) {
      setLabel("");
      return;
    }
    api
      .get(value)
      .then((res) => {
        if (!cancelled) setLabel(res.data.data?.name ?? "");
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [value, api]);

  const { data, isFetching } = useQuery({
    queryKey: [queryKey, "picker", debounced],
    queryFn: async () =>
      (await api.list({ ...(debounced ? { search: debounced } : {}), per_page: 20 })).data.data,
    enabled: open,
    keepPreviousData: true,
  });
  const options = data ?? [];

  const pick = (entity) => {
    onChange(String(entity.id), entity);
    setLabel(entity.name ?? "");
    setOpen(false);
    setTerm("");
  };

  return (
    <div ref={ref} className={`relative ${className}`}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm transition-colors hover:border-slate-300 focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
      >
        <span className={`truncate ${value ? "text-[#28364b]" : "text-slate-400"}`}>
          {value ? label || "…" : placeholder}
        </span>
        <ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 ${open ? "rotate-180" : ""}`} />
      </button>

      {open && (
        <div className="absolute z-50 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg">
          <div className="relative border-b border-slate-100 p-2">
            <Search className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              autoFocus
              value={term}
              onChange={(e) => setTerm(e.target.value)}
              placeholder="Type to search…"
              className="w-full rounded-md border border-slate-200 px-3 py-1.5 pl-9 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
            />
          </div>
          <ul className="max-h-56 overflow-auto py-1">
            {value && (
              <li>
                <button
                  type="button"
                  onClick={() => {
                    onChange("", null);
                    setLabel("");
                    setOpen(false);
                    setTerm("");
                  }}
                  className="block w-full px-3 py-1.5 text-left text-sm text-slate-400 hover:bg-slate-50"
                >
                  — Clear —
                </button>
              </li>
            )}
            {isFetching && options.length === 0 ? (
              <li className="px-3 py-3 text-center text-xs text-slate-400">Searching…</li>
            ) : options.length === 0 ? (
              <li className="px-3 py-3 text-center text-xs text-slate-400">No matches.</li>
            ) : (
              options.map((o) => (
                <li key={o.id}>
                  <button
                    type="button"
                    onClick={() => pick(o)}
                    className={`flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm transition-colors hover:bg-slate-50 ${
                      String(o.id) === String(value) ? "font-medium text-[#28364b]" : "text-slate-600"
                    }`}
                  >
                    <span className="truncate">{o.name}</span>
                    {String(o.id) === String(value) && <Check className="h-3.5 w-3.5 shrink-0" />}
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}
    </div>
  );
}
