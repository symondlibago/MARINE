/**
 * Vessel Provision Inventory — API layer.
 *
 * Every inventory endpoint lives here, separate from the procurement API in
 * pages/api.js. It reuses that file's axios instance on purpose: the base URL
 * and the auth-token interceptor stay defined in exactly one place, so the two
 * operations always talk to the same backend with the same login.
 */
import api from "@/pages/api";

const url = (path) => `/api/inventory${path}`;

/**
 * Which vessel the screen is looking at, read off the URL.
 *
 * Every inventory endpoint is scoped to one vessel, and the API refuses a
 * request that does not name one rather than guessing — with a fleet, guessing
 * means a cook counting against another ship's stock. The vessel sits in the
 * path (/inventory/v/3/items), so the path is the single source of truth and
 * no screen has to remember to pass it along.
 */
const vesselFromPath = () => {
  const match = window.location.pathname.match(/\/inventory\/v\/(\d+)(\/|$)/);
  return match ? Number(match[1]) : null;
};

// Attached once, and only to inventory calls: the procurement API shares this
// axios instance and has no business carrying a vessel. An explicit vessel_id
// passed by a caller wins, which is what the fleet page needs.
api.interceptors.request.use((config) => {
  if (!config.url?.startsWith("/api/inventory")) return config;

  const vesselId = vesselFromPath();
  if (vesselId) {
    config.params = { vessel_id: vesselId, ...(config.params || {}) };
  }

  return config;
});

