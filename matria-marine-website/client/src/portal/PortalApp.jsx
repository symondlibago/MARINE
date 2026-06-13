import { Route, Switch, Redirect } from "wouter";
import { useQuery } from "@tanstack/react-query";
import { authAPI, isAuthenticated } from "@/pages/api";
import PortalLayout from "./PortalLayout";
import PortalLogin from "./PortalLogin";
import Dashboard from "./Dashboard";
import Vendors from "./Vendors";
import Enquiries from "./Enquiries";
import EnquiryForm from "./EnquiryForm";
import EnquiryDetail from "./EnquiryDetail";
import CompareGrid from "./CompareGrid";
import Offers from "./Offers";
import OfferPage from "./OfferPage";
import DeliveryOrders from "./DeliveryOrders";
import DeliveryOrderPage from "./DeliveryOrderPage";
import PurchaseOrders from "./PurchaseOrders";
import PurchaseOrderDetail from "./PurchaseOrderDetail";
import Reports from "./Reports";
import Customers from "./Customers";
import Documents from "./Documents";
import DocumentForm from "./DocumentForm";
import { ConfirmProvider } from "./ui/confirm";
import { PageLoader } from "./ui/Loading";

const STAFF_ROLES = ["admin", "staff"];

/**
 * Gate for the portal: requires a Sanctum token, verifies it against
 * /api/user, and checks the staff role. Unauthenticated -> /portal/login.
 */
function RequireAuth({ children }) {
  const hasToken = isAuthenticated();

  const { data, isLoading, isError } = useQuery({
    queryKey: ["me"],
    queryFn: async () => (await authAPI.getUser()).data,
    enabled: hasToken,
    retry: false,
  });

  if (!hasToken) return <Redirect to="/login" />;

  if (isLoading) {
    return <div className="flex min-h-screen items-center justify-center"><PageLoader label="Checking access…" /></div>;
  }

  if (isError) {
    localStorage.removeItem("auth_token");
    return <Redirect to="/login" />;
  }

  const user = data?.data ?? data?.user ?? data;

  if (!STAFF_ROLES.includes(user?.role)) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-2 text-center">
        <p className="text-lg font-semibold text-[#28364b]">Not authorized</p>
        <p className="text-sm text-slate-500">This area is for Matria staff only.</p>
        <a href="/" className="mt-2 text-sm text-[#28364b] underline">Return to site</a>
      </div>
    );
  }

  return <PortalLayout user={user}>{children}</PortalLayout>;
}

export default function PortalApp() {
  return (
    <ConfirmProvider>
      <Switch>
        <Route path="/login" component={PortalLogin} />
        <Route>
          <RequireAuth>
            <Switch>
              <Route path="/" component={Dashboard} />
              <Route path="/vendors" component={Vendors} />
              <Route path="/enquiries" component={Enquiries} />
              <Route path="/enquiries/new" component={EnquiryForm} />
              <Route path="/enquiries/:id/edit" component={EnquiryForm} />
              <Route path="/enquiries/:id/compare" component={CompareGrid} />
              <Route path="/enquiries/:id" component={EnquiryDetail} />
              <Route path="/offers" component={Offers} />
              <Route path="/offers/:id" component={OfferPage} />
              <Route path="/delivery-orders" component={DeliveryOrders} />
              <Route path="/delivery-orders/:id" component={DeliveryOrderPage} />
              <Route path="/purchase-orders" component={PurchaseOrders} />
              <Route path="/purchase-orders/:id" component={PurchaseOrderDetail} />
              <Route path="/reports" component={Reports} />
              <Route path="/documents" component={Documents} />
              <Route path="/documents/new" component={DocumentForm} />
              <Route path="/documents/:id" component={DocumentForm} />
              <Route path="/customers" component={Customers} />
              <Route>
                <div className="p-8 text-slate-500">Portal page not found.</div>
              </Route>
            </Switch>
          </RequireAuth>
        </Route>
      </Switch>
    </ConfirmProvider>
  );
}
