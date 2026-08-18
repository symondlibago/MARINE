import { useState, useEffect } from "react";
import { Link, useLocation } from "wouter";
import {
  CalendarRange,
  Users,
  Settings as SettingsIcon,
  LogOut,
  PanelLeftClose,
  PanelLeftOpen,
  Wallet,
  ArrowLeftRight,
} from "lucide-react";
import { authAPI } from "@/pages/api";
import { cn } from "@/lib/utils";

/**
 * The payroll module's own shell. Deliberately separate from PortalLayout:
 * once you are inside Payroll you see payroll navigation only, with a single
 * link back to the procurement portal.
 */
const NAV = [
  { label: "Payroll Months", to: "/", icon: CalendarRange },
  { label: "Employees", to: "/employees", icon: Users },
  { label: "Settings", to: "/settings", icon: SettingsIcon },
];

export default function PayrollLayout({ user, company, children }) {
  const [location] = useLocation();

  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem("payroll_sidebar_collapsed") === "1";
    } catch {
      return false;
    }
  });

  useEffect(() => {
    try {
      localStorage.setItem("payroll_sidebar_collapsed", collapsed ? "1" : "0");
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
              <span className="text-sm font-bold tracking-[0.15em] text-[#28364b]">PAYROLL</span>
              <span className="text-[9px] tracking-[0.18em] text-slate-400">SALARIES &amp; CPF</span>
            </div>
          )}
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto p-3">
          {NAV.map((item) => {
            const active = item.to === "/" ? location === "/" || location.startsWith("/runs") : location.startsWith(item.to);
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
          {/* The operations are separate, but one login covers them all. */}
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
            <Wallet className="hidden h-4 w-4 text-[#cebd88] md:block" />
            <span className="truncate text-sm font-semibold text-[#28364b]">{company?.company_name || "Payroll"}</span>
            {company?.currency ? (
              <span className="hidden text-xs text-slate-400 sm:inline">· {company.currency}</span>
            ) : null}
          </div>
          <div className="flex items-center gap-4">
            <span className="hidden text-sm font-medium sm:inline">{user?.name}</span>
            <button onClick={handleLogout} className="flex items-center gap-1 text-sm text-slate-600 hover:text-[#28364b]">
              <LogOut className="h-4 w-4" /> Logout
            </button>
          </div>
        </header>

        {/* Mobile navigation */}
        <div className="flex gap-1 overflow-x-auto border-b border-slate-200 bg-white px-3 py-2 md:hidden">
          {NAV.map((item) => {
            const active = item.to === "/" ? location === "/" || location.startsWith("/runs") : location.startsWith(item.to);
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
