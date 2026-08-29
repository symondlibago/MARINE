import { Search, X } from "lucide-react";
import Select from "./Select";

/**
 * The search box and filter dropdowns that sit above a list.
 *
 * Seven pages had grown their own copy of this markup, which is how the same
 * control ends up a different width and a different placeholder on every page.
 * One definition, so a fix reaches all of them.
 *
 * filters: [{ value, onChange, options, width?, title? }] — rendered in order,
 * left to right, after the search box. Anything passed as children lands on the
 * right, for a page that needs its own extra control.
 */
export default function ListToolbar({
  search,
  onSearch,
  placeholder = "Search…",
  filters = [],
  children,
}) {
  return (
    <div className="flex flex-wrap items-center gap-3">
      <div className="relative min-w-[220px] flex-1">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input
          value={search}
          onChange={(e) => onSearch(e.target.value)}
          placeholder={placeholder}
          className="w-full rounded-lg border border-slate-200 px-3 py-2 pl-9 pr-9 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]"
        />
        {/* Clearing by hand means selecting text in a box you cannot see the end
            of; an empty list with no obvious way back is the usual dead end. */}
        {search && (
          <button
            type="button"
            onClick={() => onSearch("")}
            title="Clear search"
            className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        )}
      </div>

      {filters.map((f, i) => (
        <div key={i} className={f.width || "w-44"} title={f.title}>
          <Select value={f.value} onChange={f.onChange} options={f.options} />
        </div>
      ))}

      {children}
    </div>
  );
}
