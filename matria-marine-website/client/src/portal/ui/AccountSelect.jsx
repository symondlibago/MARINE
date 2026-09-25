import { useQuery } from "@tanstack/react-query";
import { accountingAPI } from "@/pages/api";
import Select from "./Select";

/**
 * Pick the account a document is booked to.
 *
 * This is the ONLY thing a user sets about a document's accounting treatment.
 * Its GST code, the F5 box it lands in and the statement it appears on are all
 * read back from the chart — so the treatment shown beneath the box is a live
 * consequence of the choice, not a second field that can drift out of step.
 *
 * `side` narrows the list to the accounts that make sense: income accounts for
 * a sale, expense accounts for a purchase. Everything is still on the chart,
 * but nobody needs to scroll past Owner's Equity to invoice a customer.
 */

const GST_TONE = {
  SR: "bg-blue-100 text-blue-700",
  ZI: "bg-green-100 text-green-700",
  ESN: "bg-amber-100 text-amber-700",
  OS: "bg-slate-100 text-slate-600",
};

const SIDE_TYPES = {
  sales: ["income"],
  purchase: ["expense"],
  // Enquiry and offer lines are coded against the whole chart: a line can be
  // the cost of buying something or the revenue from selling it, and staff
  // decide which.
  all: ["income", "expense", "asset", "liability", "equity"],
};

/** The chart, fetched once and shared by every picker on the page. */
export function useChart() {
  return useQuery({
    queryKey: ["accounting", "chart"],
    queryFn: async () => (await accountingAPI.chart()).data.data,
    staleTime: 5 * 60 * 1000,
  });
}

/**
 * The same picker, sized for a cell inside a line-item grid.
 *
 * Bare — no label, no treatment caption — because a hundred rows of captions is
 * noise. The chosen code's name and GST treatment ride in the tooltip instead.
 *
 * Legacy values are preserved rather than silently dropped: 45 enquiry and
 * offer lines carry `315030` from the group's German chart, and one carries the
 * typo `31530`. Both stay selectable and visible, marked as old, so opening an
 * old enquiry never quietly rewrites what it was coded to. Picking a real
 * account replaces it.
 *
 * `full` spells the account out on the closed box instead of showing the bare
 * number. The offer grid is too narrow for it, but on an invoice or a purchase
 * order there is room — and "5200" alone reads as plausible on a line it has no
 * business being on, where "5200 — CTM FX Loss & Transfer Costs" does not.
 */
export function AccountCodeCell({ value, onChange, className = "", style, placeholder = "Acct code", full = false }) {
  const { data } = useChart();

  const all = data?.accounts ?? [];
  const selected = all.find((a) => a.code === value);
  const legacy = value && !selected;

  // Omitting `short` leaves the trigger showing the whole label.
  const brief = (text) => (full ? {} : { short: text });

  const options = [
    { value: "", label: placeholder, ...brief(placeholder) },
    // A code the chart does not know stays selectable so opening an old
    // enquiry never quietly rewrites what it was coded to.
    ...(legacy ? [{ value, label: `${value} — not on the chart`, ...brief(value) }] : []),
    ...all
      .filter((a) => a.is_active)
      // The cell shows the bare code; the menu spells out what it means.
      .map((a) => ({ value: a.code, label: `${a.code} — ${a.name}`, ...brief(a.code) })),
  ];

  return (
    <div style={style} className={className} title={selected ? `${selected.name} · ${selected.gst_code} ${selected.gst_label}` : undefined}>
      <Select
        value={value ?? ""}
        onChange={onChange}
        options={options}
        placeholder={placeholder}
        portal
        menuWidth={300}
        triggerClassName={`px-2 py-1.5 ${legacy ? "!border-amber-300 bg-amber-50" : ""}`}
      />
    </div>
  );
}

export default function AccountSelect({
  value,
  onChange,
  side = "sales",
  label = "Account code",
  hint,
}) {
  const { data, isLoading } = useChart();

  const all = data?.accounts ?? [];
  const wanted = SIDE_TYPES[side] ?? [];
  const options = all.filter((a) => a.is_active && wanted.includes(a.type));

  const selected = all.find((a) => a.code === value);
  // A code that is not on the chart (older data, or the group's own coding)
  // must stay visible rather than silently snapping to something else.
  const offChart = value && !selected;

  return (
    <div>
      <label className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        {label}
      </label>

      <Select
        value={value ?? ""}
        onChange={onChange}
        placeholder={isLoading ? "Loading chart…" : "Choose account…"}
        options={[
          ...(offChart ? [{ value, label: `${value} — not on the chart` }] : []),
          ...options.map((a) => ({ value: a.code, label: `${a.code} — ${a.name}` })),
        ]}
      />

      <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
        {selected ? (
          <>
            <span className={`inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold ${GST_TONE[selected.gst_code]}`}>
              {selected.gst_code}
            </span>
            <span className="text-slate-500">{selected.gst_label}</span>
          </>
        ) : offChart ? (
          <span className="text-amber-600">
            Not on the chart — this document is left out of every GST box until a real account is picked.
          </span>
        ) : null}
      </div>

      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  );
}
