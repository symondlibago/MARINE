import { Link, useLocation } from "wouter";
import { LayoutDashboard, FileText, Ship, ShoppingCart, BarChart3, Users, LogOut, Tag, Truck, Undo2 } from "lucide-react";
import { authAPI } from "@/pages/api";
import { cn } from "@/lib/utils";

const NAV = [
  { label: "Dashboard", to: "/", icon: LayoutDashboard },
  { label: "Enquiries", to: "/enquiries", icon: FileText },
  { label: "Offers", to: "/offers", icon: Tag },
  { label: "Delivery Orders", to: "/delivery-orders", icon: Truck },
  { label: "Purchase Orders", to: "/purchase-orders", icon: ShoppingCart },
  { label: "Return Notes", to: "/return-notes", icon: Undo2 },
  { label: "Reports", to: "/reports", icon: BarChart3 },
  { label: "Customers", to: "/customers", icon: Users },
  { label: "Vendors", to: "/vendors", icon: Ship },
];

export default function PortalLayout({ user, children }) {
  const [location] = useLocation();

  const handleLogout = async () => {
    try {
      await authAPI.logout();
    } catch (e) {
      // ignore — clear client state regardless
    }
    window.location.assign("/");
  };

  return (
    <div className="flex min-h-screen bg-slate-50 text-[#28364b]">
      <aside className="hidden w-64 flex-col border-r border-slate-200 bg-white md:flex">
        <div className="flex h-16 items-center gap-2.5 border-b border-slate-200 px-5">
          <img src="/logo.png" alt="Matria Marine" className="h-9 w-9 shrink-0 object-contain" />
          <div className="flex flex-col leading-none">
            <span className="text-sm font-bold tracking-[0.15em] text-[#28364b]">MATRIA</span>
            <span className="text-[9px] tracking-[0.18em] text-slate-400">MARINE SERVICES</span>
          </div>
        </div>
        <nav className="flex-1 space-y-1 p-3">
          {NAV.map((item) => {
            const active = item.to === "/" ? location === "/" : location.startsWith(item.to);
            const Icon = item.icon;
            return (
              <Link
                key={item.label}
                href={item.to}
                className={cn(
                  "flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors",
                  active ? "bg-[#28364b] text-white" : "text-slate-600 hover:bg-slate-100"
                )}
              >
                <Icon className="h-4 w-4" /> {item.label}
              </Link>
            );
          })}
        </nav>
        <div className="border-t border-slate-200 p-3 text-xs text-slate-400">
          Procurement module
        </div>
      </aside>

      <div className="flex flex-1 flex-col">
        <header className="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6">
          <div className="flex items-center gap-2">
            <img src="/logo.png" alt="Matria Marine" className="h-7 w-7 object-contain md:hidden" />
            <span className="text-sm text-slate-500">Procurement &amp; Quotations</span>
          </div>
          <div className="flex items-center gap-4">
            <span className="text-sm font-medium">{user?.name}</span>
            <button
              onClick={handleLogout}
              className="flex items-center gap-1 text-sm text-slate-600 hover:text-[#28364b]"
            >
              <LogOut className="h-4 w-4" /> Logout
            </button>
          </div>
        </header>
        <main className="flex-1 p-6">{children}</main>
      </div>
    </div>
  );
}
