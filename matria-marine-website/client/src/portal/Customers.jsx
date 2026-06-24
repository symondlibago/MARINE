import MasterCrud from "./MasterCrud";
import { customersAPI } from "@/pages/api";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

const statusPill = (r) => (
  <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${r.is_active ? "bg-green-100 text-green-700" : "bg-slate-100 text-slate-500"}`}>
    {r.is_active ? "Active" : "Inactive"}
  </span>
);

export default function Customers() {
  return (
    <MasterCrud
      title="Customers"
      singular="Customer"
      queryKey="customers"
      api={customersAPI}
      emptyRow={{ name: "", address: "", email: "", phone: "", currency: "USD", is_active: true, notes: "" }}
      columns={[
        { key: "name", label: "Name" },
        { key: "email", label: "Email" },
        { key: "phone", label: "Phone" },
        { key: "currency", label: "Currency" },
        { key: "is_active", label: "Status", render: statusPill },
      ]}
      fields={[
        { name: "name", label: "Customer name", required: true },
        { name: "address", label: "Address (shown on quotations & orders)", type: "textarea" },
        { name: "email", label: "Email", type: "email" },
        { name: "phone", label: "Phone" },
        { name: "currency", label: "Default currency", type: "select", options: CURRENCIES, required: true },
        { name: "notes", label: "Notes", type: "textarea" },
        { name: "is_active", label: "Active", type: "switch" },
      ]}
    />
  );
}
