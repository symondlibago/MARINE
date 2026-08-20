import { useRef, useState } from "react";
import { UploadCloud, Loader2, Paperclip, X, ClipboardCheck, Lock } from "lucide-react";
import { deliveryOrdersAPI } from "../pages/api";

/**
 * Proof of delivery — the delivery order signed and stamped by the vessel.
 *
 * Filed against the DELIVERY ORDER, never the invoice, so one signed copy is
 * uploaded once and shows on both screens. Internal evidence only: nothing here
 * is ever attached to a customer email or printed on a customer document.
 *
 * Used by DeliveryOrderPage and by InvoicePage (through the invoice's linked
 * delivery order), which is why it lives in its own file.
 */

const KINDS = [
  { value: "signed_do", label: "Signed delivery order" },
  { value: "packing_list", label: "Packing list" },
  { value: "photo", label: "Photo" },
  { value: "other", label: "Other" },
];

const kindLabel = (v) => KINDS.find((k) => k.value === v)?.label ?? "Other";

const prettySize = (bytes) => {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
};

const dateOf = (d) =>
  d ? new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "";

export default function ProofOfDelivery({ deliveryOrderId, doNumber, attachments = [], onChange, hint }) {
  const inputRef = useRef(null);
  const [dragOver, setDragOver] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState("");
  const [kind, setKind] = useState("signed_do");

  const files = attachments || [];

  const upload = async (fileList) => {
    const chosen = Array.from(fileList || []);
    if (!chosen.length || !deliveryOrderId) return;

    setError("");
    setUploading(true);
    try {
      const fd = new FormData();
      chosen.forEach((f) => fd.append("files[]", f));
      fd.append("kind", kind);
      const res = await deliveryOrdersAPI.uploadFiles(deliveryOrderId, fd);
      onChange?.(res.data.data);
    } catch (e) {
      setError(e?.response?.data?.message || "Could not upload that file.");
    } finally {
      setUploading(false);
      if (inputRef.current) inputRef.current.value = "";
    }
  };

  // R2 is private — ask for a short-lived signed URL, then open it.
  const open = async (id) => {
    try {
      const res = await deliveryOrdersAPI.fileUrl(deliveryOrderId, id);
      window.open(res.data.data.url, "_blank", "noopener");
    } catch {
      setError("Could not open that file.");
    }
  };

  const remove = async (id) => {
    try {
      const res = await deliveryOrdersAPI.removeFile(deliveryOrderId, id);
      onChange?.(res.data.data);
    } catch {
      setError("Could not remove that file.");
    }
  };

  if (!deliveryOrderId) {
    return (
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <Header />
        <p className="text-xs text-slate-400">
          This invoice has no delivery order linked to it, so there is nowhere to file the signed copy yet.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5">
      <Header doNumber={doNumber} />

      <p className="mb-3 text-xs text-slate-400">
        {hint || "Attach the delivery order signed and stamped by the vessel, as proof the goods were received."}
      </p>

      <div className="mb-2 flex items-center gap-2">
        <label className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Filing as</label>
        <select
          value={kind}
          onChange={(e) => setKind(e.target.value)}
          className="rounded-lg border border-slate-200 px-2 py-1 text-xs text-[#28364b] outline-none focus:border-[#28364b]"
        >
          {KINDS.map((k) => (
            <option key={k.value} value={k.value}>
              {k.label}
            </option>
          ))}
        </select>
      </div>

      <div
        onClick={() => inputRef.current?.click()}
        onDragOver={(e) => {
          e.preventDefault();
          setDragOver(true);
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragOver(false);
          upload(e.dataTransfer.files);
        }}
        className={`flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-4 py-6 text-center transition-colors ${
          dragOver ? "border-[#28364b] bg-slate-50" : "border-slate-300 hover:bg-slate-50"
        }`}
      >
        <input
          ref={inputRef}
          type="file"
          multiple
          className="hidden"
          accept=".pdf,.jpg,.jpeg,.png,.webp,.xls,.xlsx,.doc,.docx"
          onChange={(e) => upload(e.target.files)}
        />
        {uploading ? (
          <span className="flex items-center gap-2 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" /> Uploading…
          </span>
        ) : (
          <>
            <UploadCloud className="h-6 w-6 text-slate-400" />
            <span className="text-sm font-medium text-[#28364b]">
              Drag &amp; drop the signed copy, or click to browse
            </span>
            <span className="text-xs text-slate-400">PDF, image, Word, Excel (max 10 MB each)</span>
          </>
        )}
      </div>

      {error && <p className="mt-2 text-xs text-red-600">{error}</p>}

      {files.length > 0 && (
        <ul className="mt-3 space-y-1.5">
          {files.map((f) => (
            <li
              key={f.id}
              className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
            >
              <button
                type="button"
                onClick={() => open(f.id)}
                className="flex min-w-0 items-center gap-2 text-[#28364b] hover:underline"
                title="Open file"
              >
                <Paperclip className="h-4 w-4 shrink-0 text-slate-400" />
                <span className="truncate">{f.original_name}</span>
              </button>
              <div className="flex shrink-0 items-center gap-2">
                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                  {kindLabel(f.kind)}
                </span>
                <span className="text-xs text-slate-400">{prettySize(f.size)}</span>
                <span className="hidden text-xs text-slate-400 sm:inline">{dateOf(f.created_at)}</span>
                <button
                  type="button"
                  onClick={() => remove(f.id)}
                  className="text-slate-400 transition-colors hover:text-red-600"
                  title="Remove"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <p className="mt-3 flex items-center gap-1.5 text-[11px] text-slate-400">
        <Lock className="h-3 w-3 shrink-0" />
        Internal only — these files are never sent to the customer.
      </p>
    </div>
  );
}

function Header({ doNumber }) {
  return (
    <div className="mb-2 flex flex-wrap items-center gap-1.5">
      <ClipboardCheck className="h-4 w-4 text-[#28364b]" />
      <h2 className="text-xs font-bold uppercase tracking-wider text-slate-400">Proof of delivery</h2>
      {doNumber && <span className="text-xs font-medium text-slate-400">· {doNumber}</span>}
    </div>
  );
}
