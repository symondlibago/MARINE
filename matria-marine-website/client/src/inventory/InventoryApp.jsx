import { Redirect, Route, Switch } from "wouter";
import { useQuery } from "@tanstack/react-query";
import { authAPI, isAuthenticated } from "@/pages/api";
import { inventoryAPI } from "./api";
import { AccessProvider } from "./access";
import GeneralLayout from "./GeneralLayout";
import InventoryLayout from "./InventoryLayout";
import Vessels from "./Vessels";
import Inbox from "./Inbox";
import Dashboard from "./Dashboard";
import Items from "./Items";
import Requisition from "./Requisition";
import Receiving from "./Receiving";
import ReceiptPage from "./ReceiptPage";
import Withdrawals from "./Withdrawals";
import Periods from "./Periods";
import PeriodPage from "./PeriodPage";
import CrewRations from "./CrewRations";
import Suppliers from "./Suppliers";
import Reports from "./Reports";
import Settings from "./Settings";
import { ConfirmProvider } from "@/portal/ui/confirm";
import { PageLoader } from "@/portal/ui/Loading";

// The buying screens, shared with the procurement portal rather than copied.
// See GeneralRoutes for why they are mounted here a second time.
import Enquiries from "@/portal/Enquiries";
import EnquiryForm from "@/portal/EnquiryForm";
import EnquiryDetail from "@/portal/EnquiryDetail";
import CompareGrid from "@/portal/CompareGrid";
import Offers from "@/portal/Offers";
import OfferPage from "@/portal/OfferPage";
import PurchaseOrders from "@/portal/PurchaseOrders";
import PurchaseOrderDetail from "@/portal/PurchaseOrderDetail";
import DeliveryOrders from "@/portal/DeliveryOrders";
import DeliveryOrderPage from "@/portal/DeliveryOrderPage";
import Invoices from "@/portal/Invoices";
import InvoicePage from "@/portal/InvoicePage";
import CreditMemos from "@/portal/CreditMemos";
import Accounting from "@/portal/Accounting";
import Vendors from "@/portal/Vendors";

/**
 * Gate for the provision module.
 *
 * Two kinds of account reach it: the office, who sign in with the same login
 * they use for procurement, and a vessel's own master and cook, who reach this
 * and nothing else. Which of the two is decided by the server — `scope` on the
 * login payload — rather than by matching role strings here, so there is one
 * answer and the API is the one giving it.
 *
 * Renders nothing itself: what the signed-in account gets wrapped in is
 * decided below, because the office shell and a ship's shell are different
 * jobs.
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

  if (!user?.scope) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-2 text-center">
        <p className="text-lg font-semibold text-[#28364b]">Not authorized</p>
        <p className="text-sm text-slate-500">This area is for Matria staff and vessel crew.</p>
        <a href="/" className="mt-2 text-sm text-[#28364b] underline">
          Return to site
        </a>
      </div>
    );
  }

  return children(user);
}

/**
 * One vessel's own screens.
 *
 * The vessel sits in the path, so every screen below here — and every request
 * the API layer makes — is scoped to it without a single one having to pass it
 * along. Nesting the route means the screens' own links stay relative and
 * unchanged: /items under this shell is /inventory/v/3/items in the address bar.
 */
function VesselRoutes({ user, vesselId }) {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["inv", "vessel", vesselId],
    queryFn: async () => (await inventoryAPI.settings()).data.data,
    enabled: !!vesselId,
    retry: false,
    staleTime: 60_000,
  });

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <PageLoader label="Loading the vessel…" />
      </div>
    );
  }

  // A link to a vessel that has been archived, removed, or is simply somebody
  // else's should say so rather than render an empty set of screens. The
  // server's own wording is used: it knows which of the three it was.
  if (isError) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-2 px-6 text-center">
        <p className="text-lg font-semibold text-[#28364b]">Vessel unavailable</p>
        <p className="max-w-md text-sm text-slate-500">
          {error?.response?.data?.message || "That vessel is not on the fleet."}
        </p>
        {user.scope === "office" ? (
          <a href="/inventory/vessels" className="mt-2 text-sm text-[#28364b] underline">
            Back to the fleet
          </a>
        ) : user.vessel_id ? (
          <a href={`/inventory/v/${user.vessel_id}`} className="mt-2 text-sm text-[#28364b] underline">
            Back to {user.vessel?.name || "your vessel"}
          </a>
        ) : null}
      </div>
    );
  }

  return (
    <AccessProvider value={{ vessel: data.vessel, ...data.access }}>
      <InventoryLayout user={user} vessel={data.vessel}>
        <Switch>
          <Route path="/" component={Dashboard} />
          <Route path="/items" component={Items} />
          <Route path="/requisition" component={Requisition} />
          <Route path="/receiving" component={Receiving} />
          <Route path="/receiving/:id" component={ReceiptPage} />
          <Route path="/withdrawals" component={Withdrawals} />
          <Route path="/periods" component={Periods} />
          <Route path="/periods/:id" component={PeriodPage} />
          <Route path="/crew-rations" component={CrewRations} />
          {/* The price book is negotiated ashore. The menu already hides this
              from the ship, but a link pasted into a phone would otherwise
              still render the screen and leave it to the API to say no —
              which reads as a broken page rather than a closed door. */}
          {data.access.office && <Route path="/suppliers" component={Suppliers} />}
          <Route path="/reports" component={Reports} />
          <Route path="/settings" component={Settings} />
          <Route>
            <div className="p-8 text-slate-500">Vessel page not found.</div>
          </Route>
        </Switch>
      </InventoryLayout>
    </AccessProvider>
  );
}

