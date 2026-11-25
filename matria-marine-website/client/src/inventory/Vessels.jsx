import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Link } from "wouter";
import {
  Ship,
  Plus,
  ArrowRight,
  Archive,
  ArchiveRestore,
  Save,
  AlertTriangle,
  CircleAlert,
  CheckCircle2,
  Wallet,
  Users,
  ListPlus,
} from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { money, dateOf, btn, inputCls, numCls } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";
import Modal from "@/portal/ui/Modal";

/**
 * The fleet.
 *
 * One card a vessel, each carrying the figures its own dashboard shows —
 * they come from the same calculator on the server, so a card and the screen
 * it opens can never disagree. From here the office can see which ship needs
 * attention without opening five tabs.
 */
export default function Vessels() {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const [showArchived, setShowArchived] = useState(false);
  const [form, setForm] = useState(null);

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "vessels", showArchived],
    queryFn: async () =>
      (await inventoryAPI.vessels(showArchived ? { archived: 1 } : {})).data.data.vessels,
  });

  const refresh = () => qc.invalidateQueries({ queryKey: ["inv", "vessels"] });

  const create = useMutation({
    mutationFn: () =>
      inventoryAPI.createVessel({
        name: form.name.trim(),
        imo: form.imo.trim() || null,
        crew_size: form.crew_size === "" ? undefined : Number(form.crew_size),
        supply_period_days: form.supply_period_days === "" ? undefined : Number(form.supply_period_days),
        stock_catalogue: form.stock_catalogue,
      }),
    onSuccess: (res) => {
      toast.success(res.data.message);
      setForm(null);
      refresh();
    },
    onError: (e) =>
      toast.error(e?.response?.data?.errors?.imo?.[0] || e?.response?.data?.message || "Could not add the vessel."),
  });

  const remove = useMutation({
    mutationFn: (id) => inventoryAPI.removeVessel(id),
    onSuccess: (res) => {
      toast.success(res.data.message);
      refresh();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not remove the vessel."),
  });

  const restore = useMutation({
    mutationFn: (id) => inventoryAPI.restoreVessel(id),
    onSuccess: (res) => {
      toast.success(res.data.message);
      refresh();
    },
    onError: () => toast.error("Could not restore the vessel."),
  });

  // For a ship added before the master list went on automatically, and for one
  // that should pick up items added to the list since she joined.
  const stock = useMutation({
    mutationFn: (id) => inventoryAPI.stockCatalogue(id),
    onSuccess: (res) => {
      toast.success(res.data.message);
      refresh();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not add the item list."),
  });

  /**
   * A ship that has traded is archived, not deleted — its requisitions and
   * stocktakes are the fleet's record. One added by mistake goes entirely, so
   * the warning has to say which is about to happen.
   */
  const askRemove = async (vessel) => {
    // Said before the dialog, not after it: a confirmation that promises to
    // remove the ship and then fails is worse than not offering it.
    if ((vessel.crew?.total ?? 0) > 0) {
      toast.error(
        `${vessel.name} still has crew accounts. Remove those from her Settings first — they are people's logins.`
      );
      return;
    }

    const traded = vessel.last_requisition || vessel.open_period;

    const ok = await confirm({
      title: traded ? `Archive ${vessel.name}` : `Remove ${vessel.name}`,
      message: traded
        ? `${vessel.name} has history on it, so it will be archived rather than deleted. Its requisitions, deliveries and stocktakes stay readable and it can be restored at any time.`
        : `${vessel.name} has nothing recorded against it and will be removed entirely, along with its catalogue. This cannot be undone.`,
      confirmText: traded ? "Archive" : "Remove",
      tone: traded ? "default" : "danger",
    });

    if (ok) remove.mutate(vessel.id);
  };

  if (isLoading) return <PageLoader label="Loading the fleet…" />;

  const vessels = data ?? [];
  const active = vessels.filter((v) => v.is_active);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-[#28364b]">Fleet</h1>
          <p className="text-sm text-slate-500">
            Every vessel and what its stores need. Open one to work on its inventory, requisitions and rations.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
            <input
              type="checkbox"
              checked={showArchived}
              onChange={(e) => setShowArchived(e.target.checked)}
              className="h-3.5 w-3.5 accent-[#28364b]"
            />
            Show archived
          </label>
          <button
            onClick={() => setForm({ name: "", imo: "", crew_size: "", supply_period_days: "", stock_catalogue: true })}
            className={btn.primary}
          >
            <Plus className="h-4 w-4" /> Add a vessel
          </button>
        </div>
      </div>

      {vessels.length === 0 ? (
        <div className="rounded-xl border border-slate-200 bg-white px-6 py-20 text-center">
          <Ship className="mx-auto mb-3 h-10 w-10 text-slate-200" />
          <p className="text-sm font-semibold text-[#28364b]">No vessels yet</p>
          <p className="mt-1 text-sm text-slate-400">Add the first one and its stores can be set up straight away.</p>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {vessels.map((vessel) => (
            <VesselCard
              key={vessel.id}
              vessel={vessel}
              onRemove={() => askRemove(vessel)}
              onRestore={() => restore.mutate(vessel.id)}
              onStock={() => stock.mutate(vessel.id)}
              stocking={stock.isLoading && stock.variables === vessel.id}
            />
          ))}
        </div>
      )}

      {active.length > 0 && (
        <p className="text-xs text-slate-400">
          {active.length} {active.length === 1 ? "vessel" : "vessels"} on the fleet
          {vessels.length > active.length ? ` · ${vessels.length - active.length} archived` : ""}
        </p>
      )}

      <Modal open={!!form} onClose={() => setForm(null)} title="Add a vessel" maxWidth="max-w-lg">
        {form && (
          <form
            className="space-y-4 p-6"
            onSubmit={(e) => {
              e.preventDefault();
              create.mutate();
            }}
          >
            <p className="text-sm text-slate-500">
              Only the name is needed now. Crew size, supply period and the catering rates can be set from the vessel's
              own Settings once she is on the fleet.
            </p>

            {/* Said rather than silently done: 879 rows appear, and the office
                should know that is deliberate. */}
            <label className="flex cursor-pointer items-start gap-2 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-600">
              <input
                type="checkbox"
                checked={form.stock_catalogue}
                onChange={(e) => setForm((f) => ({ ...f, stock_catalogue: e.target.checked }))}
                className="mt-0.5 h-3.5 w-3.5 shrink-0 accent-[#28364b]"
              />
              <span>
                Put the standard provision list on her — the same items as the rest of the fleet, each starting at zero
                until her crew count it. Leave this off only if she works from a list of her own.
              </span>
            </label>

            <label className="block">
              <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Vessel name</span>
              <input
                value={form.name}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                placeholder="MV SOUTHERN CROSS"
                autoFocus
                className={`${inputCls} mt-1 w-full`}
              />
            </label>

            <div className="grid gap-4 sm:grid-cols-3">
              <label className="block">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">IMO</span>
                <input
                  value={form.imo}
                  onChange={(e) => setForm((f) => ({ ...f, imo: e.target.value }))}
                  placeholder="9414759"
                  className={`${inputCls} mt-1 w-full`}
                />
              </label>
              <label className="block">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Crew</span>
                <input
                  type="number"
                  min="1"
                  value={form.crew_size}
                  onChange={(e) => setForm((f) => ({ ...f, crew_size: e.target.value }))}
                  placeholder="20"
                  className={`${numCls} mt-1 w-full`}
                />
              </label>
              <label className="block">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Supply days</span>
                <input
                  type="number"
                  min="1"
                  value={form.supply_period_days}
                  onChange={(e) => setForm((f) => ({ ...f, supply_period_days: e.target.value }))}
                  placeholder="30"
                  className={`${numCls} mt-1 w-full`}
                />
              </label>
            </div>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setForm(null)} className={btn.ghost}>
                Cancel
              </button>
              <button type="submit" disabled={!form.name.trim() || create.isLoading} className={btn.primary}>
                {create.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Add vessel
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
}

/** One vessel, with the figures that say whether it needs attention. */
function VesselCard({ vessel, onRemove, onRestore, onStock, stocking }) {
  const s = vessel.summary ?? {};
  const crew = vessel.crew ?? { total: 0, active: 0, master: false, cook: false };
  const archived = !vessel.is_active;
  // A ship with no catalogue is a ship nobody can count. Said on the card,
  // with the one button that fixes it.
  const empty = !archived && (s.items_total ?? 0) === 0;

  // The card's tone follows the worst thing true of the ship, so a glance
  // across the fleet lands on whatever needs doing first.
  const urgent = (s.order_now ?? 0) > 0;
  const low = !urgent && (s.low ?? 0) > 0;

  const tone = archived
    ? "border-slate-200 bg-slate-50"
    : urgent
      ? "border-red-200 bg-white"
      : low
        ? "border-amber-200 bg-white"
        : "border-slate-200 bg-white";

  // The notes under the figures are the reason two cards used to come out
  // different shapes: a ship missing both her accounts grew two extra lines
  // and pushed her button below her neighbour's. Gathered into one block that
  // takes the slack, so every "Open dashboard" sits on the same line.
  const notes = [];
  if ((s.missing_prices ?? 0) > 0) {
    notes.push({
      tone: "warn",
      text: `${s.missing_prices} item${s.missing_prices === 1 ? "" : "s"} with no price`,
    });
  }
  // Until she has both, somebody ashore is doing her counting.
  if (!crew.master || !crew.cook) {
    notes.push({
      tone: "muted",
      text:
        crew.active === 0
          ? "No crew accounts — add her master and cook in Settings."
          : `No ${!crew.master ? "master" : "cook"} account yet.`,
    });
  }

  return (
    <div
      className={`flex h-full flex-col overflow-hidden rounded-xl border transition-shadow hover:shadow-md ${tone}`}
    >
      {/* A coloured rule rather than a tinted card: the status reads at a
          glance down a row of ships without washing the whole card out. */}
      <div
        className={`h-1 w-full ${
          archived ? "bg-slate-200" : urgent ? "bg-red-400" : low ? "bg-amber-400" : "bg-emerald-400"
        }`}
      />

      <div className="flex flex-1 flex-col p-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className={`truncate text-base font-bold ${archived ? "text-slate-400" : "text-[#28364b]"}`}>
              {vessel.name}
            </h2>
            <p className="mt-0.5 truncate text-xs text-slate-400">
              {vessel.imo ? `IMO ${vessel.imo} · ` : ""}
              {vessel.crew_size} crew · {vessel.supply_period_days}-day supply
            </p>
          </div>
          {archived ? (
            <span className="shrink-0 rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-500">
              Archived
            </span>
          ) : (
            <Ship className="h-5 w-5 shrink-0 text-[#cebd88]" />
          )}
        </div>

        {empty && (
          <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50/60 px-4 py-3">
            <p className="text-xs font-semibold text-amber-800">No item list yet</p>
            <p className="mt-0.5 text-[11px] leading-relaxed text-amber-700">
              She joined before the master provision list was put on automatically. Nothing is on board until her crew
              count it.
            </p>
            <button
              onClick={onStock}
              disabled={stocking}
              className="mt-2.5 inline-flex items-center gap-1.5 rounded-lg bg-amber-800 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-amber-900 disabled:opacity-50"
            >
              {stocking ? <Spinner className="h-3.5 w-3.5" /> : <ListPlus className="h-3.5 w-3.5" />} Add the item list
            </button>
          </div>
        )}

        {!archived && (
          <>
            <div className="mt-4 grid grid-cols-3 gap-2 text-center">
              <Pill icon={CheckCircle2} tone="good" value={s.good ?? 0} label="Good" />
              <Pill icon={CircleAlert} tone="low" value={s.low ?? 0} label="Low" />
              <Pill icon={AlertTriangle} tone="danger" value={s.order_now ?? 0} label="Order now" />
            </div>

            <div className="mt-4 space-y-2 border-t border-slate-100 pt-3.5 text-xs">
              <Line label="To order" value={`${s.items_to_order ?? 0} items · $${money(s.order_value ?? 0)}`} />
              <Line
                label="Budget"
                value={Number(s.budget) > 0 ? `$${money(s.budget)}` : "not set"}
                muted={!(Number(s.budget) > 0)}
                icon={Wallet}
              />
              <Line
                label="Open period"
                value={
                  vessel.open_period
                    ? `${vessel.open_period.label} · from ${dateOf(vessel.open_period.start_date)}`
                    : "none open"
                }
                muted={!vessel.open_period}
              />
              <Line
                label="Last order"
                value={
                  vessel.last_requisition
                    ? `${vessel.last_requisition.req_number} · ${dateOf(vessel.last_requisition.req_date)}`
                    : "none yet"
                }
                muted={!vessel.last_requisition}
              />
              <Line
                label="Can sign in"
                value={
                  crew.master && crew.cook
                    ? "Master · Cook"
                    : crew.active > 0
                      ? [crew.master && "Master", crew.cook && "Cook"].filter(Boolean).join(" · ") ||
                        `${crew.active} account${crew.active === 1 ? "" : "s"}`
                      : "nobody yet"
                }
                muted={crew.active === 0}
                icon={Users}
              />
            </div>
          </>
        )}

        {/* Takes whatever height is left over, which is what keeps the row
            level whether a ship has two notes under her or none. */}
        <div className="mt-3 flex-1 space-y-1">
          {notes.map((note) => (
            <p
              key={note.text}
              className={`text-[11px] leading-snug ${note.tone === "warn" ? "text-amber-600" : "text-slate-400"}`}
            >
              {note.text}
            </p>
          ))}
        </div>

        <div className="mt-4 flex items-center gap-2 border-t border-slate-100 pt-3.5">
          {archived ? (
            <button onClick={onRestore} className={`${btn.ghost} flex-1 justify-center`}>
              <ArchiveRestore className="h-4 w-4" /> Restore
            </button>
          ) : (
            <>
              <Link href={`/v/${vessel.id}`} className={`${btn.primary} flex-1 justify-center`}>
                Open dashboard <ArrowRight className="h-4 w-4" />
              </Link>
              <button
                onClick={onRemove}
                className="rounded-lg border border-slate-200 p-2 text-slate-500 transition-colors hover:border-slate-300 hover:bg-slate-100 hover:text-[#28364b]"
                title="Archive or remove"
              >
                <Archive className="h-4 w-4" />
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function Pill({ icon: Icon, tone, value, label }) {
  const tones = {
    good: "border-emerald-200 bg-emerald-50/60 text-emerald-700",
    low: "border-amber-200 bg-amber-50/60 text-amber-700",
    danger: "border-red-200 bg-red-50/60 text-red-700",
  };

  return (
    <div className={`rounded-lg border px-2 py-2 ${tones[tone]}`}>
      <Icon className="mx-auto h-3.5 w-3.5 opacity-60" />
      <p className="mt-1 text-lg font-bold leading-none">{value}</p>
      <p className="mt-1 text-[10px] font-semibold uppercase tracking-wide opacity-70">{label}</p>
    </div>
  );
}

function Line({ label, value, muted = false, icon: Icon }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <span className="flex items-center gap-1 whitespace-nowrap text-slate-400">
        {Icon && <Icon className="h-3 w-3" />} {label}
      </span>
      <span className={`truncate text-right ${muted ? "text-slate-300" : "font-medium text-[#28364b]"}`}>{value}</span>
    </div>
  );
}
