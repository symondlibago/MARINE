import { useState, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Settings as SettingsIcon, Save, Info } from "lucide-react";
import { toast } from "sonner";
import { payrollAPI } from "./api";
import { money, btn, inputCls } from "./lib";
import { PageLoader, Spinner } from "@/portal/ui/Loading";

/**
 * The payslip letterhead, plus the statutory figures the calculations use —
 * shown read-only so the office can see what the system is working from.
 */
export default function Settings() {
  const qc = useQueryClient();
  const [form, setForm] = useState(null);

  const { data, isLoading } = useQuery({
    queryKey: ["pay", "settings", "full"],
    queryFn: async () => (await payrollAPI.settings()).data.data,
  });

  useEffect(() => {
    if (data?.settings && !form) setForm({ ...data.settings });
  }, [data]); // eslint-disable-line react-hooks/exhaustive-deps

  const save = useMutation({
    mutationFn: () =>
      payrollAPI.saveSettings({
        company_name: form.company_name?.trim(),
        uen: form.uen?.trim() || null,
        company_address: form.company_address?.trim() || null,
        currency: form.currency?.trim() || "SGD",
        default_payment_method: form.default_payment_method?.trim() || null,
        payslip_footer: form.payslip_footer?.trim() || null,
      }),
    onSuccess: () => {
      toast.success("Saved.");
      qc.invalidateQueries({ queryKey: ["pay", "settings"] });
      qc.invalidateQueries({ queryKey: ["pay", "settings", "full"] });
    },
    onError: (e) => toast.error(e?.response?.data?.message || "Could not save."),
  });

  if (isLoading || !form) return <PageLoader label="Loading settings…" />;

  const ref = data.reference;
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <div className="max-w-3xl space-y-4">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-bold text-[#28364b]">
          <SettingsIcon className="h-5 w-5 text-[#cebd88]" /> Settings
        </h1>
        <p className="mt-0.5 text-sm text-slate-500">What appears at the top of every payslip.</p>
      </div>

      <form
        onSubmit={(ev) => {
          ev.preventDefault();
          save.mutate();
        }}
        className="space-y-4 rounded-xl border border-slate-200 bg-white p-5"
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Company name">
            <input value={form.company_name || ""} onChange={set("company_name")} required className={`${inputCls} w-full`} />
          </Field>
          <Field label="UEN">
            <input value={form.uen || ""} onChange={set("uen")} className={`${inputCls} w-full`} />
          </Field>
          <Field label="Company address" className="sm:col-span-2">
            <textarea value={form.company_address || ""} onChange={set("company_address")} rows={2} className={`${inputCls} w-full`} />
          </Field>
          <Field label="Currency">
            <input value={form.currency || ""} onChange={set("currency")} className={`${inputCls} w-full`} />
          </Field>
          <Field label="Default payment method">
            <input value={form.default_payment_method || ""} onChange={set("default_payment_method")} className={`${inputCls} w-full`} />
          </Field>
          <Field label="Payslip footer line" className="sm:col-span-2">
            <input value={form.payslip_footer || ""} onChange={set("payslip_footer")} className={`${inputCls} w-full`} />
          </Field>
        </div>

        <div className="flex justify-end border-t border-slate-100 pt-4">
          <button type="submit" disabled={save.isLoading} className={btn.primary}>
            {save.isLoading ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />} Save
          </button>
        </div>
      </form>

      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="flex items-center gap-2 text-sm font-bold text-[#28364b]">
          <Info className="h-4 w-4 text-[#cebd88]" /> Statutory figures in use — {ref.year}
        </h2>
        <p className="mt-1 text-xs text-slate-500">
          These are published rates, not settings, so they are not editable here. CPF rates change on 1 January{" "}
          {ref.year + 1}; the figures below will need revisiting then.
        </p>

        <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
          <Row k="Ordinary Wage ceiling" v={`${form.currency} ${money(ref.ow_ceiling)} a month`} />
          <Row k="Annual salary ceiling" v={`${form.currency} ${money(ref.annual_ceiling)} a year`} />
          <Row k="SDL rate" v={`${(ref.sdl_rate * 100).toFixed(2)}% · min ${money(ref.sdl_min)} · max ${money(ref.sdl_max)}`} />
          <Row k="CPF age bands" v={Object.values(ref.age_bands).join(" · ")} />
          <Row k="CPF schemes" v={ref.cpf_schemes.join(" · ")} className="sm:col-span-2" />
          <Row k="Self-help group funds" v={ref.shg_funds.join(" · ")} className="sm:col-span-2" />
        </dl>
      </div>
    </div>
  );
}

function Field({ label, className = "", children }) {
  return (
    <label className={`flex flex-col gap-1 ${className}`}>
      <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</span>
      {children}
    </label>
  );
}

function Row({ k, v, className = "" }) {
  return (
    <div className={className}>
      <dt className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{k}</dt>
      <dd className="text-sm text-[#28364b]">{v}</dd>
    </div>
  );
}
