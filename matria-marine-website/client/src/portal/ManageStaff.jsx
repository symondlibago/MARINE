import { useQuery } from "@tanstack/react-query";
import MasterCrud from "./MasterCrud";
import { usersAPI, authAPI } from "@/pages/api";

const ROLES = [
  { value: "super_admin", label: "Super Admin" },
  { value: "admin", label: "Admin" },
];

// Worth a second look before ticking: Payroll shows every salary, and the
// money screens show the company's figures.
const SENSITIVE = ["payroll", "accounting", "reports", "statements"];

const roleBadge = (r) => (
  <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${r.role === "super_admin" ? "bg-[#28364b] text-white" : "bg-slate-100 text-slate-700"}`}>
    {r.role === "super_admin" ? "Super Admin" : "Admin"}
  </span>
);

const statusPill = (r) => (
  <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${r.is_active ? "bg-green-100 text-green-700" : "bg-slate-100 text-slate-500"}`}>
    {r.is_active ? "Active" : "Inactive"}
  </span>
);

export default function ManageStaff() {
  // Reuses the cached "me" query from the portal auth guard — no extra request.
  const { data: me } = useQuery({ queryKey: ["me"], queryFn: async () => (await authAPI.getUser()).data });
  const role = (me?.data ?? me?.user ?? me)?.role;

  // The list of pages comes from the server, which is also what enforces it —
  // so a page added there appears here without anyone editing this file.
  const { data: pageList = [] } = useQuery({
    queryKey: ["users", "page-list"],
    queryFn: async () => (await usersAPI.list()).data?.meta?.pages ?? [],
    staleTime: 5 * 60 * 1000,
  });

  if (role && role !== "super_admin") {
    return <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">This page is for super admins only.</div>;
  }

  const pagesColumn = (r) => {
    if (r.role === "super_admin") return <span className="text-xs text-slate-400">Everything</span>;
    const n = (r.pages || []).length;
    const hasPayroll = (r.pages || []).includes("payroll");
    return (
      <span className="text-xs text-slate-600">
        {n === 0 ? <span className="text-slate-400">Dashboard only</span> : `${n} of ${pageList.length || n}`}
        {hasPayroll && <span className="ml-1.5 rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800">Payroll</span>}
      </span>
    );
  };

  return (
    <MasterCrud
      title="Manage Staff"
      singular="Staff member"
      queryKey="users"
      api={usersAPI}
      // A new admin starts with nothing ticked. Forgetting a box means they
      // ask for access; a box ticked by default could hand out payroll unseen.
      emptyRow={{ name: "", email: "", username: "", password: "", phone: "", role: "admin", is_active: true, pages: [] }}
      columns={[
        { key: "name", label: "Name" },
        { key: "username", label: "Username" },
        { key: "email", label: "Email" },
        { key: "phone", label: "Phone" },
        { key: "role", label: "Role", render: roleBadge },
        { key: "pages", label: "Can see", render: pagesColumn },
        { key: "is_active", label: "Status", render: statusPill },
      ]}
      fields={[
        { name: "name", label: "Full name", required: true },
        { name: "username", label: "Username (login)", required: true },
        { name: "email", label: "Email (shared mailbox OK)", type: "email", required: true },
        { name: "password", label: "Password (min 8 — leave blank when editing to keep)", type: "password" },
        { name: "phone", label: "Phone number" },
        { name: "role", label: "Role", type: "select", options: ROLES, required: true },
        {
          name: "pages",
          label: "Pages this admin can see",
          type: "checkboxes",
          options: pageList.map((p) => ({ value: p.key, label: p.label })),
          highlight: SENSITIVE,
          // A super admin sees everything, so there is nothing to choose.
          showIf: (form) => form.role === "admin",
          hint: "The Dashboard is always shown. Unticked pages disappear from their menu and are refused by the server too.",
        },
        { name: "is_active", label: "Active", type: "switch" },
      ]}
    />
  );
}
