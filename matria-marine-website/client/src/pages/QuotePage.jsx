import { useState, useEffect, useRef } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { Anchor, Loader2, CheckCircle, UploadCloud, Paperclip, X } from "lucide-react";
import { quoteAPI } from "@/pages/api";
import Select from "@/portal/ui/Select";

const prettySize = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(b / 1024)) + " KB");

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

// Defined at module scope so it keeps a stable identity across renders
// (defining it inside QuotePage would remount the inputs on every keystroke).
function Shell({ children }) {
  return (
    <div className="min-h-screen bg-slate-50 py-10">
      <div className="mx-auto max-w-3xl px-4">
        <div className="mb-6 flex items-center gap-3">
          <div className="rounded-full bg-[#28364b] p-2 text-white"><Anchor className="h-5 w-5" /></div>
          <div>
            <h1 className="text-xl font-bold text-[#28364b]">Matria Marine — Vendor Quotation</h1>
            <p className="text-sm text-slate-500">Submit your prices for the requested items. No login required.</p>
          </div>
        </div>
        {children}
      </div>
    </div>
  );
}

/**
 * Public vendor quotation page (magic link, NO login). The {token} is validated
 * server-side; the vendor sees only their items for this enquiry.
 */
export default function QuotePage({ token }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ["quote", token],
    queryFn: async () => (await quoteAPI.get(token)).data.data,
    enabled: !!token,
    retry: false,
  });

  const [currency, setCurrency] = useState("USD");
  const [quotationNumber, setQuotationNumber] = useState("");
  const [lines, setLines] = useState({}); // rfq_item_id -> { unit_cost, remarks }
  const [formError, setFormError] = useState("");
  const [done, setDone] = useState(false);
  const [files, setFiles] = useState([]);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState("");
  const [dragOver, setDragOver] = useState(false);
  const fileInputRef = useRef(null);

  useEffect(() => {
    if (!data) return;
    setCurrency(data.quote?.currency || data.vendor?.currency || "USD");
    setQuotationNumber(data.quote?.quotation_number || "");
    const init = {};
    (data.quote?.items || []).forEach((qi) => {
      init[qi.rfq_item_id] = { unit_cost: qi.unit_cost, remarks: qi.remarks || "" };
    });
    setLines(init);
    setFiles(data.attachments || []);
  }, [data]);

  const uploadFiles = async (fileList) => {
    if (!fileList || fileList.length === 0) return;
    setUploadError("");
    const fd = new FormData();
    Array.from(fileList).forEach((f) => fd.append("files[]", f));
    setUploading(true);
    try {
      const res = await quoteAPI.uploadFiles(token, fd);
      setFiles(res.data.data || []);
    } catch (e) {
      setUploadError(e?.response?.data?.message || "Upload failed. Use PDF, image, Word, Excel or CSV (max 10 MB each).");
    } finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  };

  const removeFile = async (id) => {
    try {
      const res = await quoteAPI.deleteFile(token, id);
      setFiles(res.data.data || []);
    } catch {
      /* ignore */
    }
  };

  const handleSubmit = () => {
    setFormError("");
    submit.mutate();
  };

  const submit = useMutation({
    mutationFn: () => {
      const payload = {
        quotation_number: quotationNumber.trim() || null,
        currency,
        lines: data.items.map((it) => {
          const v = lines[it.rfq_item_id] || {};
          const cost = v.unit_cost === undefined || v.unit_cost === "" ? null : Number(v.unit_cost);
          return { rfq_item_id: it.rfq_item_id, unit_cost: cost, remarks: v.remarks || null };
        }),
      };
      return quoteAPI.submit(token, payload);
    },
    onSuccess: () => setDone(true),
    onError: (e) => setFormError(e?.response?.data?.message || "Submission failed. Please try again."),
  });

  const setLine = (id, k, v) => setLines((l) => ({ ...l, [id]: { ...l[id], [k]: v } }));

  if (isLoading) {
    return <Shell><div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Loading your request…</div></Shell>;
  }

  if (isError || !data) {
    return (
      <Shell>
        <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-red-600">
          This quotation link is invalid or has expired. Please contact Matria Marine.
        </div>
      </Shell>
    );
  }

  if (done) {
    return (
      <Shell>
        <div className="flex flex-col items-center gap-2 rounded-xl border border-slate-200 bg-white p-10 text-center">
          <CheckCircle className="h-12 w-12 text-green-600" />
          <h2 className="text-lg font-bold text-[#28364b]">Quotation submitted</h2>
          <p className="text-sm text-slate-500">Thank you — Matria Marine has received your prices. You may close this page.</p>
        </div>
      </Shell>
    );
  }

  return (
    <Shell>
      <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-6">
        <div className="grid gap-3 text-sm sm:grid-cols-3">
          <div><span className="text-slate-400">Vendor:</span> <span className="font-medium text-[#28364b]">{data.vendor?.name}</span></div>
          <div><span className="text-slate-400">Reference:</span> <span className="font-medium text-[#28364b]">{data.rfq?.vendor_reference || data.rfq?.reference}</span></div>
          <div><span className="text-slate-400">Vessel:</span> <span className="font-medium text-[#28364b]">{data.rfq?.ship_name || "—"}</span></div>
        </div>

        {data.rfq?.requirements?.length > 0 && (
          <div className="flex flex-wrap items-center gap-1.5 rounded-lg bg-slate-50 px-3 py-2">
            <span className="text-xs font-semibold uppercase tracking-wider text-slate-400">Requirements:</span>
            {data.rfq.requirements.map((req) => (
              <span key={req} className="rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-[#28364b] ring-1 ring-slate-200">{req}</span>
            ))}
          </div>
        )}

        {data.submitted && (
          <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
            You have already submitted a quotation. Submitting again will update your prices.
          </div>
        )}

        <div className="flex flex-wrap items-end gap-4">
          <div className="flex flex-col gap-1">
            <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">
              Quotation No. <span className="font-normal normal-case text-slate-400">(optional)</span>
            </label>
            <input
              value={quotationNumber}
              onChange={(e) => setQuotationNumber(e.target.value)}
              placeholder="Your quote / reference no."
              className="w-56 rounded border border-slate-200 px-2 py-1.5 text-sm"
            />
          </div>
          <div className="flex flex-col gap-1">
            <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">Currency</label>
            <div className="w-32"><Select value={currency} onChange={setCurrency} options={CURRENCIES} /></div>
          </div>
        </div>

        <div className="overflow-x-auto rounded-lg border border-slate-200">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <th className="px-3 py-2 font-semibold">Item</th>
                <th className="px-3 py-2 text-right font-semibold">Qty</th>
                <th className="px-3 py-2 font-semibold">Unit price</th>
                <th className="px-3 py-2 font-semibold">Remarks</th>
              </tr>
            </thead>
            <tbody>
              {data.items.map((it) => (
                <tr key={it.rfq_item_id} className="border-b border-slate-100 last:border-0">
                  <td className="px-3 py-2 text-slate-700">{it.description}</td>
                  <td className="px-3 py-2 text-right text-slate-500">{it.qty} {it.unit}</td>
                  <td className="px-3 py-2">
                    <input
                      type="number"
                      step="0.0001"
                      min="0"
                      placeholder="—"
                      value={lines[it.rfq_item_id]?.unit_cost ?? ""}
                      onChange={(e) => setLine(it.rfq_item_id, "unit_cost", e.target.value)}
                      className="w-28 rounded border border-slate-200 px-2 py-1 text-sm"
                    />
                  </td>
                  <td className="px-3 py-2">
                    <input
                      value={lines[it.rfq_item_id]?.remarks ?? ""}
                      onChange={(e) => setLine(it.rfq_item_id, "remarks", e.target.value)}
                      className="w-full rounded border border-slate-200 px-2 py-1 text-sm"
                      placeholder="optional"
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* File uploads — vendors can attach their own quotation / datasheets / certificates */}
        <div className="space-y-2">
          <label className="text-xs font-bold uppercase tracking-wider text-[#28364b]">Attach files (optional)</label>
          <div
            onClick={() => fileInputRef.current?.click()}
            onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
            onDragLeave={() => setDragOver(false)}
            onDrop={(e) => { e.preventDefault(); setDragOver(false); uploadFiles(e.dataTransfer.files); }}
            className={`flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-4 py-6 text-center transition-colors ${
              dragOver ? "border-[#28364b] bg-slate-50" : "border-slate-300 hover:bg-slate-50"
            }`}
          >
            <input
              ref={fileInputRef}
              type="file"
              multiple
              className="hidden"
              accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.doc,.docx,.csv,.txt"
              onChange={(e) => uploadFiles(e.target.files)}
            />
            {uploading ? (
              <span className="flex items-center gap-2 text-sm text-slate-500"><Loader2 className="h-4 w-4 animate-spin" /> Uploading…</span>
            ) : (
              <>
                <UploadCloud className="h-6 w-6 text-slate-400" />
                <span className="text-sm font-medium text-[#28364b]">Drag &amp; drop, or click to browse</span>
                <span className="text-xs text-slate-400">Your quotation, datasheets, certificates — PDF, image, Word, Excel, CSV (max 10 MB each)</span>
              </>
            )}
          </div>
          {uploadError && <p className="text-xs text-red-600">{uploadError}</p>}
          {files.length > 0 && (
            <ul className="space-y-1.5">
              {files.map((f) => (
                <li key={f.id} className="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
                  <span className="flex min-w-0 items-center gap-2 text-[#28364b]">
                    <Paperclip className="h-4 w-4 shrink-0 text-slate-400" />
                    <span className="truncate">{f.name}</span>
                    <span className="shrink-0 text-xs text-slate-400">{prettySize(f.size)}</span>
                  </span>
                  <button type="button" onClick={() => removeFile(f.id)} className="shrink-0 text-slate-400 hover:text-red-600" title="Remove">
                    <X className="h-4 w-4" />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <p className="text-xs text-slate-400">
          Enter prices where you can — you can also leave them blank and just <span className="font-medium text-slate-500">attach your quotation file</span> above; our team will fill in the rest.
        </p>

        {formError && (
          <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{formError}</div>
        )}

        <div className="flex justify-end">
          <button
            onClick={handleSubmit}
            disabled={submit.isLoading}
            className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-70"
          >
            {submit.isLoading && <Loader2 className="h-4 w-4 animate-spin" />}
            Submit quotation
          </button>
        </div>
      </div>
    </Shell>
  );
}
