import { Redirect } from "wouter";
import LoginModal from "@/components/LoginModal";
import { isAuthenticated } from "@/pages/api";

/**
 * Routes staff through the EXISTING SPA login (the shared LoginModal +
 * authAPI / Sanctum token). On success we send them to the portal dashboard.
 */
export default function PortalLogin() {
  // Already signed in -> straight to the dashboard (relative to /portal).
  if (isAuthenticated()) return <Redirect to="/" />;

  return (
    <div className="min-h-screen bg-slate-100">
      <LoginModal
        isOpen={true}
        onClose={() => window.location.assign("/")}
        onLoginSuccess={() => window.location.assign("/portal")}
      />
    </div>
  );
}
