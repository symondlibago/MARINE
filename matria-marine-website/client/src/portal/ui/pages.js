/**
 * Which portal screen a URL belongs to, and whether this user may open it.
 *
 * The server decides access (App\Support\PortalPages) and refuses anything not
 * granted. This only keeps the menu, the router and the dashboard in step with
 * that, so nobody is shown a link that would just answer "no access".
 */

// Route prefix -> page key. Longest match wins, so /credit-memos is not taken
// for something shorter. Routes not listed (the Dashboard) are open to all.
const ROUTE_PAGES = [
  ["/enquiries", "enquiries"],
  ["/offers", "offers"],
  ["/delivery-orders", "delivery_orders"],
  ["/invoices", "invoices"],
  ["/credit-memos", "credit_memos"],
  ["/purchase-orders", "purchase_orders"],
  ["/return-notes", "return_notes"],
  ["/accounting", "accounting"],
  ["/reports", "reports"],
  ["/statements", "statements"],
  ["/operating-expenses", "operating_expenses"],
  ["/customers", "customers"],
  ["/vendors", "vendors"],
  ["/sent-log", "sent_log"],
];

/** The page key for a portal path, or null when it is open to everyone. */
export function pageForPath(path = "") {
  const match = ROUTE_PAGES
    .filter(([prefix]) => path === prefix || path.startsWith(prefix + "/"))
    .sort((a, b) => b[0].length - a[0].length)[0];
  return match ? match[1] : null;
}

/**
 * May this user open that page? Super admins may open every one.
 *
 * No `pages` list at all means a server from before page access existed — the
 * frontend deploys on Vercel ahead of the backend on Railway, so for a few
 * minutes this code can be talking to it. That server restricts nothing, so
 * showing nothing would lock every admin out of a screen they can still use.
 * The server is what enforces access; this only mirrors it.
 */
export function canSee(user, page) {
  if (!page) return true;
  if (user?.role === "super_admin") return true;
  if (!Array.isArray(user?.pages)) return true;
  return user.pages.includes(page);
}
