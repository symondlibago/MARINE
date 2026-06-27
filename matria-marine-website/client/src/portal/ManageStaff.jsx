import { useQuery } from "@tanstack/react-query";
import MasterCrud from "./MasterCrud";
import { usersAPI, authAPI } from "@/pages/api";

const ROLES = [
  { value: "super_admin", label: "Super Admin" },
  { value: "admin", label: "Admin" },
];

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
  if (role && role !== "super_admin") {
    return <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">This page is for super admins only.</div>;
  }

  return (
    <MasterCrud
      title="Manage Staff"
      singular="Staff member"
      queryKey="users"
      api={usersAPI}
      emptyRow={{ name: "", email: "", password: "", phone: "", role: "admin", is_active: true }}
      columns={[
        { key: "name", label: "Name" },
        { key: "email", label: "Email" },
        { key: "phone", label: "Phone" },
        { key: "role", label: "Role", render: roleBadge },
        { key: "is_active", label: "Status", render: statusPill },
      ]}
      fields={[
        { name: "name", label: "Full name", required: true },
        { name: "email", label: "Email (login)", type: "email", required: true },
        { name: "password", label: "Password (min 8 — leave blank when editing to keep)", type: "password" },
        { name: "phone", label: "Phone number" },
        { name: "role", label: "Role", type: "select", options: ROLES, required: true },
        { name: "is_active", label: "Active", type: "switch" },
      ]}
    />
  );
}
