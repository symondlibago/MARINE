import { lazy, Suspense } from "react";
import { Toaster } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClientProvider } from "@tanstack/react-query";
import { Route, Switch } from "wouter";
import ErrorBoundary from "./components/ErrorBoundary";
import { ThemeProvider } from "./contexts/ThemeContext";
import { queryClient } from "@/lib/queryClient";
import Home from "./pages/Home";
import NotFound from "@/pages/NotFound";
import { Analytics } from '@vercel/analytics/react';

// Code-split: the staff portal and the public vendor quote page are lazy-loaded
// so they stay out of the marketing bundle.
const PortalApp = lazy(() => import("@/portal/PortalApp"));
const QuotePage = lazy(() => import("@/pages/QuotePage"));
const PoAcceptancePage = lazy(() => import("@/pages/PoAcceptancePage"));

function RouteLoader() {
  return (
    <div className="flex min-h-screen items-center justify-center text-[#28364b]">
      Loading…
    </div>
  );
}

function Router() {
  return (
    <Switch>
      <Route path="/" component={Home} />

      {/* Public vendor quotation page (magic link, no login). */}
      <Route path="/quote/:token">
        {(params) => (
          <Suspense fallback={<RouteLoader />}>
            <QuotePage token={params.token} />
          </Suspense>
        )}
      </Route>

      {/* Public vendor purchase-order acceptance page (magic link, no login). */}
      <Route path="/po/:token">
        {(params) => (
          <Suspense fallback={<RouteLoader />}>
            <PoAcceptancePage token={params.token} />
          </Suspense>
        )}
      </Route>

      {/* Staff procurement portal (nested routes resolve under /portal). */}
      <Route path="/portal" nest>
        <Suspense fallback={<RouteLoader />}>
          <PortalApp />
        </Suspense>
      </Route>

      <Route path="/404" component={NotFound} />
      <Route component={NotFound} />
    </Switch>
  );
}

function App() {
  return (
    <ErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <ThemeProvider defaultTheme="light">
          <TooltipProvider>
            <Toaster />
            <Router />
            <Analytics />
          </TooltipProvider>
        </ThemeProvider>
      </QueryClientProvider>
    </ErrorBoundary>
  );
}

export default App;
