import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { KeyRound, Pencil, Plus, Trash2, UserRound, X } from "lucide-react";
import { toast } from "sonner";
import { inventoryAPI } from "./api";
import { btn, inputCls } from "./lib";
import Select from "@/portal/ui/Select";
import { useConfirm } from "@/portal/ui/confirm";
import { Spinner } from "@/portal/ui/Loading";

const RANKS = [
  { value: "vessel_master", label: "Master" },
  { value: "vessel_cook", label: "Cook" },
];

/**
 * The logins that sail on one vessel — normally two, the master and the cook.
 *
 * Kept apart from the portal's Manage Staff screen, which is about the office.
 * These accounts open one ship's stores and nothing else, so they are added
 * next to that ship's settings by whoever added the ship, rather than from a
 * staff list ashore.
 *
 * Rendered only for the office. A master who could add accounts could add
 * himself a second one with more authority.
 */
export default function VesselAccounts({ vessel }) {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const [editing, setEditing] = useState(null); // an account, {} for a new one

  const { data: accounts = [], isLoading } = useQuery({
    queryKey: ["inv", "accounts", vessel.id],
    queryFn: async () => (await inventoryAPI.accounts(vessel.id)).data.data.accounts,
  });

  // Every write answers with the whole list, so one place puts it back.
  const settle = (message) => (res) => {
    qc.setQueryData(["inv", "accounts", vessel.id], res.data.data.accounts);
    toast.success(res.data.message || message);
    setEditing(null);
  };

  const fail = (e) => toast.error(e?.response?.data?.message || "Could not save the account.");

  const create = useMutation({
    mutationFn: (payload) => inventoryAPI.createAccount(vessel.id, payload),
    onSuccess: settle("Account created."),
    onError: fail,
  });

  const update = useMutation({
    mutationFn: ({ id, payload }) => inventoryAPI.updateAccount(vessel.id, id, payload),
    onSuccess: settle("Account updated."),
    onError: fail,
  });

  const remove = useMutation({
    mutationFn: (id) => inventoryAPI.removeAccount(vessel.id, id),
    onSuccess: settle("Account removed."),
    onError: fail,
  });

  const askToRemove = async (account) => {
    const ok = await confirm({
      title: `Remove ${account.name}?`,
      message: `Their login is deleted and they are signed out. ${vessel.name}'s records are not touched.`,
      confirmText: "Remove account",
      tone: "danger",
    });
    if (ok) remove.mutate(account.id);
  };

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-sm font-bold text-[#28364b]">Crew accounts</h2>
          <p className="mt-0.5 text-xs text-slate-500">
            Who can sign in for {vessel.name}. The cook counts, withdraws, receives and asks for the next supply; the
            master approves it and sets how she is provisioned. Neither of them sees another vessel or the procurement
            portal.
          </p>
        </div>
        {!editing && (
          <button type="button" onClick={() => setEditing({})} className={btn.ghost}>
            <Plus className="h-4 w-4" /> Add account
          </button>
        )}
      </div>

      {editing ? (
        <AccountForm
          account={editing}
          vessel={vessel}
          busy={create.isLoading || update.isLoading}
          onCancel={() => setEditing(null)}
          onSave={(payload) =>
            editing.id ? update.mutate({ id: editing.id, payload }) : create.mutate(payload)
          }
        />
      ) : isLoading ? (
        <div className="flex justify-center py-6">
          <Spinner className="h-5 w-5" />
        </div>
      ) : accounts.length === 0 ? (
        <p className="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-400">
          No one can sign in for {vessel.name} yet. Add her master and her cook.
        </p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {accounts.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-3 py-3">
              <div
                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
                  a.role === "vessel_master" ? "bg-[#28364b] text-white" : "bg-slate-100 text-slate-500"
                }`}
              >
                <UserRound className="h-4 w-4" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 truncate text-sm font-semibold text-[#28364b]">
                  {a.name}
                  <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">
                    {a.rank}
                  </span>
                  {!a.is_active && (
                    <span className="rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-red-600">
                      Signed off
                    </span>
                  )}
                </p>
                <p className="truncate text-xs text-slate-400">
                  signs in as <span className="font-medium text-slate-500">{a.username}</span>
                  {a.email ? ` · ${a.email}` : ""}
                </p>
              </div>
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  onClick={() => setEditing(a)}
                  title="Edit, or set a new password"
                  className="rounded-lg p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                >
                  <Pencil className="h-4 w-4" />
                </button>
                <button
                  type="button"
                  onClick={() => askToRemove(a)}
                  title="Remove account"
                  className="rounded-lg p-2 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function AccountForm({ account, vessel, busy, onSave, onCancel }) {
  const isNew = !account.id;

  const [form, setForm] = useState({
    name: account.name || "",
    username: account.username || "",
    email: account.email || "",
    phone: account.phone || "",
    role: account.role || "vessel_cook",
    password: "",
    is_active: account.is_active ?? true,
  });

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = (e) => {
    e.preventDefault();

    const payload = {
      name: form.name.trim(),
      username: form.username.trim(),
      email: form.email.trim(),
      phone: form.phone.trim() || null,
      role: form.role,
      is_active: form.is_active,
    };

    // Blank means "leave it alone" on an edit, so it is only sent when typed.
    if (form.password) payload.password = form.password;

    onSave(payload);
  };

  return (
    <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-slate-50/60 p-4">
      <div className="mb-4 flex items-center justify-between">
        <p className="text-sm font-semibold text-[#28364b]">
          {isNew ? `New account for ${vessel.name}` : `Editing ${account.name}`}
        </p>
        <button type="button" onClick={onCancel} className="rounded p-1 text-slate-400 hover:text-[#28364b]">
          <X className="h-4 w-4" />
        </button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <F label="Rank" hint="What they may do on board">
          <Select
            value={form.role}
            onChange={(v) => setForm((f) => ({ ...f, role: v }))}
            options={RANKS}
            className="w-full"
          />
        </F>
        <F label="Name">
          <input value={form.name} onChange={set("name")} required className={`${inputCls} w-full`} />
        </F>
        <F label="Username" hint="How they sign in — e.g. varun-cook">
          <input
            value={form.username}
            onChange={set("username")}
            required
            autoCapitalize="none"
            autoCorrect="off"
            className={`${inputCls} w-full`}
          />
        </F>
        <F label="Email" hint="One ship's address can serve both accounts">
          <input type="email" value={form.email} onChange={set("email")} required className={`${inputCls} w-full`} />
        </F>
        <F label="Phone" hint="Optional">
          <input value={form.phone} onChange={set("phone")} className={`${inputCls} w-full`} />
        </F>
        <F
          label={isNew ? "Password" : "New password"}
          hint={isNew ? "At least 8 characters" : "Leave blank to keep the current one"}
        >
          <div className="relative">
            <KeyRound className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-300" />
            <input
              type="text"
              value={form.password}
              onChange={set("password")}
              required={isNew}
              autoComplete="new-password"
              placeholder={isNew ? "" : "unchanged"}
              className={`${inputCls} w-full pl-9`}
            />
          </div>
        </F>
      </div>

      {!isNew && (
        <label className="mt-4 flex items-start gap-2 text-xs text-slate-500">
          <input
            type="checkbox"
            checked={!form.is_active}
            onChange={(e) => setForm((f) => ({ ...f, is_active: !e.target.checked }))}
            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-[#28364b]"
          />
          {/* Not a delete: the account stays, and so does everything it
              recorded. Signing somebody off also revokes their session. */}
          <span>Signed off — cannot sign in until this is cleared. Their records are kept.</span>
        </label>
      )}

      <div className="mt-4 flex justify-end gap-2">
        <button type="button" onClick={onCancel} className={btn.ghost}>
          Cancel
        </button>
        <button type="submit" disabled={busy} className={btn.primary}>
          {busy && <Spinner className="h-4 w-4" />} {isNew ? "Create account" : "Save account"}
        </button>
      </div>

      {/* The password is shown as typed on purpose: the office reads it out to
          the ship, and a masked box they cannot check is how the wrong one
          gets handed over. */}
      <p className="mt-3 text-[11px] text-slate-400">
        Give them the username and password yourself — nothing is emailed from here.
      </p>
    </form>
  );
}

function F({ label, hint, children }) {
  return (
    <label className="block">
      <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-[11px] text-slate-400">{hint}</span>}
    </label>
  );
}
