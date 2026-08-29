import { useMemo, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useLocation } from "wouter";
import { motion } from "framer-motion";
import { ShoppingCart, CheckCircle2, ChevronRight, ChevronDown, Plus, Save } from "lucide-react";
import { toast } from "sonner";
import { purchaseOrdersAPI, vendorsAPI } from "@/pages/api";
import { TableSkeleton, Spinner } from "./ui/Loading";
import Select from "./ui/Select";
import ListToolbar from "./ui/ListToolbar";
import Modal from "./ui/Modal";
import EntityPicker from "./ui/EntityPicker";

const CURRENCIES = ["SGD", "USD", "EUR", "AED", "PHP", "INR", "GBP", "JPY"];

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-600",
  issued: "bg-blue-100 text-blue-700",
  received: "bg-green-100 text-green-700",
  cancelled: "bg-red-100 text-red-700",
};

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "draft", label: "Draft" },
  { value: "issued", label: "Issued" },
  { value: "received", label: "Received" },
  { value: "cancelled", label: "Cancelled" },
];

function StatusBadge({ status }) {
  return (
    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[status] || "bg-slate-100"}`}>{status}</span>
  );
}

export default function PurchaseOrders() {
  const [, setLocation] = useLocation();
  const qc = useQueryClient();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [open, setOpen] = useState({});
  const [form, setForm] = useState(null);   // the direct-purchase dialog

  // A purchase with no enquiry behind it. Created with just a vendor, then
  // the items are added on the detail screen like any other purchase order.
  const createDirect = useMutation({
    mutationFn: () =>
      purchaseOrdersAPI.createDirect({
        vendor_id: Number(form.vendor_id),
        currency: form.currency || undefined,
      }),
    onSuccess: (res) => {
      toast.success(res?.data?.message || "Purchase order created.");
      setForm(null);
      qc.invalidateQueries({ queryKey: ["purchase-orders"] });
      setLocation(`/purchase-orders/${res.data.data.id}`);
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not create the purchase order."),
  });

  const { data, isLoading } = useQuery({
    queryKey: ["purchase-orders", status],
    queryFn: async () => (await purchaseOrdersAPI.list(status ? { status } : {})).data.data,
  });

  const all = data ?? [];
  const rows = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return all;

    // Everything now on screen is searchable — a column you can read but not
    // search for is the more annoying half of the feature.
    return all.filter((r) =>
      [r.po_number, r.vendor, r.reference, r.customer, r.customer_reference, r.ship_name]
        .some((v) => String(v || "").toLowerCase().includes(q))
    );
  }, [all, search]);

  // One row per enquiry; the individual vendor POs are nested underneath.
  const groups = useMemo(() => {
    const map = new Map();
    for (const r of rows) {
      const key = r.rfq_id ?? `solo-${r.id}`;
      if (!map.has(key)) {
        map.set(key, { key, rfq_id: r.rfq_id, reference: r.reference, pos: [] });
      }
      map.get(key).pos.push(r);
    }
    return Array.from(map.values()).map((g) => {
      const currencies = new Set(g.pos.map((p) => p.currency));
      const statusCounts = g.pos.reduce((m, p) => ({ ...m, [p.status]: (m[p.status] || 0) + 1 }), {});
      const issuedDates = g.pos.map((p) => p.issued_date).filter(Boolean).sort();
      return {
        ...g,
        total: g.pos.reduce((s, p) => s + Number(p.subtotal || 0), 0),
        currency: currencies.size === 1 ? [...currencies][0] : null,
        items: g.pos.reduce((s, p) => s + Number(p.items_count || 0), 0),
        statusCounts,
        latestIssued: issuedDates.length ? issuedDates[issuedDates.length - 1] : null,
      };
    });
  }, [rows]);

  const toggle = (key) => setOpen((o) => ({ ...o, [key]: !o[key] }));

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">Purchase Orders</h1>
          <p className="text-sm text-slate-500">
            {groups.length} enquir{groups.length === 1 ? "y" : "ies"} · {rows.length} order{rows.length === 1 ? "" : "s"}
          </p>
        </div>
        <button
          onClick={() => setForm({ vendor_id: "", currency: "" })}
          className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-70"
        >
          <Plus className="h-4 w-4" /> New Direct Purchase
        </button>
      </div>

      <ListToolbar
        search={search}
        onSearch={setSearch}
        placeholder="Search PO #, vendor, enquiry…"
        filters={[{ value: status, onChange: setStatus, options: STATUS_OPTIONS, title: "Filter by status" }]}
      />

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-3 py-3 font-semibold">Enquiry / PO</th>
              <th className="px-3 py-3 font-semibold">Vendor</th>
              <th className="px-3 py-3 font-semibold">Customer</th>
              <th className="px-3 py-3 font-semibold">Customer ref.</th>
              <th className="px-3 py-3 font-semibold">Vessel</th>
              <th className="px-3 py-3 font-semibold">Prepared by</th>
              <th className="px-3 py-3 font-semibold">Status</th>
              <th className="px-3 py-3 text-right font-semibold">Items</th>
              <th className="px-3 py-3 text-right font-semibold">Total</th>
              <th className="px-3 py-3 font-semibold">Cur.</th>
              <th className="px-3 py-3 font-semibold">Issued</th>
              <th className="px-3 py-3 font-semibold">Expected</th>
            </tr>
          </thead>
          {isLoading ? (
            <tbody><TableSkeleton cols={12} /></tbody>
          ) : groups.length === 0 ? (
            <tbody>
              <tr>
                <td colSpan={12} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center gap-2">
                    <ShoppingCart className="h-8 w-8 text-slate-300" />
                    No purchase orders yet — generate them from a finished enquiry's <span className="font-medium">Delivery Order</span>,
                    or click <span className="font-medium">New Direct Purchase</span> for something bought outside an enquiry.
                  </div>
                </td>
              </tr>
            </tbody>
          ) : (
              groups.map((g, i) => {
                const isOpen = !!open[g.key];
                return (
                  <motion.tbody
                    key={g.key}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: Math.min(i * 0.02, 0.3) }}
                  >
                    {/* Enquiry group header */}
                    <tr
                      onClick={() => toggle(g.key)}
                      className="cursor-pointer border-b border-slate-100 bg-slate-50/60 hover:bg-slate-100/70"
                    >
                      <td className="px-4 py-3 font-semibold text-[#28364b]">
                        <span className="inline-flex items-center gap-1.5">
                          {isOpen ? <ChevronDown className="h-4 w-4 text-slate-400" /> : <ChevronRight className="h-4 w-4 text-slate-400" />}
                          {g.reference || (g.rfq_id ? "—" : g.pos[0]?.po_number)}
                          {!g.rfq_id && (
                            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">Direct</span>
                          )}
                          <span className="ml-1 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600">
                            {g.pos.length} PO{g.pos.length === 1 ? "" : "s"}
                          </span>
                        </span>
                      </td>
                      <td className="px-3 py-3 text-slate-500">
                        {g.pos.length} vendor{g.pos.length === 1 ? "" : "s"}
                      </td>
                      {/* One enquiry, one customer — so the group row can carry it. */}
                      <td className="px-3 py-3 text-slate-700">{g.pos[0]?.customer || "—"}</td>
                      <td className="px-3 py-3 text-slate-500">{g.pos[0]?.customer_reference || "—"}</td>
                      <td className="px-3 py-3 text-slate-700">{g.pos[0]?.ship_name || "—"}</td>
                      <td className="px-3 py-3"></td>
                      <td className="px-3 py-3">
                        <span className="flex flex-wrap gap-1">
                          {Object.entries(g.statusCounts).map(([s, n]) => (
                            <span key={s} className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[s] || "bg-slate-100"}`}>
                              {n} {s}
                            </span>
                          ))}
                        </span>
                      </td>
                      <td className="px-3 py-3 text-right text-slate-600">{g.items}</td>
                      <td className="px-3 py-3 text-right font-semibold text-[#28364b] whitespace-nowrap">
                        {g.currency ? g.total.toFixed(2) : <span className="text-xs font-normal text-slate-400">mixed</span>}
                      </td>
                      <td className="px-3 py-3 text-xs text-slate-500">{g.currency || ""}</td>
                      <td className="px-3 py-3 text-slate-500 whitespace-nowrap">{g.latestIssued || "—"}</td>
                      <td className="px-3 py-3"></td>
                    </tr>

                    {/* Individual vendor POs */}
                    {isOpen &&
                      g.pos.map((r) => (
                        <tr
                          key={r.id}
                          onClick={() => setLocation(`/purchase-orders/${r.id}`)}
                          className="cursor-pointer border-b border-slate-100 last:border-0 hover:bg-blue-50/40"
                        >
                          <td className="px-3 py-2.5 pl-11 font-medium text-[#28364b] whitespace-nowrap">{r.po_number}</td>
                          <td className="px-3 py-2.5 text-slate-700">{r.vendor || "—"}</td>
                          <td className="px-3 py-2.5 text-slate-700">{r.customer || "—"}</td>
                          <td className="px-3 py-2.5 text-slate-500">{r.customer_reference || "—"}</td>
                          <td className="px-3 py-2.5 text-slate-700">{r.ship_name || "—"}</td>
                          <td className="px-3 py-2.5 text-slate-500">{r.prepared_by || "—"}</td>
                          <td className="px-3 py-2.5">
                            <span className="inline-flex items-center gap-1.5">
                              <StatusBadge status={r.status} />
                              {r.accepted_at && <CheckCircle2 className="h-4 w-4 text-green-600" title="Accepted by vendor" />}
                            </span>
                          </td>
                          <td className="px-3 py-2.5 text-right text-slate-600">{r.items_count}</td>
                          <td className="px-3 py-2.5 text-right font-medium text-[#28364b] whitespace-nowrap">
                            {Number(r.subtotal).toFixed(2)}
                          </td>
                          <td className="px-3 py-2.5 text-xs text-slate-500">{r.currency}</td>
                          <td className="px-3 py-2.5 text-slate-500 whitespace-nowrap">{r.issued_date || "—"}</td>
                          <td className="px-3 py-2.5 text-slate-500 whitespace-nowrap">{r.expected_date || "—"}</td>
                        </tr>
                      ))}
                  </motion.tbody>
                );
              })
            )}
        </table>
      </div>

      <Modal open={!!form} onClose={() => setForm(null)} title="New direct purchase">
        {form && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              if (form.vendor_id) createDirect.mutate();
            }}
            className="space-y-4 px-6 py-5"
          >
            <p className="rounded-lg bg-slate-50 px-3 py-2.5 text-xs leading-relaxed text-slate-500">
              For anything bought outside an enquiry — office items, tools, consumables. You add the items on the next
              screen, and it reconciles against the vendor's invoice like any other purchase order.
            </p>

            <label className="flex flex-col gap-1">
              <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Vendor</span>
              <EntityPicker
                api={vendorsAPI}
                queryKey="vendors"
                value={form.vendor_id}
                onChange={(v) => setForm((f) => ({ ...f, vendor_id: v }))}
                placeholder="— Select vendor —"
              />
            </label>

            <label className="flex flex-col gap-1">
              <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Currency</span>
              <Select
                value={form.currency}
                onChange={(v) => setForm((f) => ({ ...f, currency: v }))}
                options={[{ value: "", label: "Use the vendor's currency" }, ...CURRENCIES.map((c) => ({ value: c, label: c }))]}
              />
            </label>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setForm(null)} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-[#28364b] hover:bg-slate-50">
                Cancel
              </button>
              <button
                type="submit"
                disabled={!form.vendor_id || createDirect.isLoading}
                className="inline-flex items-center gap-2 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63] disabled:opacity-50"
              >
                {createDirect.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Create
              </button>
            </div>
          </form>
        )}
      </Modal>
    </motion.div>
  );
}
