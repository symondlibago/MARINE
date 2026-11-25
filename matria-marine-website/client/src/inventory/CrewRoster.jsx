import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Users,
  ChevronDown,
  ChevronRight,
  Plus,
  Trash2,
  UserMinus,
  UserPlus,
  ClipboardPaste,
  X,
  Save,
  AlertTriangle,
} from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { btn, inputCls } from "./lib";
import Select from "@/portal/ui/Select";
import { useConfirm } from "@/portal/ui/confirm";
import { Spinner } from "@/portal/ui/Loading";

/**
 * Who is aboard.
 *
 * The ration sheet below counts meals and the owner is billed from the totals.
 * What it could not say was whose. Twenty meals a day is a figure; twenty
 * named men is a record, and the master is the one who gets asked to stand
 * behind it when an invoice is queried.
 *
 * Collapsed by default: the roster changes at a crew change and not otherwise,
 * while the sheet underneath is filled in every day.
 */
export default function CrewRoster({ vessel }) {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const [open, setOpen] = useState(false);
  const [adding, setAdding] = useState(null);   // {} for one, "list" for many

  const { data, isLoading } = useQuery({
    queryKey: ["inv", "crew"],
    queryFn: async () => (await inventoryAPI.crew()).data.data,
  });

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["inv", "crew"] });
    qc.invalidateQueries({ queryKey: ["inv", "rations"] });
  };

  const told = (fallback) => ({
    onSuccess: (res) => {
      toast.success(res.data.message || fallback);
      setAdding(null);
      refresh();
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not do that."),
  });

  const add = useMutation({ mutationFn: (payload) => inventoryAPI.addCrew(payload), ...told("Signed on.") });
  const addList = useMutation({ mutationFn: (payload) => inventoryAPI.addCrewList(payload), ...told("Signed on.") });
  const update = useMutation({
    mutationFn: ({ id, payload }) => inventoryAPI.updateCrew(id, payload),
    ...told("Updated."),
  });
  const remove = useMutation({ mutationFn: (id) => inventoryAPI.removeCrew(id), ...told("Removed.") });

  const signOff = async (member) => {
    const ok = await confirm({
      title: `Sign ${member.name} off?`,
      message:
        "They come off the roster and stop counting towards the crew aboard. The name is kept — last month's ration " +
        "report still has to name the men it was billed for.",
      confirmText: "Sign off",
    });
    if (ok) update.mutate({ id: member.id, payload: { is_active: false } });
  };

  const askRemove = async (member) => {
    const ok = await confirm({
      title: `Remove ${member.name} from the roster?`,
      message: "Deleted outright, for a name typed in by mistake. To record somebody leaving, sign them off instead.",
      confirmText: "Remove",
      tone: "danger",
    });
    if (ok) remove.mutate(member.id);
  };

  const crew = data?.crew ?? [];
  const aboard = data?.aboard ?? { total: 0 };
  const expected = data?.crew_size ?? 0;

  // Worth saying when it happens: the sheet's figures come from the settings,
  // and a roster that has drifted from them means one of the two is wrong.
  const drifted = aboard.total > 0 && expected > 0 && aboard.total !== expected;

  return (
    <section className="rounded-xl border border-slate-200 bg-white">
      <button
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center gap-2.5 px-5 py-3.5 text-left"
      >
        {open ? (
          <ChevronDown className="h-4 w-4 shrink-0 text-slate-400" />
        ) : (
          <ChevronRight className="h-4 w-4 shrink-0 text-slate-400" />
        )}
        <Users className="h-4 w-4 shrink-0 text-[#cebd88]" />
        <span className="text-sm font-bold text-[#28364b]">Who is aboard</span>
        <span className="text-xs text-slate-400">
          {isLoading
            ? "…"
            : aboard.total === 0
              ? "nobody named yet"
              : `${aboard.total} named` +
                (aboard.charterer ? ` · ${aboard.charterer} charterer's` : "") +
                (aboard.passenger ? ` · ${aboard.passenger} passengers` : "")}
        </span>
        {drifted && (
          <span
            className="ml-auto flex items-center gap-1 text-[11px] font-semibold text-amber-600"
            title={`Settings say ${expected} crew`}
          >
            <AlertTriangle className="h-3.5 w-3.5" /> settings say {expected}
          </span>
        )}
      </button>

      {open && (
        <div className="border-t border-slate-100 px-5 py-4">
          <p className="mb-4 text-xs text-slate-500">
            The names behind the figures below. A ration report with twenty meals on it and no names is hard to check
            and harder to dispute — and {vessel?.name || "the vessel"}'s master is the one who gets asked.
          </p>

          {adding === "list" ? (
            <PasteList busy={addList.isLoading} onCancel={() => setAdding(null)} onSave={(p) => addList.mutate(p)} />
          ) : adding ? (
            <OneName busy={add.isLoading} onCancel={() => setAdding(null)} onSave={(p) => add.mutate(p)} />
          ) : (
            <div className="mb-4 flex flex-wrap gap-2">
              <button onClick={() => setAdding({})} className={btn.ghost}>
                <Plus className="h-4 w-4" /> Sign somebody on
              </button>
              <button onClick={() => setAdding("list")} className={btn.ghost} title="A crew change is a dozen people on one day">
                <ClipboardPaste className="h-4 w-4" /> Paste a crew list
              </button>
            </div>
          )}

          {isLoading ? (
            <div className="flex justify-center py-6">
              <Spinner className="h-5 w-5" />
            </div>
          ) : crew.length === 0 ? (
            <p className="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-400">
              Nobody named yet. The sheet below still works — this only puts names against the figures.
            </p>
          ) : (
            // Laid out as the ration report lays it out — number, rank, name,
            // what they are charged as. The same four columns in the same order
            // on screen and on the PDF, so checking one against the other is
            // reading across rather than hunting.
            <div className="overflow-hidden rounded-lg border border-slate-200">
              <div className="overflow-x-auto">
                <table className="w-full min-w-[520px] text-sm">
                  <thead>
                    <tr className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
                      <th className="w-10 px-3 py-2 font-semibold">#</th>
                      <th className="px-3 py-2 font-semibold">Rank</th>
                      <th className="px-3 py-2 font-semibold">Name</th>
                      <th className="px-3 py-2 font-semibold">Charged as</th>
                      <th className="px-3 py-2" />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {crew.map((m, i) => (
                      <tr key={m.id} className="transition-colors hover:bg-slate-50/70">
                        <td className="px-3 py-2 text-xs text-slate-400">{i + 1}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">
                          {m.rank || <span className="text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 font-medium text-[#28364b]">{m.name}</td>
                        <td className="px-3 py-2">
                          <span
                            className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${
                              m.category === "crew"
                                ? "bg-slate-100 text-slate-500"
                                : "bg-[#cebd88]/25 text-[#8a7a4a]"
                            }`}
                          >
                            {m.category_label}
                          </span>
                        </td>
                        {/* Both drawn in their own colour rather than waiting to
                            be hovered. Two destructive-ish actions a row apart
                            need telling apart at a glance, and signing somebody
                            off is not the same as deleting them — so one is
                            labelled in words and only the irreversible one is
                            red. */}
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <button
                              onClick={() => signOff(m)}
                              title="Sign off — they stop counting aboard, the name is kept"
                              className="inline-flex items-center gap-1 whitespace-nowrap rounded-lg border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 transition-colors hover:border-[#28364b] hover:bg-[#28364b] hover:text-white"
                            >
                              <UserMinus className="h-3.5 w-3.5" /> Sign off
                            </button>
                            <button
                              onClick={() => askRemove(m)}
                              title="Remove — for a name typed by mistake"
                              className="rounded-lg p-1.5 text-red-500 transition-colors hover:bg-red-50 hover:text-red-700"
                            >
                              <Trash2 className="h-3.5 w-3.5" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}
    </section>
  );
}

function OneName({ busy, onSave, onCancel }) {
  const [form, setForm] = useState({ name: "", rank: "", category: "crew" });

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        onSave({ name: form.name.trim(), rank: form.rank.trim() || null, category: form.category });
      }}
      className="mb-4 rounded-lg border border-slate-200 bg-slate-50/60 p-4"
    >
      <div className="mb-3 flex items-center justify-between">
        <p className="text-sm font-semibold text-[#28364b]">Sign somebody on</p>
        <button type="button" onClick={onCancel} className="rounded p-1 text-slate-400 hover:text-[#28364b]">
          <X className="h-4 w-4" />
        </button>
      </div>
      <div className="grid gap-3 sm:grid-cols-3">
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Name</span>
          <input
            value={form.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            required
            autoFocus
            className={`${inputCls} w-full`}
          />
        </label>
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">Rank</span>
          <input
            value={form.rank}
            onChange={(e) => setForm((f) => ({ ...f, rank: e.target.value }))}
            placeholder="Optional"
            className={`${inputCls} w-full`}
          />
        </label>
        <label className="block">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            Billed as
          </span>
          <Select
            value={form.category}
            onChange={(v) => setForm((f) => ({ ...f, category: v }))}
            options={[
              { value: "crew", label: "Crew" },
              { value: "passenger", label: "Passenger" },
              { value: "charterer", label: "Charterer's" },
            ]}
            className="w-full"
          />
        </label>
      </div>
      <div className="mt-3 flex justify-end gap-2">
        <button type="button" onClick={onCancel} className={btn.ghost}>
          Cancel
        </button>
        <button type="submit" disabled={!form.name.trim() || busy} className={btn.primary}>
          {busy ? <Spinner className="h-4 w-4" /> : <UserPlus className="h-4 w-4" />} Sign on
        </button>
      </div>
    </form>
  );
}

/**
 * A crew change is a dozen people on one day.
 *
 * One name at a time is how a roster ends up half-entered and then ignored, so
 * the whole list can be pasted in from whatever the agent sent.
 */
function PasteList({ busy, onSave, onCancel }) {
  const [names, setNames] = useState("");
  const [category, setCategory] = useState("crew");

  const lines = names.split(/\r\n|\r|\n/).filter((l) => l.trim() !== "").length;

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        onSave({ names, category });
      }}
      className="mb-4 rounded-lg border border-slate-200 bg-slate-50/60 p-4"
    >
      <div className="mb-3 flex items-center justify-between">
        <p className="text-sm font-semibold text-[#28364b]">Paste a crew list</p>
        <button type="button" onClick={onCancel} className="rounded p-1 text-slate-400 hover:text-[#28364b]">
          <X className="h-4 w-4" />
        </button>
      </div>

      <textarea
        value={names}
        onChange={(e) => setNames(e.target.value)}
        rows={8}
        autoFocus
        placeholder={"Master, Pedro Santos\nChief Cook, Juan Dela Cruz\nAble Seaman, R. Lim\nMessman"}
        className={`${inputCls} w-full font-mono text-xs`}
      />

      <p className="mt-2 text-[11px] text-slate-400">
        One per line. Put the rank first with a comma — <span className="font-mono">Chief Cook, Juan Dela Cruz</span> —
        or just the name. Anybody already aboard is skipped.
      </p>

      <div className="mt-3 flex flex-wrap items-end justify-between gap-3">
        <label className="block w-40">
          <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            Billed as
          </span>
          <Select
            value={category}
            onChange={setCategory}
            options={[
              { value: "crew", label: "Crew" },
              { value: "passenger", label: "Passenger" },
              { value: "charterer", label: "Charterer's" },
            ]}
            className="w-full"
          />
        </label>
        <div className="flex gap-2">
          <button type="button" onClick={onCancel} className={btn.ghost}>
            Cancel
          </button>
          <button type="submit" disabled={lines === 0 || busy} className={btn.primary}>
            {busy ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />}
            Sign on {lines || ""}
          </button>
        </div>
      </div>
    </form>
  );
}