export const inventoryAPI = {
  // Dashboard — budget, order value, balance, status counts
  dashboard: (params = {}) => api.get(url("/dashboard"), { params }),

  // The fleet — every vessel with the figures its card shows
  vessels: (params = {}) => api.get(url("/vessels"), { params }),
  createVessel: (payload) => api.post(url("/vessels"), payload),
  removeVessel: (id) => api.delete(url(`/vessels/${id}`)),
  restoreVessel: (id) => api.post(url(`/vessels/${id}/restore`)),
  stockCatalogue: (id) => api.post(url(`/vessels/${id}/catalogue`)),

  // Settings — the reference values behind every calculation, and what this
  // account may change about them
  settings: () => api.get(url("/settings")),
  saveSettings: (id, payload) => api.put(url(`/vessels/${id}`), payload),

  // The logins that sail on a vessel — her master and her cook. Office only.
  accounts: (vesselId) => api.get(url(`/vessels/${vesselId}/accounts`)),
  createAccount: (vesselId, payload) => api.post(url(`/vessels/${vesselId}/accounts`), payload),
  updateAccount: (vesselId, id, payload) => api.put(url(`/vessels/${vesselId}/accounts/${id}`), payload),
  removeAccount: (vesselId, id) => api.delete(url(`/vessels/${vesselId}/accounts/${id}`)),

  // Inventory — the main working screen
  categories: () => api.get(url("/categories")),
  items: (params = {}) => api.get(url("/items"), { params }),
  createItem: (payload) => api.post(url("/items"), payload),
  updateItem: (id, payload) => api.put(url(`/items/${id}`), payload),
  removeItem: (id) => api.delete(url(`/items/${id}`)),
  updateStock: (id, current_stock) => api.patch(url(`/items/${id}/stock`), { current_stock }),
  bulkStock: (items) => api.post(url("/items/stock"), { items }),
  bulkPrices: (items) => api.post(url("/items/prices"), { items }),

  // "Order some of this anyway" — what the cook wants beyond the calculation
  orderRequests: () => api.get(url("/order-requests")),
  requestOrder: (payload) => api.post(url("/order-requests"), payload),
  dropOrderRequest: (id) => api.delete(url(`/order-requests/${id}`)),

  // Who is aboard — the roster the ration report names
  crew: (params = {}) => api.get(url("/crew"), { params }),
  addCrew: (payload) => api.post(url("/crew"), payload),
  addCrewList: (payload) => api.post(url("/crew/bulk"), payload),
  updateCrew: (id, payload) => api.put(url(`/crew/${id}`), payload),
  removeCrew: (id) => api.delete(url(`/crew/${id}`)),

  // Fill the catalogue's blank prices from quotations already sent the vessel
  pricesFromOffers: (payload = {}) => api.post(url("/items/prices/from-offers"), payload),

  // Requisition — the calculated order and its saved history
  requisitionPreview: (params = {}) => api.get(url("/requisition/preview"), { params }),
  // The budget verdict on whatever is ticked, from the one class that decides.
  // Posted, though it asks rather than changes anything: what goes up is the
  // lines being ordered, because the ship has no prices to total herself, and a
  // few hundred of those do not belong in a query string.
  requisitionBudget: (payload = {}) => api.post(url("/requisition/budget"), payload),
  requisitions: () => api.get(url("/requisitions")),
  createRequisition: (payload) => api.post(url("/requisitions"), payload),
  requisition: (id) => api.get(url(`/requisitions/${id}`)),
  updateRequisition: (id, payload) => api.patch(url(`/requisitions/${id}`), payload),
  removeRequisition: (id) => api.delete(url(`/requisitions/${id}`)),

  // The approval chain: the cook sends it up, the master signs it off
  submitRequisition: (id) => api.post(url(`/requisitions/${id}/submit`)),
  pullBackRequisition: (id) => api.post(url(`/requisitions/${id}/pull-back`)),
  approveRequisition: (id) => api.post(url(`/requisitions/${id}/approve`)),
  declineRequisition: (id, reason) => api.post(url(`/requisitions/${id}/decline`), { reason }),

  // Every approved order across the fleet — the office's own list
  requisitionInbox: (params = {}) => api.get(url("/requisitions/inbox"), { params }),
  // The handover: an approved order becomes an ordinary enquiry in the portal
  toEnquiry: (id) => api.post(url(`/requisitions/${id}/to-enquiry`)),
  requisitionPdf: (id) => api.get(url(`/requisitions/${id}/pdf`), { responseType: "blob" }),
  receivingSheet: (id) => api.get(url(`/requisitions/${id}/receiving-sheet`)),

  // Receiving — the only thing that raises stock
  receipts: () => api.get(url("/receipts")),
  receipt: (id) => api.get(url(`/receipts/${id}`)),
  createReceipt: (payload) => api.post(url("/receipts"), payload),
  updateReceipt: (id, payload) => api.put(url(`/receipts/${id}`), payload),
  confirmReceipt: (id) => api.post(url(`/receipts/${id}/confirm`)),
  reopenReceipt: (id) => api.post(url(`/receipts/${id}/reopen`)),
  removeReceipt: (id) => api.delete(url(`/receipts/${id}`)),

  // Suppliers — price comparison, office use
  suppliers: () => api.get(url("/suppliers")),
  createSupplier: (payload) => api.post(url("/suppliers"), payload),
  updateSupplier: (id, payload) => api.put(url(`/suppliers/${id}`), payload),
  removeSupplier: (id) => api.delete(url(`/suppliers/${id}`)),
  comparison: (params = {}) => api.get(url("/suppliers/comparison"), { params }),
  savePrices: (id, payload) => api.post(url(`/suppliers/${id}/prices`), payload),
  applyPrices: (id) => api.post(url(`/suppliers/${id}/apply-prices`)),

  // Withdrawals — what left the store, costed against its own batches
  withdrawals: (params = {}) => api.get(url("/withdrawals"), { params }),
  withdraw: (payload) => api.post(url("/withdrawals"), payload),
  undoWithdrawal: (id) => api.delete(url(`/withdrawals/${id}`)),

  // The batches on board: what each cost, and when it goes off
  stockLots: (params = {}) => api.get(url("/stock-lots"), { params }),

  // Supply periods — the stocktake that makes consumption measurable
  periods: () => api.get(url("/periods")),
  period: (id) => api.get(url(`/periods/${id}`)),
  openPeriod: (payload) => api.post(url("/periods"), payload),
  saveCounts: (id, lines) => api.post(url(`/periods/${id}/counts`), { lines }),
  closePeriod: (id, payload) => api.post(url(`/periods/${id}/close`), payload),
  removePeriod: (id) => api.delete(url(`/periods/${id}`)),

  // Crew rations — the daily meal count behind the victualling budget
  crewRations: (params = {}) => api.get(url("/crew-rations"), { params }),
  saveCrewRations: (days) => api.post(url("/crew-rations"), { days }),
  rationsReport: (params = {}) => api.get(url("/crew-rations/report"), { params }),
  rationInvoice: (params = {}) => api.get(url("/crew-rations/invoice"), { params }),
  rationInvoicePdf: (params = {}) =>
    api.get(url("/crew-rations/invoice/pdf"), { params, responseType: "blob" }),

  // Reports — monthly budget vs order value, spend by category
  monthly: (params = {}) => api.get(url("/reports/monthly"), { params }),
  byCategory: (params = {}) => api.get(url("/reports/by-category"), { params }),
};

export default inventoryAPI;
