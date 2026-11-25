import { Redirect } from "wouter";
import LoginModal from "@/components/LoginModal";
import { isAuthenticated } from "@/pages/api";
import { homeFor } from "@/lib/home";

/**
 * Routes staff through the EXISTING SPA login (the shared LoginModal +
 * authAPI / Sanctum token).
 *
 * Where they land depends on the account: the office gets the portal
 * dashboard, a vessel's master or cook gets their own ship's stores. This is
 * the only login screen, so it serves both.
 */
export default function PortalLogin() {
  // Already signed in -> straight to the dashboard (relative to /portal).
  // A crew account that lands there is moved on by the portal's own gate,
  // which has the account to hand and this does not.
  if (isAuthenticated()) return <Redirect to="/" />;

  return (
    <div className="min-h-screen bg-slate-100">
      <LoginModal
        isOpen={true}
        onClose={() => window.location.assign("/")}
        onLoginSuccess={(user) => window.location.assign(homeFor(user))}
      />
    </div>
  );
}
