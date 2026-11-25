import { useState, useEffect } from "react";
import { Link, useLocation } from "wouter";
import {
  Ship,
  ClipboardList,
  LogOut,
  PanelLeftClose,
  PanelLeftOpen,
  ArrowLeftRight,
  FileSearch,
  FileText,
  ShoppingCart,
  Truck,
  Receipt,
  Undo2,
  Calculator,
  Store,
} from "lucide-react";
import { authAPI } from "@/pages/api";
import { cn } from "@/lib/utils";

/**
 * The office's shell: the fleet as a whole, rather than one ship.
 *
 * Deliberately separate from the vessel shell. Standing here you are looking
 * across every vessel — which one needs ordering, whose requisition is waiting
 * — and the work is buying. Inside a vessel you are looking at one set of
 * stores, and the work is counting and cooking. Two jobs, two sidebars.
 */
/**
 * There is no separate dashboard here, and there was never meant to be two
 * links to one screen: the fleet IS the office's dashboard. Every card carries
 * the figures that ship's own dashboard shows — what is low, what to order,
 * what it comes to, whether anybody can sign in — so a roll-up above it would
 * only be the same numbers added together.
 *
 * `/` still lands on it, because old links and the procurement portal's way
 * back both point there, which is why Fleet claims both paths as its own.
 */
const NAV = [
  { label: "Fleet", to: "/vessels", icon: Ship, also: ["/"] },
  { label: "Requisitions", to: "/requisitions", icon: ClipboardList },
];

/**
 * The rest of the flow — the buying — and it lives HERE, on the fleet's own
 * pages. Following one of these does not leave for the procurement portal.
 *
 * They are the same components the portal uses, mounted a second time under
 * /inventory. Not copies: a vessel's requisition becomes an ordinary enquiry
 * and from there it is bought the way everything else is bought — one Compare &
 * Award screen, one purchase order sequence, one set of books. Two
 * implementations would mean two of everything to keep in step, and they would
 * not stay in step.
 *
 * What makes these the fleet's view rather than the whole company's is where
 * the browser is: the API layer narrows every portal request made under
 * /inventory to the fleet's own documents.
 *
 * The paths match the portal's on purpose. These screens link to each other
 * internally — an enquiry to its comparison grid, an offer to its invoice —
 * and because wouter nests this app at /inventory, those links stay inside the
 * fleet's pages without one of them having to know it is mounted twice.
 */
const FLOW = [
  { label: "Enquiries", to: "/enquiries", icon: FileSearch },
  { label: "Offers", to: "/offers", icon: FileText },
  { label: "Purchase Orders", to: "/purchase-orders", icon: ShoppingCart },
  { label: "Delivery Orders", to: "/delivery-orders", icon: Truck },
  { label: "Invoices", to: "/invoices", icon: Receipt },
  { label: "Credit Memos", to: "/credit-memos", icon: Undo2 },
  { label: "Accounting", to: "/accounting", icon: Calculator },
  { label: "Vendors", to: "/vendors", icon: Store },
];