/**
 * The office's own screens: the fleet, and the buying that serves it.
 *
 * The buying screens from "Enquiries" down are the procurement portal's own
 * components, mounted here a second time rather than copied. One Compare &
 * Award grid, one purchase order sequence, one set of books — two
 * implementations would mean two of everything to keep in step, and they would
 * not stay in step.
 *
 * What makes them the fleet's view is where they are: the API layer narrows
 * every portal request made under /inventory to the fleet's own documents, so
 * these same screens show the provisions here and the whole company there.
 *
 * The paths deliberately match the portal's. These screens link to each other
 * — an enquiry to its comparison grid, an offer to the invoice raised from it —
 * and because this app is nested at /inventory those links stay on the fleet's
 * pages without a single screen having to know it is mounted twice.
 */
function GeneralRoutes({ user }) {
  return (
    <GeneralLayout user={user}>
      <Switch>
        <Route path="/" component={Vessels} />
        <Route path="/vessels" component={Vessels} />
        <Route path="/requisitions" component={Inbox} />

        {/* Enquiries first: a vessel's requisition arrives here as one. */}
        <Route path="/enquiries" component={Enquiries} />
        <Route path="/enquiries/new" component={EnquiryForm} />
        <Route path="/enquiries/:id/edit" component={EnquiryForm} />
        <Route path="/enquiries/:id/compare" component={CompareGrid} />
        <Route path="/enquiries/:id" component={EnquiryDetail} />
        <Route path="/offers" component={Offers} />
        <Route path="/offers/:id" component={OfferPage} />
        <Route path="/purchase-orders" component={PurchaseOrders} />
        <Route path="/purchase-orders/:id" component={PurchaseOrderDetail} />
        <Route path="/delivery-orders" component={DeliveryOrders} />
        <Route path="/delivery-orders/:id" component={DeliveryOrderPage} />
        <Route path="/invoices" component={Invoices} />
        <Route path="/invoices/:id" component={InvoicePage} />
        <Route path="/credit-memos" component={CreditMemos} />
        <Route path="/accounting" component={Accounting} />
        <Route path="/vendors" component={Vendors} />

        <Route>
          <div className="p-8 text-slate-500">Inventory page not found.</div>
        </Route>
      </Switch>
    </GeneralLayout>
  );
}

/**
 * A crew account has no fleet to look at — it has a ship.
 *
 * Anything outside /v/:id is the office's view, so a master or a cook landing
 * there is sent to their own vessel rather than shown a page that would refuse
 * every request it made.
 */
function OwnVesselOnly({ user }) {
  if (user.vessel_id) {
    return <Redirect to={`/v/${user.vessel_id}`} replace />;
  }

  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-2 px-6 text-center">
      <p className="text-lg font-semibold text-[#28364b]">No vessel assigned</p>
      <p className="max-w-md text-sm text-slate-500">
        Your account has not been posted to a vessel yet. Ask the office to set it, then sign in again.
      </p>
    </div>
  );
}

export default function InventoryApp() {
  return (
    <ConfirmProvider>
      <RequireAuth>
        {(user) => (
          <Switch>
            {/* The id is taken here, at the nest, because inside it every
                path is relative and the vessel has dropped out of view. */}
            <Route path="/v/:vesselId" nest>
              {(params) => <VesselRoutes user={user} vesselId={params.vesselId} />}
            </Route>
            <Route>
              {user.scope === "vessel" ? <OwnVesselOnly user={user} /> : <GeneralRoutes user={user} />}
            </Route>
          </Switch>
        )}
      </RequireAuth>
    </ConfirmProvider>
  );
}
