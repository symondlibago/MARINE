/**
 * Mirroring one document into the local backup folder.
 *
 * The one definition of the whole operation: ask the server what the folder
 * should contain, write it, then record it in _index.csv. The per-document
 * button and the Mirror-all screen both come through here, so the two cannot
 * drift into writing different folders for the same document.
 */

import { backupAPI } from "@/pages/api";
import { downloadBlob, readIndex, writeBundle, writeIndex } from "./localBackup";

/**
 * Run a request, waiting out the rate limiter rather than giving up.
 *
 * Mirroring is bursty — one document is a manifest plus every file on it — so
 * a long run can still meet the server's ceiling however high it is set. A 429
 * is the server saying "not yet", not "no", and abandoning the document over
 * it would leave a half-written folder that the screen then calls backed up.
 *
 * The server's Retry-After is used when the browser can see it; cross-origin
 * it usually cannot, so the fallback climbs to a minute, which is the window
 * Laravel's limiter works in.
 */
async function withRetry(call, onWait, attempts = 5) {
  for (let attempt = 0; ; attempt++) {
    try {
      return await call();
    } catch (e) {
      if (e?.response?.status !== 429 || attempt >= attempts) throw e;

      const headerSeconds = Number(e.response.headers?.["retry-after"]);
      const seconds = headerSeconds || Math.min(60, 5 * 2 ** attempt);

      onWait?.(seconds);
      await new Promise((resolve) => setTimeout(resolve, seconds * 1000));
    }
  }
}

/** "2026-10-05 14:32" — what goes in the backed_up_at column. */
function stamp() {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, "0");

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

/**
 * Write one document's folder, and record it.
 *
 * @param root  the backup folder handle
 * @param type  "enquiry" | "offer" | "invoice" | "purchase_order"
 * @param id    the document's id
 * @param opts.onStep  (done, total) => void
 * @param opts.onWait  (seconds) => void, when the server asks us to slow down
 * @param opts.index   a Map from readIndex() to accumulate into. Pass one when
 *                     mirroring a batch and flush it once at the end with
 *                     flushIndex() — rewriting the whole CSV per document
 *                     turns a 300-document run into 300 extra file writes.
 * @returns {Promise<{folder: string, number: string, written: number, failed: Array}>}
 */
export async function mirrorDocument(root, type, id, { onStep, onWait, index } = {}) {
  const response = await withRetry(() => backupAPI.manifest(type, id), onWait);
  const manifest = response.data.data;
  const columns = response.data.meta.index_columns;

  const result = await writeBundle(
    root,
    manifest,
    async (fileIndex) => (await withRetry(() => backupAPI.file(type, id, fileIndex), onWait)).data,
    onStep,
  );

  const row = { ...manifest.index_row, backed_up_at: stamp() };

  if (index) {
    index.set(manifest.folder, row);
  } else {
    const rows = await readIndex(root);
    rows.set(manifest.folder, row);
    await writeIndex(root, columns, rows);
  }

  return { ...result, number: manifest.number, columns };
}

/** Write an accumulated index out, after a batch. */
export async function flushIndex(root, columns, index) {
  await writeIndex(root, columns, index);
}

/**
 * The fallback: let the server build a ZIP of the same folder and hand it to
 * the browser. For Firefox and Safari, which cannot write to a folder, and for
 * taking one document to a machine that has no backup folder set up.
 */
export async function downloadBundleZip(type, id, filename) {
  const response = await backupAPI.zip(type, id);

  downloadBlob(response.data, `${filename || `${type}-${id}`}.zip`);
}
