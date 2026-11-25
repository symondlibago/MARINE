/**
 * Writing the local backup folder — the only place in the app that touches a
 * disk.
 *
 * Chrome and Edge let a page write into one folder the person picked for it,
 * through the File System Access API. Dru chooses C:\MATRIA BACKUP once; the
 * browser remembers it, and from then on pressing Mirror writes the document's
 * folder straight in. No ZIP to unpack, no Downloads to tidy up.
 *
 * Two rules this module keeps:
 *
 *   1. IT NEVER DELETES ANYTHING. Files are created and overwritten by name,
 *      and nothing else in that folder is read, moved or removed. The backup
 *      cannot destroy the backup.
 *   2. The person is in charge of when. There is no polling, no background
 *      sync — a folder is written because somebody pressed a button.
 *
 * Firefox and Safari have no such API, so callers fall back to the ZIP the
 * server builds (see backupAPI.zip).
 */

const DB_NAME = "matria_backup";
const STORE = "handles";

/**
 * The two folders this machine can hold, each remembered under its own key.
 *
 * Everything the office works with goes to MAIN. Payroll goes to PAYROLL,
 * which Dru points at a different drive, because that folder ends up holding
 * every salary in the company and the general backup is the one that gets
 * copied onto sticks and sync drives.
 *
 * PAYROLL is optional. Where it has not been set, payroll falls back to MAIN
 * and nothing changes from how it worked before — the split is something you
 * turn on, not something that quietly starts failing because a second folder
 * was never chosen.
 */
export const MAIN = "root";
export const PAYROLL = "payroll";

/**
 * The types that go to the payroll folder.
 *
 * Mirrors BackupBundle::PRIVATE_TYPES on the server, which is the definition —
 * the pending list sends `private` on every row and the screen reads that.
 * This copy exists for the per-document Mirror button, which is handed a type
 * and nothing else.
 */
const PRIVATE_TYPES = ["payroll_run", "payroll_employees"];

/** Which of the two folders a document type belongs in. */
export function scopeFor(type) {
  return PRIVATE_TYPES.includes(type) ? PAYROLL : MAIN;
}

/** Does this browser let a page write to a folder at all? */
export function isSupported() {
  return typeof window !== "undefined" && typeof window.showDirectoryPicker === "function";
}

// ---------------------------------------------------------------- the handle
//
// A directory handle survives being put in IndexedDB, which is how the folder
// is still known after the browser restarts. localStorage cannot hold one —
// it stringifies, and a handle is not a string.

function openDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function tx(db, mode, fn) {
  return new Promise((resolve, reject) => {
    const store = db.transaction(STORE, mode).objectStore(STORE);
    const request = fn(store);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

/** The folder chosen on this machine for `scope`, or null if there isn't one. */
export async function savedFolder(scope = MAIN) {
  if (!isSupported()) return null;

  try {
    const db = await openDb();
    const handle = await tx(db, "readonly", (s) => s.get(scope));
    db.close();
    return handle ?? null;
  } catch {
    // Private windows and blocked site data both land here. Treat it as "no
    // folder chosen" rather than breaking the screen.
    return null;
  }
}

/** Ask for a backup folder and remember it under `scope`. Needs a click behind it. */
export async function chooseFolder(scope = MAIN) {
  const handle = await window.showDirectoryPicker({
    // A separate id per scope, so Chrome reopens the picker where that folder
    // was last chosen — C:\ for the general backup, D:\ for payroll — instead
    // of sending you back to the other drive every time.
    id: scope === PAYROLL ? "matria-backup-payroll" : "matria-backup",
    mode: "readwrite",
    startIn: "documents",
  });

  // The picker itself asks for write access when it is opened in readwrite
  // mode, so this only speaks up if it somehow came back without it. Asking
  // again unconditionally risks a second "Allow this site to edit files?" for
  // a permission Chrome has already given.
  if ((await handle.queryPermission({ mode: "readwrite" })) !== "granted") {
    const granted = await handle.requestPermission({ mode: "readwrite" });

    if (granted !== "granted") {
      throw new Error("Matria needs permission to write to that folder.");
    }
  }

  try {
    const db = await openDb();
    await tx(db, "readwrite", (s) => s.put(handle, scope));
    db.close();
  } catch {
    // Not fatal — it just means the folder has to be picked again next time.
  }

  return handle;
}

/** Forget a folder on this machine (the files themselves are left alone). */
export async function forgetFolder(scope = MAIN) {
  try {
    const db = await openDb();
    await tx(db, "readwrite", (s) => s.delete(scope));
    db.close();
  } catch {
    /* nothing to forget */
  }
}

/**
 * Is the remembered folder still writable?
 *
 * Permission lapses when the browser restarts, so this returns "prompt" to
 * mean "still there, needs one click to re-allow".
 *
 * @returns {Promise<"granted"|"prompt"|"denied">}
 */
export async function folderPermission(handle) {
  if (!handle) return "denied";

  try {
    return await handle.queryPermission({ mode: "readwrite" });
  } catch {
    return "denied";
  }
}

/** Re-ask for a folder already chosen. Needs a click behind it. */
export async function regrantFolder(handle) {
  const granted = await handle.requestPermission({ mode: "readwrite" });
  return granted === "granted";
}

// ----------------------------------------------------------------- writing

/** Walk (and create) a path like "ENQUIRIES/2026/MMS-QTN-…", returning the last folder. */
async function folderAt(root, path) {
  let handle = root;

  for (const segment of path.split("/").filter(Boolean)) {
    handle = await handle.getDirectoryHandle(segment, { create: true });
  }

  return handle;
}

/** Write one file, overwriting a previous copy of the same name. */
async function writeFile(folder, name, contents) {
  const file = await folder.getFileHandle(name, { create: true });
  const stream = await file.createWritable();

  try {
    await stream.write(contents);
  } finally {
    // Closing is what commits it; skipping this on an error would leave a
    // zero-byte file sitting where a good one used to be.
    await stream.close();
  }
}

/**
 * Write one document's folder.
 *
 * @param root     the backup folder handle
 * @param manifest what the server says belongs in it
 * @param getFile  (index) => Blob, usually backupAPI.file bound to the document
 * @param onStep   optional progress callback, (done, total) => void
 * @returns {Promise<{folder: string, written: number, failed: Array}>}
 */
export async function writeBundle(root, manifest, getFile, onStep) {
  const folder = await folderAt(root, manifest.folder);
  const total = Object.keys(manifest.inline).length + manifest.binaries.length;
  const failed = [];
  let written = 0;

  const step = () => onStep?.(++written, total);

  // The text files first, so even a run that dies halfway leaves a folder that
  // says what it was meant to hold.
  for (const [name, contents] of Object.entries(manifest.inline)) {
    await writeFile(folder, name, new Blob([contents], { type: "text/plain" }));
    step();
  }

  for (const file of manifest.binaries) {
    // A name may carry its own subfolder, e.g. "vendor-quotes/Acme - quote.pdf".
    const parts = file.name.split("/");
    const name = parts.pop();
    const target = parts.length ? await folderAt(folder, parts.join("/")) : folder;

    try {
      await writeFile(target, name, await getFile(file.index));
    } catch (e) {
      // One attachment missing from storage must not cost the whole document.
      failed.push({ name: file.name, reason: e?.message ?? "could not be read" });
    }

    step();
  }

  return { folder: manifest.folder, written, failed };
}

// ---------------------------------------------------------------- the index
//
// _index.csv at the root lists every document this machine holds. It is what
// makes three hundred folders searchable — it opens in Excel — and it is also
// how the pending screen knows which documents are still missing here, rather
// than on somebody else's PC.

const INDEX_FILE = "_index.csv";

function csvEscape(value) {
  const text = value == null ? "" : String(value);
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/** Split one CSV line, honouring quotes. */
function splitCsvLine(line) {
  const out = [];
  let field = "";
  let quoted = false;

  for (let i = 0; i < line.length; i++) {
    const char = line[i];

    if (quoted) {
      if (char === '"' && line[i + 1] === '"') { field += '"'; i++; }
      else if (char === '"') quoted = false;
      else field += char;
    } else if (char === '"') quoted = true;
    else if (char === ",") { out.push(field); field = ""; }
    else field += char;
  }

  out.push(field);
  return out;
}

/**
 * The folders this machine already holds, as a Set of folder paths.
 *
 * Read from _index.csv rather than by listing the disk: listing would call a
 * folder "backed up" the moment it exists, even if the write died before the
 * files landed.
 */
export async function readIndex(root) {
  try {
    const handle = await root.getFileHandle(INDEX_FILE);
    const text = await (await handle.getFile()).text();
    const lines = text.replace(/^\uFEFF/, "").split(/\r?\n/).filter(Boolean);

    if (lines.length < 2) return new Map();

    const headers = splitCsvLine(lines[0]);
    const rows = new Map();

    for (const line of lines.slice(1)) {
      const values = splitCsvLine(line);
      const row = Object.fromEntries(headers.map((h, i) => [h, values[i] ?? ""]));
      if (row.folder) rows.set(row.folder, row);
    }

    return rows;
  } catch {
    // No index yet — nothing has been backed up here.
    return new Map();
  }
}

/**
 * Add or update rows in _index.csv.
 *
 * The whole file is rewritten from the merged set, so a row is never
 * duplicated when a document is mirrored a second time.
 */
export async function writeIndex(root, columns, rows) {
  const header = columns.map(csvEscape).join(",");
  const body = [...rows.values()]
    .sort((a, b) => String(b.date ?? "").localeCompare(String(a.date ?? "")))
    .map((row) => columns.map((c) => csvEscape(row[c])).join(","))
    .join("\r\n");

  // A BOM, or Excel on Windows reads the accents as the local codepage.
  await writeFile(root, INDEX_FILE, new Blob(["\uFEFF" + header + "\r\n" + body + "\r\n"], { type: "text/csv" }));
}

/** Human name of the chosen folder, for showing on screen. */
export function folderLabel(handle) {
  return handle?.name ?? null;
}

/** Hand the browser a blob to save, for the ZIP fallback. */
export function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();

  // Revoked on a delay: revoking it immediately cancels the download in some
  // builds of Chrome.
  setTimeout(() => URL.revokeObjectURL(url), 10_000);
}
