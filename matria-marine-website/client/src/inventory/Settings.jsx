import { useEffect, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Save,
  Info,
  Ship,
  Lock,
  SlidersHorizontal,
  Wallet,
  UtensilsCrossed,
  CalendarRange,
  CheckCircle2,
} from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { useAccess } from "./access";
import VesselAccounts from "./VesselAccounts";
import { btn, inputCls, numCls } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import DatePicker from "@/portal/ui/DatePicker";

/**
 * The reference values behind every calculation.
 *
 * Two audiences on one screen. The master sets how his vessel is provisioned —
 * how many to feed, how long the next delivery has to last, how much buffer he
 * wants. The office sets what she is and what she costs: her name, her budget,
 * and the rates the owner is invoiced at.
 *
 * So the commercial half is drawn only for the office, and the save sends only
 * the fields it drew. The API refuses the rest from a master in any case —
 * this keeps the screen honest rather than being the thing that enforces it.
 */
export default function Settings() {
  const qc = useQueryClient();
  const [form, setForm] = useState(null);
  // The form as it was loaded, so the page can say whether anything has been
  // touched. A settings screen whose Save button is always live gives no clue
  // whether the last click took, which on a screen that silently redrives
  // every order quantity is worth knowing.
  const [saved, setSaved] = useState(null);
  const { vessel: data, office } = useAccess();

  useEffect(() => {
    if (!data) return;
    const loaded = {
      name: data.name || "",
      imo: data.imo || "",
      crew_size: data.crew_size ?? 20,
      previous_crew_size: data.previous_crew_size ?? "",
      supply_period_days: data.supply_period_days ?? 30,
      safety_margin_pct: data.safety_margin_pct ?? 10,
      budget: data.budget ?? 0,
      ration_rate: data.ration_rate ?? 0,
      visitor_meal_rate: data.visitor_meal_rate ?? 0,
      charterer_meal_rate: data.charterer_meal_rate ?? 0,
      currency: data.currency || "USD",
      exchange_rate: data.exchange_rate ?? 1,
      supply_date: (data.supply_date || "").slice(0, 10),
      next_supply_date: (data.next_supply_date || "").slice(0, 10),
    };
    setForm(loaded);
    setSaved(loaded);
  }, [data]);

  const save = useMutation({
    mutationFn: () => {
      // What the master may change: the ship as she is sailing.
      const payload = {
        crew_size: Number(form.crew_size),
        previous_crew_size: form.previous_crew_size === "" ? null : Number(form.previous_crew_size),
        supply_period_days: Number(form.supply_period_days),
        safety_margin_pct: Number(form.safety_margin_pct),
        supply_date: form.supply_date || null,
        next_supply_date: form.next_supply_date || null,
      };

      // Sent only when they were shown, because sending one of these at all is
      // what the API refuses a master for.
      if (office) {
        Object.assign(payload, {
          name: form.name.trim(),
          imo: form.imo?.trim() || null,
          budget: Number(form.budget) || 0,
          ration_rate: Number(form.ration_rate) || 0,
          visitor_meal_rate: Number(form.visitor_meal_rate) || 0,
          charterer_meal_rate: Number(form.charterer_meal_rate) || 0,
          currency: form.currency.trim().toUpperCase() || "USD",
          exchange_rate: Number(form.exchange_rate) || 1,
        });
      }

      return inventoryAPI.saveSettings(data.id, payload);
    },
    onSuccess: () => {
      toast.success("Settings saved — order quantities have been recalculated.");
      // These values feed every calculated figure in the module.
      qc.invalidateQueries();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the settings."),
  });

  if (!form) return <PageLoader label="Loading settings…" />;

  // Compared as a whole rather than field by field: every value on this screen
  // is a string or a number, so the shallow dump is a faithful answer.
  const dirty = !!saved && JSON.stringify(form) !== JSON.stringify(saved);

  return (
    <div className="max-w-3xl space-y-5 pb-4">
      <div>
        <h1 className="text-xl font-bold text-[#28364b]">Settings</h1>
        <p className="mt-1 text-sm text-slate-500">
          The reference figures behind every target and order quantity on {data.name}. Change one and the whole
          catalogue is scored again.
        </p>
      </div>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
        className="space-y-4"
      >
        {office ? (
          <Section icon={Ship} title="Vessel" description="Her name and number, as they appear on every document.">
            <div className="grid gap-4 sm:grid-cols-2">
              <F label="Vessel name">
                <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} className={`${inputCls} w-full`} />
              </F>
              <F label="IMO" hint="Optional">
                <input value={form.imo} onChange={(e) => setForm((f) => ({ ...f, imo: e.target.value }))} className={`${inputCls} w-full`} />
              </F>
            </div>
          </Section>
        ) : (
          /* Said once, plainly, rather than leaving a master wondering where
             the budget went. */
          <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4">
            <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-50 ring-1 ring-slate-200">
              <Lock className="h-3.5 w-3.5 text-slate-400" />
            </span>
            <p className="text-xs leading-relaxed text-slate-500">
              You set how {data.name} is provisioned. Her particulars, her budget and the rates the owner is invoiced at
              are agreed ashore — ask the office to change those.
            </p>
          </div>
        )}

        <Section
          icon={SlidersHorizontal}
          title="Ordering"
          description="How much the next delivery has to carry. These four decide every target in the catalogue."
        >
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <F label="Crew on board" hint="People to feed">
              <input
                type="number"
                min="1"
                value={form.crew_size}
                onChange={(e) => setForm((f) => ({ ...f, crew_size: e.target.value }))}
                className={`${numCls} w-full`}
              />
            </F>
            <F label="Previous crew" hint="Scales the next order if the crew changed">
              <input
                type="number"
                min="0"
                value={form.previous_crew_size}
                onChange={(e) => setForm((f) => ({ ...f, previous_crew_size: e.target.value }))}
                className={`${numCls} w-full`}
              />
            </F>
            <F label="Supply period" hint="Days the next delivery must cover">
              <input
                type="number"
                min="1"
                value={form.supply_period_days}
                onChange={(e) => setForm((f) => ({ ...f, supply_period_days: e.target.value }))}
                className={`${numCls} w-full`}
              />
            </F>
            <F label="Safety margin %" hint="Buffer on top of expected use">
              <input
                type="number"
                min="0"
                max="100"
                step="any"
                value={form.safety_margin_pct}
                onChange={(e) => setForm((f) => ({ ...f, safety_margin_pct: e.target.value }))}
                className={`${numCls} w-full`}
              />
            </F>
          </div>

          <div className="mt-5 flex items-start gap-2.5 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3">
            <Info className="mt-0.5 h-4 w-4 shrink-0 text-[#cebd88]" />
            <p className="text-xs leading-relaxed text-slate-600">
              <span className="font-semibold text-[#28364b]">
                Target = daily use × {form.supply_period_days || 30} days × (1 + {form.safety_margin_pct || 0}%)
              </span>
              , rounded up. Daily use is measured from the last closed supply period, never guessed.
            </p>
          </div>
        </Section>

        {office && (
        <Section
          icon={Wallet}
          title="Budget & currency"
          description="What she may spend in a month, and the money her suppliers quote in."
        >
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <F label="Budget (USD)" hint="Spending limit per order">
              <input
                type="number"
                min="0"
                step="any"
                value={form.budget}
                onChange={(e) => setForm((f) => ({ ...f, budget: e.target.value }))}
                className={`${numCls} w-full`}
              />
            </F>
            <F label="Local currency" hint="Supplier prices may be quoted in it">
              <input
                value={form.currency}
                onChange={(e) => setForm((f) => ({ ...f, currency: e.target.value.toUpperCase() }))}
                className={`${inputCls} w-full`}
              />
            </F>
            <F label="Rate to USD">
              <input
                type="number"
                min="0"
                step="any"
                value={form.exchange_rate}
                onChange={(e) => setForm((f) => ({ ...f, exchange_rate: e.target.value }))}
                className={`${numCls} w-full`}
              />
            </F>
          </div>
        </Section>
        )}

        {office && (
        <Section
          icon={UtensilsCrossed}
          title="Catering rates"
          description="What the owner is invoiced per head. These drive the ration report and nothing else."
        >
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <F label="Ration rate" hint="Crew & passengers, per man-day">
              <input
                type="number"
                min="0"
                step="any"
                value={form.ration_rate}
                onChange={(e) => setForm((f) => ({ ...f, ration_rate: e.target.value }))}
                className={`${numCls} w-full`}
              />
            </F>
            <F label="Visitor meal rate" hint="Owner's & crew's guests, per meal">
              <input
                type="number"
                min="0"
                step="any"
                value={form.visitor_meal_rate}
                onChange={(e) => setForm((f) => ({ ...f, visitor_meal_rate: e.target.value }))}
                className={`${numCls} w-full`}
              />
            </F>
            <F label="Charterer meal rate" hint="Often a separate agreement">
              <input
                type="number"
                min="0"
                step="any"
                value={form.charterer_meal_rate}
                onChange={(e) => setForm((f) => ({ ...f, charterer_meal_rate: e.target.value }))}
                className={`${numCls} w-full`}
              />
            </F>
          </div>

          <div className="mt-5 flex items-start gap-2.5 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3">
            <Info className="mt-0.5 h-4 w-4 shrink-0 text-[#cebd88]" />
            <p className="text-xs leading-relaxed text-slate-600">
              Two different bases, which is why there is more than one rate. Crew and passengers are billed by the{" "}
              <strong className="text-[#28364b]">ration</strong> — three meals feed one man for one day, and that day
              costs {form.ration_rate || 0} whether he took two meals or three. Visitors are billed by the{" "}
              <strong className="text-[#28364b]">meal</strong> at {form.visitor_meal_rate || 0}, because a guest at
              lunch is not a man fed for a day.
            </p>
          </div>
        </Section>
        )}

        <Section
          icon={CalendarRange}
          title="Supply dates"
          description="When this order goes ashore, and when the next delivery is expected alongside."
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <F label="This supply" hint="When the order is raised">
              <DatePicker value={form.supply_date} onChange={(v) => setForm((f) => ({ ...f, supply_date: v }))} />
            </F>
            <F label="Next supply" hint="Expected delivery date">
              <DatePicker
                value={form.next_supply_date}
                onChange={(v) => setForm((f) => ({ ...f, next_supply_date: v }))}
              />
            </F>
          </div>
        </Section>

        {/* Pinned, because this is a long screen and the one thing you came to
            do should never be somewhere you have to scroll to find. */}
        <div className="sticky bottom-0 z-10 -mx-4 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur md:-mx-6 md:px-6">
          <div className="flex items-center justify-between gap-3">
            <p className="flex items-center gap-1.5 text-xs text-slate-500">
              {dirty ? (
                <>
                  <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-[#cebd88]" />
                  Unsaved changes
                </>
              ) : (
                <>
                  <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                  Everything saved
                </>
              )}
            </p>
            <button type="submit" disabled={!dirty || save.isLoading} className={btn.primary}>
              {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save settings
            </button>
          </div>
        </div>
      </form>

      {/* Her crew's logins. Outside the form: these save one at a time, and a
          stray Enter in a password box should not submit the settings. The
          office manages them — a master who could add accounts could add
          himself a second one. */}
      {office && <VesselAccounts vessel={data} />}
    </div>
  );
}

/**
 * One block of the screen, headed by what it is for.
 *
 * Every section looked identical before — five white boxes with a four-word
 * heading each — so the page read as a wall and nothing said which figure
 * mattered. The description is the part that earns its place: it says what the
 * fields below actually move.
 */
function Section({ icon: Icon, title, description, children }) {
  return (
    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <div className="flex items-start gap-3 border-b border-slate-100 bg-slate-50/70 px-5 py-3.5">
        <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-slate-200">
          <Icon className="h-3.5 w-3.5 text-[#cebd88]" />
        </span>
        <div className="min-w-0">
          <h2 className="text-sm font-bold text-[#28364b]">{title}</h2>
          {description && <p className="mt-0.5 text-xs leading-relaxed text-slate-500">{description}</p>}
        </div>
      </div>
      <div className="p-5">{children}</div>
    </section>
  );
}

function F({ label, hint, children }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</span>
      {children}
      {hint && <span className="mt-1.5 block text-[11px] leading-snug text-slate-400">{hint}</span>}
    </label>
  );
}
