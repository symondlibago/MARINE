import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Users, Plus, Pencil, Trash2, Search, Save } from "lucide-react";
import { toast } from "sonner";
import { payrollAPI } from "./api";
import { money, dateOf, btn, inputCls, numCls } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";
import { useConfirm } from "@/portal/ui/confirm";
import Modal from "@/portal/ui/Modal";
import Select from "@/portal/ui/Select";
import DatePicker from "@/portal/ui/DatePicker";

const blank = () => ({
  id: null,
  code: "",
  full_name: "",
  nric: "",
  date_of_birth: "",
  status: "active",
  cpf_scheme: "SC / SPR 3+ (Full)",
  shg_fund: "None",
  basic_salary: 0,
  fixed_allowance: 0,
  payment_method: "",
  bank_ref: "",
  job_title: "",
  start_date: "",
  end_date: "",
  notes: "",
});

/**
 * The employee master — entered once. Every payroll month copies from here, so
 * a change made today affects the next month opened, never a month already run.
 */
export default function Employees() {
  const qc = useQueryClient();
  const confirm = useConfirm();

  const [search, setSearch] = useState("");
  const [form, setForm] = useState(null);

  const { data, isLoading } = useQuery({
    queryKey: ["pay", "employees", search],
    queryFn: async () => (await payrollAPI.employees({ search: search || undefined })).data.data,
    keepPreviousData: true,
  });

  const employees = data?.employees ?? [];
  const schemes = data?.cpf_schemes ?? [];
  const funds = data?.shg_funds ?? [];

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        code: form.code?.trim() || null,
        full_name: form.full_name.trim(),
        nric: form.nric?.trim() || null,
        date_of_birth: form.date_of_birth || null,
        status: form.status,
        cpf_scheme: form.cpf_scheme,
        shg_fund: form.shg_fund,
        basic_salary: Number(form.basic_salary) || 0,
        fixed_allowance: Number(form.fixed_allowance) || 0,
        payment_method: form.payment_method?.trim() || null,
        bank_ref: form.bank_ref?.trim() || null,
        job_title: form.job_title?.trim() || null,
        start_date: form.start_date || null,
        end_date: form.end_date || null,
        notes: form.notes?.trim() || null,
      };
      return form.id ? payrollAPI.updateEmployee(form.id, payload) : payrollAPI.createEmployee(payload);
    },
    onSuccess: (res) => {
      toast.success(res?.data?.message || "Saved.");
      setForm(null);
      qc.invalidateQueries({ queryKey: ["pay", "employees"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save the employee."),
  });

  const remove = useMutation({
    mutationFn: (id) => payrollAPI.removeEmployee(id),
    onSuccess: (res) => {
      toast.success(res?.data?.message || "Removed.");
      qc.invalidateQueries({ queryKey: ["pay", "employees"] });
    },
    onError: () => toast.error("Could not remove the employee."),
  });

  const askRemove = async (employee) => {
    const ok = await confirm({
      title: `Remove ${employee.full_name}?`,
      message: "Payslips already issued keep their own copy of the details, so nothing already printed changes.",
      confirmText: "Remove",
      tone: "danger",
    });
    if (ok) remove.mutate(employee.id);
  };

  const openNew = () => setForm({ ...blank(), code: data?.next_code || "" });

  if (isLoading) return <PageLoader label="Loading employees…" />;

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  // Select and DatePicker hand back the value itself, not an event.
  const pick = (k) => (value) => setForm((f) => ({ ...f, [k]: value }));

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-bold text-[#28364b]">
            <Users className="h-5 w-5 text-[#cebd88]" /> Employees
          </h1>
          <p className="mt-0.5 text-sm text-slate-500">
            {employees.length} on file · entered once, used by every payroll month
          </p>
        </div>
        <div className="flex items-center gap-2">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Name, ID or NRIC"
              className={`${inputCls} w-56 pl-9`}
            />
          </div>
          <button onClick={openNew} className={btn.primary}>
            <Plus className="h-4 w-4" /> Add employee
          </button>
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3">ID</th>
              <th className="px-4 py-3">Name</th>
              <th className="px-4 py-3">NRIC / FIN</th>
              <th className="px-4 py-3">CPF Scheme</th>
              <th className="px-4 py-3">SHG</th>
              <th className="px-4 py-3 text-right">Basic</th>
              <th className="px-4 py-3 text-right">Allowance</th>
              <th className="px-4 py-3">Started</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody>
            {employees.map((e) => (
              <tr key={e.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                <td className="px-4 py-3 font-semibold text-[#28364b]">{e.code}</td>
                <td className="px-4 py-3">
                  <div className="font-medium text-[#28364b]">{e.full_name}</div>
                  {e.job_title && <div className="text-xs text-slate-400">{e.job_title}</div>}
                </td>
                <td className="px-4 py-3 text-slate-500">{e.nric || "—"}</td>
                <td className="px-4 py-3 text-xs text-slate-500">{e.cpf_scheme}</td>
                <td className="px-4 py-3 text-slate-500">{e.shg_fund}</td>
                <td className="px-4 py-3 text-right font-semibold text-[#28364b]">{money(e.basic_salary)}</td>
                <td className="px-4 py-3 text-right text-slate-500">{money(e.fixed_allowance)}</td>
                <td className="px-4 py-3 text-slate-500">{dateOf(e.start_date)}</td>
                <td className="px-4 py-3">
                  <span
                    className={`rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ${
                      e.status === "active"
                        ? "bg-emerald-50 text-emerald-700 ring-emerald-200"
                        : "bg-slate-100 text-slate-500 ring-slate-200"
                    }`}
                  >
                    {e.status === "active" ? "ACTIVE" : "INACTIVE"}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-1">
                    <button
                      onClick={() => setForm({ ...blank(), ...e, date_of_birth: e.date_of_birth?.slice(0, 10) || "", start_date: e.start_date?.slice(0, 10) || "", end_date: e.end_date?.slice(0, 10) || "" })}
                      className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-[#28364b]"
                      title="Edit"
                    >
                      <Pencil className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => askRemove(e)}
                      className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600"
                      title="Remove"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
            {employees.length === 0 && (
              <tr>
                <td colSpan={10} className="px-4 py-10 text-center text-slate-400">
                  {search ? "Nobody matches that search." : "No employees yet. Add the first one to get started."}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <Modal open={!!form} onClose={() => setForm(null)} title={form?.id ? "Edit employee" : "Add employee"} maxWidth="max-w-2xl">
        {form && (
          <form
            onSubmit={(ev) => {
              ev.preventDefault();
              save.mutate();
            }}
            className="space-y-4 px-6 py-5"
          >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <Field label="Employee ID">
                <input value={form.code} onChange={set("code")} className={`${inputCls} w-full`} placeholder="MM001" />
              </Field>
              <Field label="Full name" className="sm:col-span-2">
                <input value={form.full_name} onChange={set("full_name")} required className={`${inputCls} w-full`} />
              </Field>
              <Field label="NRIC / FIN / Work pass">
                <input value={form.nric} onChange={set("nric")} className={`${inputCls} w-full`} />
              </Field>
              <Field label="Date of birth" hint="Sets the CPF age band">
                <DatePicker value={form.date_of_birth} onChange={pick("date_of_birth")} placeholder="Pick a date" />
              </Field>
              <Field label="Job title">
                <input value={form.job_title} onChange={set("job_title")} className={`${inputCls} w-full`} />
              </Field>
              <Field label="CPF scheme" className="sm:col-span-2">
                <Select value={form.cpf_scheme} onChange={pick("cpf_scheme")} options={schemes} />
              </Field>
              <Field label="Self-help group">
                <Select value={form.shg_fund} onChange={pick("shg_fund")} options={funds} />
              </Field>
              <Field label="Monthly basic salary">
                <input type="number" step="0.01" min="0" value={form.basic_salary} onChange={set("basic_salary")} className={`${numCls} w-full`} />
              </Field>
              <Field label="Fixed allowance">
                <input type="number" step="0.01" min="0" value={form.fixed_allowance} onChange={set("fixed_allowance")} className={`${numCls} w-full`} />
              </Field>
              <Field label="Status">
                <Select
                  value={form.status}
                  onChange={pick("status")}
                  options={[
                    { value: "active", label: "Active" },
                    { value: "inactive", label: "Inactive" },
                  ]}
                />
              </Field>
              <Field label="Payment method">
                <input value={form.payment_method} onChange={set("payment_method")} className={`${inputCls} w-full`} placeholder="Bank Transfer" />
              </Field>
              <Field label="Bank / pay reference" className="sm:col-span-2">
                <input value={form.bank_ref} onChange={set("bank_ref")} className={`${inputCls} w-full`} />
              </Field>
              <Field label="Start date">
                <DatePicker value={form.start_date} onChange={pick("start_date")} placeholder="Pick a date" />
              </Field>
              <Field label="End date">
                <DatePicker value={form.end_date} onChange={pick("end_date")} placeholder="Pick a date" />
              </Field>
              <Field label="Notes" className="sm:col-span-3">
                <textarea value={form.notes} onChange={set("notes")} rows={2} className={`${inputCls} w-full`} />
              </Field>
            </div>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setForm(null)} className={btn.ghost}>
                Cancel
              </button>
              <button type="submit" disabled={save.isLoading} className={btn.primary}>
                {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
}

function Field({ label, hint, className = "", children }) {
  return (
    <label className={`flex flex-col gap-1 ${className}`}>
      <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</span>
      {children}
      {hint && <span className="text-[11px] text-slate-400">{hint}</span>}
    </label>
  );
}
