/**
 * Download a table as CSV, from data already in the browser.
 *
 * No round trip: every accounting screen already holds its rows, so the export
 * is the same figures the user is looking at rather than a second query that
 * could disagree with them.
 *
 * Values are escaped for quotes, commas and newlines — a customer called
 * "Smith, Ltd" must not silently become two columns. The BOM is there so Excel
 * opens accented and non-Latin names correctly instead of mangling them.
 */
export function downloadCsv(filename, headers, rows) {
  const esc = (v) => {
    const s = v == null ? "" : String(v);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };

  const csv = [headers, ...rows].map((r) => r.map(esc).join(",")).join("\n");
  const url = URL.createObjectURL(new Blob(["﻿" + csv], { type: "text/csv;charset=utf-8" }));
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

/** "sales-invoices-2026-01-01-to-2026-12-31.csv" */
export function rangedFilename(base, range) {
  const part = [range?.from, range?.to].filter(Boolean).join("-to-");
  return `${base}${part ? `-${part}` : ""}.csv`;
}

export default downloadCsv;
