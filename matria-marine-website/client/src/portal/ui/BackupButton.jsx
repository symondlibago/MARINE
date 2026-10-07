import { useState } from "react";
import { FolderDown, FileArchive, Check } from "lucide-react";
import { toast } from "sonner";
import {
  chooseFolder,
  folderPermission,
  isSupported,
  regrantFolder,
  savedFolder,
} from "./localBackup";
import { downloadBundleZip, mirrorDocument } from "./mirror";

/**
 * "Mirror" — writes this document's folder into the local backup.
 *
 * On every document whatever its status: Dru decides when a job is finished
 * and worth keeping, not the system. Pressing it twice is harmless — the
 * folder is simply written again over the top, and nothing is ever deleted.
 *
 * The first press asks where the backup folder is. The browser remembers it
 * from then on, per machine, so the PC and the laptop each keep their own set.
 *
 * Firefox and Safari cannot write to a folder at all, so there the button
 * offers the ZIP the server builds instead.
 */
export default function BackupButton({ type, id, number, className = "" }) {
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [progress, setProgress] = useState(null);

  const supported = isSupported();

  const finish = () => {
    setBusy(false);
    setProgress(null);
    setDone(true);
    setTimeout(() => setDone(false), 4000);
  };

  const mirror = async () => {
    setBusy(true);

    try {
      let root = await savedFolder();

      if (root && (await folderPermission(root)) !== "granted") {
        // The grant lapses when the browser restarts — one click renews it.
        if (!(await regrantFolder(root))) root = null;
      }

      if (!root) {
        toast.info("Choose your backup folder — this browser will remember it.");
        root = await chooseFolder();
      }

      const result = await mirrorDocument(root, type, id, {
        onStep: (step, total) => setProgress(`${step}/${total}`),
        onWait: (seconds) => toast.info(`Server busy — retrying in ${seconds}s.`),
      });

      if (result.failed.length) {
        toast.warning(`Saved, but ${result.failed.length} file(s) could not be read from storage.`, {
          description: result.folder,
        });
      } else {
        toast.success("Mirrored to your backup folder", { description: result.folder });
      }

      finish();
    } catch (e) {
      setBusy(false);
      setProgress(null);

      // Cancelling the folder picker is not an error worth shouting about.
      if (e?.name === "AbortError") return;

      toast.error(e?.response?.data?.message ?? e?.message ?? "Could not write the backup.");
    }
  };

  const zip = async () => {
    setBusy(true);

    try {
      await downloadBundleZip(type, id, number);
      toast.success("Backup ZIP downloaded — unzip it into your backup folder.");
      finish();
    } catch (e) {
      setBusy(false);
      toast.error(e?.response?.data?.message ?? e?.message ?? "Could not build the ZIP.");
    }
  };

  return (
    <div className={`flex items-center gap-1 ${className}`}>
      <button
        type="button"
        onClick={supported ? mirror : zip}
        disabled={busy}
        title={
          supported
            ? "Write this document's folder into your local backup"
            : "This browser cannot write to a folder — download a ZIP instead"
        }
        className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-70"
      >
        {done ? <Check className="h-4 w-4 text-green-600" /> : <FolderDown className="h-4 w-4" />}
        {busy ? `Mirroring${progress ? ` ${progress}` : "…"}` : supported ? "Mirror" : "Backup ZIP"}
      </button>

      {supported && (
        <button
          type="button"
          onClick={zip}
          disabled={busy}
          title="Download this document's folder as a ZIP instead"
          className="inline-flex items-center rounded-lg border border-slate-200 px-2 py-2 text-slate-500 hover:bg-slate-50 disabled:opacity-70"
        >
          <FileArchive className="h-4 w-4" />
        </button>
      )}
    </div>
  );
}