export default function GeneralLayout({ user, children }) {
  const [location] = useLocation();

  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem("inventory_sidebar_collapsed") === "1";
    } catch {
      return false;
    }
  });

  useEffect(() => {
    try {
      localStorage.setItem("inventory_sidebar_collapsed", collapsed ? "1" : "0");
    } catch {
      /* ignore */
    }
  }, [collapsed]);

  const handleLogout = async () => {
    try {
      await authAPI.logout();
    } catch {
      // clear client state regardless
    }
    window.location.assign("/");
  };

  // `also` lists further paths the entry owns, matched exactly — "/" would
  // swallow every page under it if it were treated as a prefix.
  const isActive = (item) =>
    (item.exact ? location === item.to : location.startsWith(item.to)) ||
    (item.also ?? []).some((path) => location === path);

  return (
    <div className="flex min-h-screen bg-slate-50 text-[#28364b]">
      <aside
        className={cn(
          "sticky top-0 z-30 hidden h-screen shrink-0 flex-col self-start border-r border-slate-200 bg-white transition-[width] duration-200 md:flex",
          collapsed ? "w-16" : "w-64"
        )}
      >
        <div className={cn("flex h-16 items-center border-b border-slate-200", collapsed ? "justify-center px-2" : "gap-2.5 px-5")}>
          <img src="/logo.png" alt="Matria Marine" className="h-9 w-9 shrink-0 object-contain" />
          {!collapsed && (
            <div className="flex flex-col leading-none">
              <span className="text-sm font-bold tracking-[0.15em] text-[#28364b]">INVENTORY</span>
              <span className="text-[9px] tracking-[0.18em] text-slate-400">FLEET PROVISIONS</span>
            </div>
          )}
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto p-3">
          {NAV.map((item) => {
            const Icon = item.icon;
            return (
              <Link
                key={item.label}
                href={item.to}
                title={collapsed ? item.label : undefined}
                className={cn(
                  "flex items-center rounded-lg px-3 py-2 text-sm font-medium transition-colors",
                  collapsed ? "justify-center" : "gap-3",
                  isActive(item) ? "bg-[#28364b] text-white" : "text-slate-600 hover:bg-slate-100"
                )}
              >
                <Icon className="h-4 w-4 shrink-0" />
                {!collapsed && <span>{item.label}</span>}
              </Link>
            );
          })}

          {/* The buying — on these pages, not the portal's. */}
          <div className="pt-3">
            {!collapsed && (
              <p className="px-3 pb-1.5 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">
                Buying
              </p>
            )}
            {FLOW.map((item) => {
              const Icon = item.icon;

              return (
                <Link
                  key={item.label}
                  href={item.to}
                  title={collapsed ? item.label : undefined}
                  className={cn(
                    "flex items-center rounded-lg px-3 py-2 text-sm font-medium transition-colors",
                    collapsed ? "justify-center" : "gap-3",
                    location.startsWith(item.to) ? "bg-[#28364b] text-white" : "text-slate-600 hover:bg-slate-100"
                  )}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  {!collapsed && <span>{item.label}</span>}
                </Link>
              );
            })}
          </div>
        </nav>

        <div className="space-y-1 border-t border-slate-200 p-3">
          <a
            href="/portal"
            title={collapsed ? "Procurement portal" : undefined}
            className={cn(
              "flex items-center rounded-lg px-3 py-2 text-xs font-medium text-slate-500 transition-colors hover:bg-slate-100",
              collapsed ? "justify-center" : "gap-2"
            )}
          >
            <ArrowLeftRight className="h-4 w-4 shrink-0" />
            {!collapsed && <span>Procurement portal</span>}
          </a>
          <button
            onClick={() => setCollapsed((c) => !c)}
            title={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            className={cn(
              "flex w-full items-center rounded-lg px-3 py-2 text-xs font-medium text-slate-500 transition-colors hover:bg-slate-100",
              collapsed ? "justify-center" : "gap-2"
            )}
          >
            {collapsed ? (
              <PanelLeftOpen className="h-4 w-4 shrink-0" />
            ) : (
              <>
                <PanelLeftClose className="h-4 w-4 shrink-0" /> <span>Collapse</span>
              </>
            )}
          </button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6">
          <div className="flex min-w-0 items-center gap-2">
            <img src="/logo.png" alt="Matria Marine" className="h-7 w-7 object-contain md:hidden" />
            <Ship className="hidden h-4 w-4 text-[#cebd88] md:block" />
            <span className="truncate text-sm font-semibold text-[#28364b]">Fleet provisions</span>
          </div>
          <div className="flex items-center gap-4">
            <span className="hidden text-sm font-medium sm:inline">{user?.name}</span>
            <button onClick={handleLogout} className="flex items-center gap-1 text-sm text-slate-600 hover:text-[#28364b]">
              <LogOut className="h-4 w-4" /> Logout
            </button>
          </div>
        </header>

        {/* The same two groups on a phone, the buying after a divider. */}
        <div className="flex gap-1 overflow-x-auto border-b border-slate-200 bg-white px-3 py-2 md:hidden">
          {NAV.map((item) => (
            <Link
              key={item.label}
              href={item.to}
              className={cn(
                "whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors",
                isActive(item) ? "bg-[#28364b] text-white" : "text-slate-500 hover:bg-slate-100"
              )}
            >
              {item.label}
            </Link>
          ))}
          <span className="mx-1 w-px shrink-0 self-stretch bg-slate-200" />
          {FLOW.map((item) => (
            <Link
              key={item.label}
              href={item.to}
              className={cn(
                "whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors",
                location.startsWith(item.to) ? "bg-[#28364b] text-white" : "text-slate-500 hover:bg-slate-100"
              )}
            >
              {item.label}
            </Link>
          ))}
        </div>

        <main className="flex-1 p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
