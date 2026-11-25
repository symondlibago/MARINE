import { useState, useEffect } from "react";
import { Link, useLocation } from "wouter";
import {
  LayoutDashboard,
  Boxes,
  ClipboardList,
  PackageCheck,
  MinusCircle,
  Store,
  CalendarRange,
  UtensilsCrossed,
  BarChart3,
  Settings as SettingsIcon,
  LogOut,
  PanelLeftClose,
  PanelLeftOpen,
  Ship,
  ArrowLeftRight,
} from "lucide-react";
import { authAPI } from "@/pages/api";
import { cn } from "@/lib/utils";
import { allows, useAccess } from "./access";

/**
 * One vessel's shell. Deliberately separate from PortalLayout: once somebody
 * is inside a ship's stores they see that ship's navigation only.
 *
 * `needs` marks the entries that are not simply the ship's work. Everything
 * without one is what the cook is here to do — counting, withdrawing,
 * receiving, asking for the next supply. The price book is negotiated ashore,
 * and only the master changes how his vessel is provisioned. The same rules
 * refuse the requests behind these screens, so a link hidden here is a link
 * that would not have worked.
 */
const NAV = [
  { label: "Dashboard", to: "/", icon: LayoutDashboard },
  { label: "Inventory", to: "/items", icon: Boxes },
  { label: "Requisition", to: "/requisition", icon: ClipboardList },
  { label: "Receiving", to: "/receiving", icon: PackageCheck },
  { label: "Withdrawals", to: "/withdrawals", icon: MinusCircle },
  { label: "Supply Periods", to: "/periods", icon: CalendarRange },
  { label: "Crew Rations", to: "/crew-rations", icon: UtensilsCrossed },
  { label: "Suppliers", to: "/suppliers", icon: Store, needs: "office" },
  { label: "Reports", to: "/reports", icon: BarChart3 },
  { label: "Settings", to: "/settings", icon: SettingsIcon, needs: "master" },
];

export default function InventoryLayout({ user, vessel, children }) {
  const [location] = useLocation();
  const access = useAccess();
  const nav = NAV.filter((item) => allows(access, item.needs));

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
              <span className="text-[9px] tracking-[0.18em] text-slate-400">VESSEL PROVISIONS</span>
            </div>
          )}
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto p-3">
          {nav.map((item) => {
            const active = item.to === "/" ? location === "/" : location.startsWith(item.to);
            const Icon = item.icon;
            return (
              <Link
                key={item.label}
                href={item.to}
                title={collapsed ? item.label : undefined}
                className={cn(
                  "flex items-center rounded-lg px-3 py-2 text-sm font-medium transition-colors",
                  collapsed ? "justify-center" : "gap-3",
                  active ? "bg-[#28364b] text-white" : "text-slate-600 hover:bg-slate-100"
                )}
              >
                <Icon className="h-4 w-4 shrink-0" />
                {!collapsed && <span>{item.label}</span>}
              </Link>
            );
          })}
        </nav>

        <div className="space-y-1 border-t border-slate-200 p-3">
          {/* Only the office has anywhere else to go: a master and a cook have
              one ship, and procurement is not their operation. */}
          {access.office && (
            <>
              {/* Out of this ship's stores and back to the fleet. */}
              <a
                href="/inventory/vessels"
                title={collapsed ? "All vessels" : undefined}
                className={cn(
                  "flex items-center rounded-lg px-3 py-2 text-xs font-medium text-slate-500 transition-colors hover:bg-slate-100",
                  collapsed ? "justify-center" : "gap-2"
                )}
              >
                <Ship className="h-4 w-4 shrink-0" />
                {!collapsed && <span>All vessels</span>}
              </a>
              {/* The two operations are separate, but one login covers both. */}
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
            </>
          )}
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
            <span className="truncate text-sm font-semibold text-[#28364b]">{vessel?.name || "Vessel Provisions"}</span>
            {vessel?.crew_size ? (
              <span className="hidden text-xs text-slate-400 sm:inline">
                · {vessel.crew_size} crew · {vessel.supply_period_days}-day supply
              </span>
            ) : null}
          </div>
          <div className="flex items-center gap-4">
            <div className="hidden text-right leading-tight sm:block">
              <span className="block text-sm font-medium">{user?.name}</span>
              {/* Who the module thinks you are. Worth saying out loud on a
                  ship where the master and the cook share a screen. */}
              {access.rank && <span className="block text-[11px] text-slate-400">{access.rank}</span>}
            </div>
            <button onClick={handleLogout} className="flex items-center gap-1 text-sm text-slate-600 hover:text-[#28364b]">
              <LogOut className="h-4 w-4" /> Logout
            </button>
          </div>
        </header>

        {/* Mobile navigation — crew often work from a phone or tablet. */}
        <div className="flex gap-1 overflow-x-auto border-b border-slate-200 bg-white px-3 py-2 md:hidden">
          {nav.map((item) => {
            const active = item.to === "/" ? location === "/" : location.startsWith(item.to);
            return (
              <Link
                key={item.label}
                href={item.to}
                className={cn(
                  "whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors",
                  active ? "bg-[#28364b] text-white" : "text-slate-500 hover:bg-slate-100"
                )}
              >
                {item.label}
              </Link>
            );
          })}
        </div>

        <main className="flex-1 p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
