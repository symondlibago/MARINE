import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  AlertTriangle,
  Check,
  FileArchive,
  FolderDown,
  FolderOpen,
  HardDrive,
  RefreshCw,
  X,
} from "lucide-react";
import { backupAPI } from "@/pages/api";
import ListToolbar from "./ui/ListToolbar";
import { PageLoader } from "./ui/Loading";
import {
  chooseFolder,
  folderLabel,
  folderPermission,
  forgetFolder,
  isSupported,
  readIndex,
  regrantFolder,
  savedFolder,
} from "./ui/localBackup";
import { downloadBundleZip, flushIndex, mirrorDocument } from "./ui/mirror";

const TYPE_LABELS = {
  enquiry: "Enquiry",
  offer: "Offer",
  invoice: "Invoice",
  purchase_order: "Purchase Order",
  payroll_run: "Payroll month",
  payroll_employees: "Staff list",
};

/**
 * The local backup screen: which documents are on this machine, and which are
 * still missing from it.
 *
 * "Missing" is per machine on purpose. It is read from the backup folder's own
 * _index.csv, not from anything the server stores, so mirroring on the PC does
 * not make the laptop believe it already has a copy. Each machine keeps its
 * own complete set.
 */
export default function LocalBackup() {
  const [root, setRoot] = useState(null);
  const [permission, setPermission] = useState("prompt");
  const [held, setHeld] = useState(new Map());
  const [search, setSearch] = useState("");
  const [type, setType] = useState("");
  const [onlyPending, setOnlyPending] = useState(true);
  const [run, setRun] = useState(null); // {done, total, current, failed:[]}
  const [note, setNote] = useState(null);

  const supported = isSupported();

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["backup", "pending"],
    queryFn: async () => (await backupAPI.pending()).data,
  });

  const rows = data?.data ?? [];
  const columns = data?.meta?.index_columns ?? [];

  // Pick the remembered folder back up on arrival, and read what it holds.
  useEffect(() => {
    let cancelled = false;

    (async () => {
      const handle = await savedFolder();
      if (cancelled || !handle) return;

      const state = await folderPermission(handle);
      setRoot(handle);
      setPermission(state);

      if (state === "granted") {
        setHeld(await readIndex(handle));
      }
    })();

    return () => { cancelled = true; };
  }, []);

  const pick = async () => {
    try {
      const handle = await chooseFolder();
      setRoot(handle);
      setPermission("granted");
      setHeld(await readIndex(handle));
      setNote(null);
    } catch (e) {
      if (e?.name !== "AbortError") setNote({ bad: true, text: e?.message ?? "Could not open that folder." });
    }
  };

  const regrant = async () => {
    if (!root) return;

    if (await regrantFolder(root)) {
      setPermission("granted");
      setHeld(await readIndex(root));
    }
  };

  const forget = async () => {
    await forgetFolder();
    setRoot(null);
    setHeld(new Map());
    setPermission("prompt");
    setNote({ text: "This machine has forgotten the folder. Nothing in it was deleted." });
  };

  /**
   * Where this document stands on this machine.
   *
   * "stale" is the one that matters day to day: the folder is there, but the
   * document has been edited since — a mark-up changed, a line added, a vendor
   * quote filed — so what is on disk is no longer what is in the system.
   *
   * A row written before fingerprints existed has none recorded, so it counts
   * as stale and is refreshed once, which gives it one.
   */
  const stateOf = useMemo(() => (row) => {
    const on = held.get(row.folder);

    if (!on) return "missing";
    if (!on.fingerprint || on.fingerprint !== row.fingerprint) return "stale";

    return "current";
  }, [held]);

  const visible = useMemo(() => {
    const term = search.trim().toLowerCase();

    return rows.filter((row) => {
      if (type && row.type !== type) return false;
      if (onlyPending && stateOf(row) === "current") return false;
      if (!term) return true;

      return `${row.number} ${row.party}`.toLowerCase().includes(term);
    });
  }, [rows, search, type, onlyPending, stateOf]);

  const pending = rows.filter((r) => stateOf(r) !== "current");
  const staleCount = rows.filter((r) => stateOf(r) === "stale").length;

  /** Mirror a list of documents, writing _index.csv once at the end. */
  const mirrorMany = async (list) => {
    if (!root) return pick();

    if (permission !== "granted" && !(await regrantFolder(root))) return;

    const index = await readIndex(root);
    const failed = [];

    setNote(null);
    setRun({ done: 0, total: list.length, current: null, waiting: null, failed });

    for (const [i, row] of list.entries()) {
      setRun({ done: i, total: list.length, current: row.number, waiting: null, failed });

      try {
        const result = await mirrorDocument(root, row.type, row.id, {
          index,
          // The server asks us to slow down on a long run. Say so, rather than
          // sitting on a frozen-looking progress bar.
          onWait: (seconds) =>
            setRun({ done: i, total: list.length, current: row.number, waiting: seconds, failed }),
        });
        if (result.failed.length) {
          failed.push(`${row.number}: ${result.failed.length} file(s) unreadable`);
        }
      } catch (e) {
        failed.push(`${row.number}: ${e?.response?.data?.message ?? e?.message ?? "failed"}`);
      }

      // Written as we go, so stopping halfway still leaves a true index.
      if (i % 20 === 19) await flushIndex(root, columns, index);
    }

    await flushIndex(root, columns, index);

    setHeld(new Map(index));
    setRun(null);
    setNote({
      bad: failed.length > 0,
      text: failed.length
        ? `${list.length - failed.length} of ${list.length} mirrored. Problems: ${failed.slice(0, 3).join("; ")}${failed.length > 3 ? "…" : ""}`
        : `${list.length} document${list.length === 1 ? "" : "s"} mirrored.`,
    });
  };

  if (isLoading) return <PageLoader label="Loading documents…" />;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Local Backup</h1>
        <p className="mt-1 text-sm text-slate-500">
          Keeps a folder of PDFs, attachments and records on this machine. Nothing is sent anywhere, and
          nothing in the folder is ever deleted — files are only added or replaced.
        </p>
      </div>

      {/* ----------------------------------------------------- the folder */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        {!supported ? (
          <div className="flex items-start gap-3">
            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
            <div className="text-sm">
              <p className="font-medium text-[#28364b]">This browser cannot write to a folder</p>
              <p className="mt-1 text-slate-500">
                Writing folders directly needs Chrome or Edge. In this browser each document can still be
                downloaded as a ZIP, which you unzip into your backup folder yourself.
              </p>
            </div>
          </div>
        ) : !root ? (
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex items-start gap-3">
              <HardDrive className="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
              <div className="text-sm">
                <p className="font-medium text-[#28364b]">No backup folder chosen on this machine</p>
                <p className="mt-1 text-slate-500">
                  Pick the folder once — <span className="font-mono text-xs">C:\MATRIA BACKUP</span> — and this
                  browser will remember it. Each machine is set up separately.
                </p>
              </div>
            </div>
            <button
              onClick={pick}
              className="inline-flex items-center gap-2 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63]"
            >
              <FolderOpen className="h-4 w-4" /> Choose folder
            </button>
          </div>
        ) : permission !== "granted" ? (
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex items-start gap-3">
              <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
              <div className="text-sm">
                <p className="font-medium text-[#28364b]">Permission for “{folderLabel(root)}” has lapsed</p>
                <p className="mt-1 text-slate-500">
                  Browsers drop folder access when they restart. One click puts it back.
                </p>
              </div>
            </div>
            <button
              onClick={regrant}
              className="inline-flex items-center gap-2 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63]"
            >
              <RefreshCw className="h-4 w-4" /> Allow again
            </button>
          </div>
        ) : (
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex items-start gap-3">
              <Check className="mt-0.5 h-5 w-5 shrink-0 text-green-600" />
              <div className="text-sm">
                <p className="font-medium text-[#28364b]">
                  Backing up to “{folderLabel(root)}”
                </p>
                <p className="mt-1 text-slate-500">
                  {held.size} document{held.size === 1 ? "" : "s"} held here
                  {" · "}
                  {pending.length === 0
                    ? "all up to date"
                    : `${pending.length - staleCount} never backed up, ${staleCount} changed since`}
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <button
                onClick={() => mirrorMany(pending)}
                disabled={!!run || pending.length === 0}
                className="inline-flex items-center gap-2 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-50"
              >
                <FolderDown className="h-4 w-4" />
                {pending.length === 0 ? "Everything up to date" : `Back up ${pending.length} document${pending.length === 1 ? "" : "s"}`}
              </button>
              <button onClick={pick} title="Choose a different folder" className="rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                Change
              </button>
              <button onClick={forget} title="Forget this folder on this machine" className="rounded-lg border border-slate-200 px-2 py-2 text-slate-400 hover:bg-slate-50">
                <X className="h-4 w-4" />
              </button>
            </div>
          </div>
        )}

        {run && (
          <div className="mt-4 border-t border-slate-100 pt-4">
            <div className="flex items-center justify-between text-sm">
              <span className="text-slate-600">
                {run.waiting ? (
                  <span className="text-amber-700">
                    Server busy — waiting {run.waiting}s, then carrying on with{" "}
                    <span className="font-mono">{run.current}</span>
                  </span>
                ) : (
                  <>Mirroring {run.current ? <span className="font-mono">{run.current}</span> : "…"}</>
                )}
              </span>
              <span className="text-slate-400">{run.done} / {run.total}</span>
            </div>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
              <div
                className="h-full rounded-full bg-[#28364b] transition-[width]"
                style={{ width: `${run.total ? (run.done / run.total) * 100 : 0}%` }}
              />
            </div>
          </div>
        )}

        {note && (
          <p className={`mt-3 text-sm ${note.bad ? "text-amber-700" : "text-slate-500"}`}>{note.text}</p>
        )}
      </div>

      {/* ------------------------------------------------------ the list */}
      <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
        <ListToolbar
          search={search}
          onSearch={setSearch}
          placeholder="Search by number or name…"
          filters={[
            {
              value: type,
              onChange: setType,
              title: "Document type",
              options: [
                { value: "", label: "All types" },
                ...Object.entries(TYPE_LABELS).map(([value, label]) => ({ value, label })),
              ],
            },
          ]}
        >
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input
              type="checkbox"
              checked={onlyPending}
              onChange={(e) => setOnlyPending(e.target.checked)}
              className="h-4 w-4 rounded border-slate-300"
            />
            Only what needs backing up
          </label>
          <button onClick={() => refetch()} title="Refresh the list" className="rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
            <RefreshCw className="h-4 w-4" />
          </button>
        </ListToolbar>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-3 py-2">Type</th>
                <th className="px-3 py-2">Number</th>
                <th className="px-3 py-2">Date</th>
                <th className="px-3 py-2">Customer / Vendor</th>
                <th className="px-3 py-2">On this machine</th>
                <th className="px-3 py-2 text-right">Backup</th>
              </tr>
            </thead>
            <tbody>
              {visible.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-3 py-8 text-center text-slate-400">
                    {rows.length === 0
                      ? "No documents yet."
                      : onlyPending
                        ? "Everything up to date — this machine holds a current copy of every document."
                        : "Nothing matches that search."}
                  </td>
                </tr>
              )}

              {visible.map((row) => {
                const on = held.get(row.folder);
                const state = stateOf(row);

                return (
                  <tr key={`${row.type}-${row.id}`} className="border-b border-slate-100 last:border-0">
                    <td className="px-3 py-2 text-slate-500">{TYPE_LABELS[row.type] ?? row.type}</td>
                    <td className="px-3 py-2 font-mono text-xs">{row.number}</td>
                    <td className="px-3 py-2 text-slate-500">{row.date}</td>
                    <td className="max-w-xs truncate px-3 py-2" title={row.party}>{row.party}</td>
                    <td className="px-3 py-2">
                      {state === "current" ? (
                        <span className="inline-flex items-center gap-1 text-xs text-green-700">
                          <Check className="h-3.5 w-3.5" /> {on.backed_up_at || "yes"}
                        </span>
                      ) : state === "stale" ? (
                        <span
                          className="inline-flex items-center gap-1 text-xs text-amber-700"
                          title={`Last backed up ${on.backed_up_at || "earlier"} — it has been edited since`}
                        >
                          <AlertTriangle className="h-3.5 w-3.5" /> Changed since backup
                        </span>
                      ) : (
                        <span className="text-xs text-slate-400">Never</span>
                      )}
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex items-center justify-end gap-1">
                        {supported && (
                          <button
                            onClick={() => mirrorMany([row])}
                            disabled={!!run}
                            title={on ? "Write it again, over the top" : "Write this document's folder"}
                            className={`inline-flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs disabled:opacity-50 ${
                              state === "stale"
                                ? "border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100"
                                : "border-slate-200 hover:bg-slate-50"
                            }`}
                          >
                            <FolderDown className="h-3.5 w-3.5" />
                            {state === "stale" ? "Update" : state === "current" ? "Again" : "Mirror"}
                          </button>
                        )}
                        <button
                          onClick={() => downloadBundleZip(row.type, row.id, row.number)}
                          title="Download as a ZIP"
                          className="inline-flex items-center rounded-lg border border-slate-200 px-2 py-1.5 text-slate-500 hover:bg-slate-50"
                        >
                          <FileArchive className="h-3.5 w-3.5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
