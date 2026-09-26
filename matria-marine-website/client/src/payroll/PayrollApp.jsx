import { Route, Switch } from "wouter";
import { useQuery } from "@tanstack/react-query";
import { authAPI, isAuthenticated } from "@/pages/api";
import { payrollAPI } from "./api";
import PayrollLayout from "./PayrollLayout";
import Runs from "./Runs";
import RunPage from "./RunPage";
import Employees from "./Employees";
import Settings from "./Settings";
import { ConfirmProvider } from "@/portal/ui/confirm";
import { PageLoader } from "@/portal/ui/Loading";
import { canSee } from "@/portal/ui/pages";

const STAFF_ROLES = ["super_admin", "admin"];

/**
 * Same gate as the procurement portal — one staff login covers every module.
 * Unauthenticated users are sent to the portal login.
 */
function RequireAuth({ children }) {
  const hasToken = isAuthenticated();

  const { data, isLoading, isError } = useQuery({
    queryKey: ["me"],
    queryFn: async () => (await authAPI.getUser()).data,
    enabled: hasToken,
    retry: false,
  });

  const user = data?.data ?? data?.user ?? data;
  // Staff, AND given Payroll. The server refuses every payroll request from
  // anyone else regardless; this only stops the screen loading around them.
  const isStaff = STAFF_ROLES.includes(user?.role);
  const allowed = isStaff && canSee(user, "payroll");

  // The company header sits on every screen, so it is fetched once here.
  const { data: company } = useQuery({
    queryKey: ["pay", "settings"],
    queryFn: async () => (await payrollAPI.settings()).data.data.settings,
    enabled: !!allowed,
    staleTime: 60_000,
  });

  if (!hasToken) {
    window.location.assign("/portal/login");
    return null;
  }

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <PageLoader label="Checking access…" />
      </div>
    );
  }

  if (isError) {
    localStorage.removeItem("auth_token");
    window.location.assign("/portal/login");
    return null;
  }

  if (!allowed) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-2 text-center">
        <p className="text-lg font-semibold text-[#28364b]">{isStaff ? "No access to Payroll" : "Not authorized"}</p>
        <p className="text-sm text-slate-500">
          {isStaff
            ? "Your account hasn't been given Payroll. Ask a super admin to add it under Manage Staff."
            : "This area is for Matria staff only."}
        </p>
        <a href={isStaff ? "/portal" : "/"} className="mt-2 text-sm text-[#28364b] underline">
          {isStaff ? "Back to the portal" : "Return to site"}
        </a>
      </div>
    );
  }

  return (
    <PayrollLayout user={user} company={company}>
      {children}
    </PayrollLayout>
  );
}

export default function PayrollApp() {
  return (
    <ConfirmProvider>
      <RequireAuth>
        <Switch>
          <Route path="/" component={Runs} />
          <Route path="/runs/:id" component={RunPage} />
          <Route path="/employees" component={Employees} />
          <Route path="/settings" component={Settings} />
          <Route>
            <div className="p-8 text-slate-500">Payroll page not found.</div>
          </Route>
        </Switch>
      </RequireAuth>
    </ConfirmProvider>
  );
}
