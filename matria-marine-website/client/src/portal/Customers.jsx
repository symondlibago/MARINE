import MasterCrud from "./MasterCrud";
import { customersAPI } from "@/pages/api";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

/**
 * The customer number the accounting side identifies this customer by. Issued
 * by the system from 10001 and never editable — hence a column, not a field.
 */
const customerNo = (r) =>
  r.customer_no ? (
    <span className="font-semibold tabular-nums text-[#28364b]">{r.customer_no}</span>
  ) : (
    <span className="text-slate-300">—</span>
  );

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
        { key: "customer_no", label: "No.", render: customerNo },
        { key: "name", label: "Name" },
        { key: "email", label: "Email" },
        { key: "phone", label: "Phone" },
        { key: "currency", label: "Currency" },
        { key: "is_active", label: "Status", render: statusPill },
      ]}
      fields={[
        { name: "name", label: "Customer name", required: true },
        { name: "address", label: "Address (shown on quotations & orders)", type: "textarea" },
        { name: "email", label: "Email", placeholder: "accounts@acme.com, purchasing@acme.com", hint: "You can enter multiple emails separated by commas — quotations go to all of them." },
        { name: "phone", label: "Phone" },
        { name: "currency", label: "Default currency", type: "select", options: CURRENCIES, required: true },
        { name: "notes", label: "Notes", type: "textarea" },
        { name: "is_active", label: "Active", type: "switch" },
      ]}
    />
  );
}
