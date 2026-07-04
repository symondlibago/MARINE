import MasterCrud from "./MasterCrud";
import { vendorsAPI } from "@/pages/api";

const CURRENCIES = ["USD", "EUR", "SGD", "AED", "PHP", "INR", "GBP", "JPY"];

const statusPill = (r) => (
  <span
    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
      r.is_active ? "bg-green-100 text-green-700" : "bg-slate-100 text-slate-500"
    }`}
  >
    {r.is_active ? "Active" : "Inactive"}
  </span>
);

export default function Vendors() {
  return (
    <MasterCrud
      title="Vendors"
      singular="Vendor"
      queryKey="vendors"
      api={vendorsAPI}
      emptyRow={{
        name: "",
        contact_name: "",
        email: "",
        phone: "",
        address: "",
        currency: "USD",
        notes: "",
        is_active: true,
      }}
      columns={[
        { key: "name", label: "Name" },
        { key: "contact_name", label: "Contact" },
        { key: "email", label: "Email" },
        { key: "phone", label: "Phone" },
        { key: "currency", label: "Currency" },
        { key: "is_active", label: "Status", render: statusPill },
      ]}
      fields={[
        { name: "name", label: "Name", required: true },
        { name: "contact_name", label: "Contact name" },
        { name: "email", label: "Email", placeholder: "sales@acme.com, orders@acme.com", hint: "You can enter multiple emails separated by commas — the enquiry goes to all of them." },
        { name: "phone", label: "Phone" },
        { name: "currency", label: "Currency", type: "select", options: CURRENCIES, required: true },
        { name: "address", label: "Address", type: "textarea" },
        { name: "notes", label: "Notes", type: "textarea" },
        { name: "is_active", label: "Active", type: "switch" },
      ]}
    />
  );
}
