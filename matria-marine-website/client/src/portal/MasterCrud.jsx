import { useEffect, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus, Pencil, Trash2, Search, ChevronLeft, ChevronRight } from "lucide-react";
import { motion } from "framer-motion";
import { toast } from "sonner";
import Modal from "./ui/Modal";
import Select from "./ui/Select";
import { Spinner, TableSkeleton } from "./ui/Loading";
import { useConfirm } from "./ui/confirm";

const inputCls =
  "w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-[#28364b] focus:outline-none focus:ring-1 focus:ring-[#28364b]";

const PER_PAGE = 100;

export default function MasterCrud({ title, singular, queryKey, api, columns, fields, emptyRow }) {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyRow);

  // Reset to the first page whenever the search term changes.
  useEffect(() => {
    setPage(1);
  }, [search]);

  const { data, isLoading, isFetching } = useQuery({
    queryKey: [queryKey, search, page],
    queryFn: async () =>
      (await api.list({ ...(search ? { search } : {}), page, per_page: PER_PAGE })).data,
    keepPreviousData: true,
  });
  const rows = data?.data ?? [];
  const total = data?.meta?.total ?? rows.length;
  const lastPage = data?.meta?.last_page ?? 1;
  const rangeStart = total === 0 ? 0 : (page - 1) * PER_PAGE + 1;
  const rangeEnd = Math.min(page * PER_PAGE, total);

  const saveMutation = useMutation({
    mutationFn: (payload) => (editing ? api.update(editing.id, payload) : api.create(payload)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: [queryKey] });
      toast.success(`${singular} ${editing ? "updated" : "created"}.`);
      setDialogOpen(false);
    },
    onError: (err) => toast.error(err.response?.data?.message || "Something went wrong."),
  });

  const deleteMutation = useMutation({
    mutationFn: (id) => api.remove(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: [queryKey] });
      toast.success(`${singular} deleted.`);
    },
    onError: () => toast.error("Delete failed."),
  });

  const openCreate = () => {
    setEditing(null);
    setForm(emptyRow);
    setDialogOpen(true);
  };
  const openEdit = (row) => {
    setEditing(row);
    setForm({ ...emptyRow, ...row });
    setDialogOpen(true);
  };
  const setField = (name, value) => setForm((f) => ({ ...f, [name]: value }));

  const handleDelete = async (row) => {
    const ok = await confirm({
      title: `Delete ${singular.toLowerCase()}?`,
      message: `"${row.name}" will be permanently removed.`,
      confirmText: "Delete",
      tone: "danger",
    });
    if (ok) deleteMutation.mutate(row.id);
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    saveMutation.mutate(form);
  };

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.25 }} className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-[#28364b]">{title}</h1>
          <p className="text-sm text-slate-500">{total.toLocaleString()} record{total === 1 ? "" : "s"}</p>
        </div>
        <button onClick={openCreate} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[#3c4a63]">
          <Plus className="h-4 w-4" /> New {singular}
        </button>
      </div>

      <div className="relative max-w-sm">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={`Search ${title.toLowerCase()}…`} className={inputCls + " pl-9"} />
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              {columns.map((c) => <th key={c.key} className="px-4 py-3 font-semibold">{c.label}</th>)}
              <th className="px-4 py-3 text-right font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <TableSkeleton cols={columns.length + 1} />
            ) : rows.length === 0 ? (
              <tr><td colSpan={columns.length + 1} className="py-10 text-center text-slate-400">No {title.toLowerCase()} yet.</td></tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                  {columns.map((c) => <td key={c.key} className="px-4 py-3 text-slate-700">{c.render ? c.render(row) : row[c.key] ?? "—"}</td>)}
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-1">
                      <button onClick={() => openEdit(row)} className="rounded p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-[#28364b]" title="Edit">
                        <Pencil className="h-4 w-4" />
                      </button>
                      <button onClick={() => handleDelete(row)} className="rounded p-1.5 text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600" title="Delete">
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {!isLoading && total > 0 && (
        <div className="flex flex-col items-center justify-between gap-3 sm:flex-row">
          <p className="text-sm text-slate-500">
            Showing <span className="font-medium text-slate-700">{rangeStart.toLocaleString()}</span>–
            <span className="font-medium text-slate-700">{rangeEnd.toLocaleString()}</span> of{" "}
            <span className="font-medium text-slate-700">{total.toLocaleString()}</span>
          </p>
          <div className="flex items-center gap-1">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page <= 1 || isFetching}
              className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
            >
              <ChevronLeft className="h-4 w-4" /> Prev
            </button>
            <span className="px-3 text-sm text-slate-500">
              Page <span className="font-semibold text-[#28364b]">{page}</span> of {lastPage.toLocaleString()}
            </span>
            <button
              onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
              disabled={page >= lastPage || isFetching}
              className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
            >
              Next <ChevronRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      )}

      <Modal open={dialogOpen} onClose={() => setDialogOpen(false)} title={editing ? `Edit ${singular}` : `New ${singular}`}>
        <form onSubmit={handleSubmit} className="space-y-4 p-6">
          {fields.map((field) => (
            <div key={field.name} className="space-y-1.5">
              <label htmlFor={field.name} className="text-xs font-bold uppercase tracking-wider text-[#28364b]">
                {field.label}
                {field.required && <span className="text-red-500"> *</span>}
              </label>
              {field.type === "textarea" ? (
                <textarea id={field.name} value={form[field.name] ?? ""} onChange={(e) => setField(field.name, e.target.value)} className={inputCls} rows={2} />
              ) : field.type === "select" ? (
                <Select value={form[field.name] ?? ""} onChange={(v) => setField(field.name, v)} options={field.options} placeholder={`Select ${field.label.toLowerCase()}`} />
              ) : field.type === "switch" ? (
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setField(field.name, !form[field.name])}
                    className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${form[field.name] ? "bg-[#28364b]" : "bg-slate-300"}`}
                  >
                    <motion.span animate={{ x: form[field.name] ? 22 : 2 }} transition={{ type: "spring", stiffness: 500, damping: 30 }} className="h-5 w-5 rounded-full bg-white shadow" />
                  </button>
                  <span className="text-sm text-slate-500">{form[field.name] ? "Active" : "Inactive"}</span>
                </div>
              ) : (
                <input id={field.name} type={field.type || "text"} value={form[field.name] ?? ""} onChange={(e) => setField(field.name, e.target.value)} className={inputCls} />
              )}
            </div>
          ))}
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => setDialogOpen(false)} className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
            <button type="submit" disabled={saveMutation.isLoading} className="inline-flex items-center gap-1 rounded-lg bg-[#28364b] px-4 py-2 text-sm font-semibold text-white hover:bg-[#3c4a63] disabled:opacity-70">
              {saveMutation.isLoading && <Spinner className="h-4 w-4" />}
              {editing ? "Save changes" : `Create ${singular}`}
            </button>
          </div>
        </form>
      </Modal>
    </motion.div>
  );
}
