import axios from 'axios';

const api = axios.create({
  baseURL: 'https://marine-production.up.railway.app',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  }
});

// const api = axios.create({
//   baseURL: 'http://localhost:8000',
//   headers: {
//     'Accept': 'application/json',
//     'Content-Type': 'application/json',
//   }
// });

// Interceptor to attach the token from LocalStorage to every request
api.interceptors.request.use(config => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

const apiUrl = (path) => `/api${path}`;

export const authAPI = {
  // getCsrf is no longer required for Token Auth
  getCsrf: () => Promise.resolve(), 
  
  login: async (login, password) => {
    const response = await api.post(apiUrl('/login'), { login, password });
    if (response.data.access_token) {
        // Store token in LocalStorage
        localStorage.setItem('auth_token', response.data.access_token);
    }
    return response;
  },

  logout: async () => {
    const response = await api.post(apiUrl('/logout'));
    localStorage.removeItem('auth_token');
    sessionStorage.removeItem('user_name');
    sessionStorage.removeItem('user_role');
    return response;
  },

  getUser: () => api.get(apiUrl('/user')),
  updateProfile: (name) => api.post(apiUrl('/user/update-profile'), { name }),
  updatePassword: (current_password, password, password_confirmation) => 
    api.post(apiUrl('/user/update-password'), { current_password, password, password_confirmation }),
  initiateEmailUpdate: (new_email, password) => 
    api.post(apiUrl('/user/email/initiate'), { new_email, password }),
  completeEmailUpdate: (otp) => 
    api.post(apiUrl('/user/email/complete'), { otp }),
};

export const mediaAPI = {
  getAll: () => api.get(apiUrl('/media')),
  uploadItem: (formData) => api.post(apiUrl('/media/upload'), formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }),
  updateCategory: (id, data) => api.put(apiUrl(`/media/category/${id}`), data),
  deleteItem: (id) => api.delete(apiUrl(`/media/item/${id}`)),
  deleteCategory: (id) => api.delete(apiUrl(`/media/category/${id}`)),
};

export const updatesAPI = {
  getAll: () => api.get(apiUrl('/updates')),
  create: (formData) => api.post(apiUrl('/updates'), formData, {
    headers: { 'Content-Type': 'multipart/form-data' }
  }),
  update: (id, formData) => {
    formData.append('_method', 'PUT'); 
    return api.post(apiUrl(`/updates/${id}`), formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
    });
  },
  delete: (id) => api.delete(apiUrl(`/updates/${id}`)),
};

// --- Procurement portal (staff, authenticated via the existing Sanctum token) ---
export const portalAPI = {
  // Phase 0 smoke endpoint; real procurement endpoints arrive in Phase 2.
  ping: () => api.get(apiUrl('/portal/ping')),
};

// --- Vendor quote magic link (public, NO auth) ---
export const quoteAPI = {
  get: (token) => api.get(apiUrl(`/quote/${token}`)),
  submit: (token, payload) => api.post(apiUrl(`/quote/${token}`), payload),
  uploadFiles: (token, formData) =>
    api.post(apiUrl(`/quote/${token}/attachments`), formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
  deleteFile: (token, attachmentId) => api.delete(apiUrl(`/quote/${token}/attachments/${attachmentId}`)),
};

// --- Vendor purchase-order acceptance magic link (public, NO auth) ---
export const poAcceptanceAPI = {
  get: (token) => api.get(apiUrl(`/po/${token}`)),
  accept: (token, payload) => api.post(apiUrl(`/po/${token}/accept`), payload),
};

// --- Customer offer (quotation) acceptance magic link (public, NO auth) ---
export const offerAcceptanceAPI = {
  get: (token) => api.get(apiUrl(`/offer/${token}`)),
  accept: (token, payload) => api.post(apiUrl(`/offer/${token}/accept`), payload),
};

// --- Phase 1 masters (staff, authenticated) ---
export const vendorsAPI = {
  list: (params = {}) => api.get(apiUrl('/portal/vendors'), { params }),
  get: (id) => api.get(apiUrl(`/portal/vendors/${id}`)),
  create: (payload) => api.post(apiUrl('/portal/vendors'), payload),
  update: (id, payload) => api.put(apiUrl(`/portal/vendors/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/vendors/${id}`)),
};

// --- Phase 2: enquiries (RFQ spine) ---
export const rfqsAPI = {
  list: () => api.get(apiUrl('/portal/rfqs')),
  get: (id) => api.get(apiUrl(`/portal/rfqs/${id}`)),
  create: (payload) => api.post(apiUrl('/portal/rfqs'), payload),
  update: (id, payload) => api.put(apiUrl(`/portal/rfqs/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/rfqs/${id}`)),
  send: (id, payload) => api.post(apiUrl(`/portal/rfqs/${id}/send`), payload),
  sendExternal: (id, payload) => api.post(apiUrl(`/portal/rfqs/${id}/send-external`), payload),
  compare: (id) => api.get(apiUrl(`/portal/rfqs/${id}/compare`)),
  saveAwards: (id, awards) => api.post(apiUrl(`/portal/rfqs/${id}/awards`), { awards }),
  finish: (id) => api.post(apiUrl(`/portal/rfqs/${id}/finish`)),
  updateQuoteRate: (quoteId, exchange_rate) =>
    api.patch(apiUrl(`/portal/quotes/${quoteId}`), { exchange_rate }),
  updateQuoteCurrency: (quoteId, currency) =>
    api.patch(apiUrl(`/portal/quotes/${quoteId}`), { currency }),
  updateQuoteNumber: (quoteId, quotation_number) =>
    api.patch(apiUrl(`/portal/quotes/${quoteId}`), { quotation_number }),
  saveVendorPrices: (quoteId, items) =>
    api.patch(apiUrl(`/portal/quotes/${quoteId}/prices`), { items }),
  attachmentUrl: (quoteId, attachmentId) =>
    api.get(apiUrl(`/portal/quotes/${quoteId}/attachments/${attachmentId}`)),
  itemSuggestions: (q) => api.get(apiUrl('/portal/item-suggestions'), { params: q ? { q } : {} }),
  vendorAwardPdf: (id, vendorId) =>
    api.get(apiUrl(`/portal/rfqs/${id}/vendors/${vendorId}/award-pdf`), { responseType: 'blob' }),
  reopen: (id) => api.post(apiUrl(`/portal/rfqs/${id}/reopen`)),
  // Customer files on an enquiry (internal only — staff portal, never vendors)
  uploadFiles: (id, formData) =>
    api.post(apiUrl(`/portal/rfqs/${id}/attachments`), formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
  deleteFile: (id, attachmentId) => api.delete(apiUrl(`/portal/rfqs/${id}/attachments/${attachmentId}`)),
  fileUrl: (id, attachmentId) => api.get(apiUrl(`/portal/rfqs/${id}/attachments/${attachmentId}`)),
  enquiryVendorPdf: (id, vendorId) =>
    api.get(apiUrl(`/portal/rfqs/${id}/vendors/${vendorId}/enquiry-pdf`), { responseType: 'blob' }),
  summaryPdf: (id) => api.get(apiUrl(`/portal/rfqs/${id}/quotation-pdf`), { responseType: 'blob' }),
};

// --- Phase 3: purchase orders (awards -> POs to vendors) ---
export const purchaseOrdersAPI = {
  list: (params = {}) => api.get(apiUrl('/portal/purchase-orders'), { params }),
  get: (id) => api.get(apiUrl(`/portal/purchase-orders/${id}`)),
  generate: (rfqId, payload = {}) => api.post(apiUrl(`/portal/rfqs/${rfqId}/purchase-orders`), payload),
  update: (id, payload) => api.patch(apiUrl(`/portal/purchase-orders/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/purchase-orders/${id}`)),
  pdf: (id) => api.get(apiUrl(`/portal/purchase-orders/${id}/pdf`), { responseType: 'blob' }),
  finalInvoice: (id) => api.get(apiUrl(`/portal/purchase-orders/${id}/final-invoice`), { responseType: 'blob' }),
  email: (id) => api.post(apiUrl(`/portal/purchase-orders/${id}/email`)),
  // New flow: each PO gets its own delivery order
  createDeliveryOrder: (id) => api.post(apiUrl(`/portal/purchase-orders/${id}/delivery-order`)),
  uploadFiles: (id, formData) =>
    api.post(apiUrl(`/portal/purchase-orders/${id}/attachments`), formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
  deleteFile: (id, attachmentId) => api.delete(apiUrl(`/portal/purchase-orders/${id}/attachments/${attachmentId}`)),
  attachmentUrl: (id, attachmentId) => api.get(apiUrl(`/portal/purchase-orders/${id}/attachments/${attachmentId}`)),
};

// --- Return notes: goods returned to a vendor (credits the PO payable) ---
export const returnNotesAPI = {
  list: (params = {}) => api.get(apiUrl('/portal/return-notes'), { params }),
  get: (id) => api.get(apiUrl(`/portal/return-notes/${id}`)),
  saveForPo: (poId, payload) => api.post(apiUrl(`/portal/purchase-orders/${poId}/return-note`), payload),
  update: (id, payload) => api.patch(apiUrl(`/portal/return-notes/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/return-notes/${id}`)),
  pdf: (id) => api.get(apiUrl(`/portal/return-notes/${id}/pdf`), { responseType: 'blob' }),
  email: (id) => api.post(apiUrl(`/portal/return-notes/${id}/email`)),
};

// --- Offers: customer quotation with markup (built from an enquiry's awards) ---
export const offersAPI = {
  list: () => api.get(apiUrl('/portal/offers')),
  get: (id) => api.get(apiUrl(`/portal/offers/${id}`)),
  generate: (rfqId) => api.post(apiUrl(`/portal/rfqs/${rfqId}/offer`)),
  update: (id, payload) => api.patch(apiUrl(`/portal/offers/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/offers/${id}`)),
  pdf: (id) => api.get(apiUrl(`/portal/offers/${id}/pdf`), { responseType: 'blob' }),
  email: (id) => api.post(apiUrl(`/portal/offers/${id}/email`)),
};

// --- Delivery Orders: customer order + delivery address (from an accepted offer) ---
export const deliveryOrdersAPI = {
  list: () => api.get(apiUrl('/portal/delivery-orders')),
  get: (id) => api.get(apiUrl(`/portal/delivery-orders/${id}`)),
  generate: (offerId) => api.post(apiUrl(`/portal/offers/${offerId}/delivery-order`)),
  update: (id, payload) => api.patch(apiUrl(`/portal/delivery-orders/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/delivery-orders/${id}`)),
  pdf: (id) => api.get(apiUrl(`/portal/delivery-orders/${id}/pdf`), { responseType: 'blob' }),
  proforma: (id) => api.get(apiUrl(`/portal/delivery-orders/${id}/proforma`), { responseType: 'blob' }),
};

// --- Customer invoices (money in): from an offer, or a direct invoice ---
export const invoicesAPI = {
  list: () => api.get(apiUrl('/portal/invoices')),
  get: (id) => api.get(apiUrl(`/portal/invoices/${id}`)),
  createDirect: (payload = {}) => api.post(apiUrl('/portal/invoices'), payload),
  fromOffer: (offerId) => api.post(apiUrl(`/portal/offers/${offerId}/invoice`)),
  update: (id, payload) => api.patch(apiUrl(`/portal/invoices/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/invoices/${id}`)),
  pdf: (id) => api.get(apiUrl(`/portal/invoices/${id}/pdf`), { responseType: 'blob' }),
  email: (id) => api.post(apiUrl(`/portal/invoices/${id}/email`)),
};

// --- Staff management (super admin only) ---
export const usersAPI = {
  list: () => api.get(apiUrl('/portal/users')),
  create: (payload) => api.post(apiUrl('/portal/users'), payload),
  update: (id, payload) => api.patch(apiUrl(`/portal/users/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/users/${id}`)),
};

// --- Sent log (proof of document emails sent) ---
export const sentLogsAPI = {
  list: (params = {}) => api.get(apiUrl('/portal/sent-logs'), { params }),
};

// --- Reports / analytics ---
export const reportsAPI = {
  spend: (params = {}) => api.get(apiUrl('/portal/reports/spend'), { params }),
  vendors: (params = {}) => api.get(apiUrl('/portal/reports/vendors'), { params }),
  pipeline: (params = {}) => api.get(apiUrl('/portal/reports/pipeline'), { params }),
  accounting: (params = {}) => api.get(apiUrl('/portal/reports/accounting'), { params }),
};

// --- Operating expenses (business overhead: rent, salaries, software…) ---
export const operatingExpensesAPI = {
  list: () => api.get(apiUrl('/portal/operating-expenses')),
  create: (payload) => api.post(apiUrl('/portal/operating-expenses'), payload),
  update: (id, payload) => api.patch(apiUrl(`/portal/operating-expenses/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/operating-expenses/${id}`)),
};

// --- Customers master (for outgoing documents) ---
export const customersAPI = {
  list: (params = {}) => api.get(apiUrl('/portal/customers'), { params }),
  get: (id) => api.get(apiUrl(`/portal/customers/${id}`)),
  create: (payload) => api.post(apiUrl('/portal/customers'), payload),
  update: (id, payload) => api.put(apiUrl(`/portal/customers/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/customers/${id}`)),
};

// --- Documents: invoice / quotation / enquiry / delivery note (one template) ---
export const documentsAPI = {
  list: (params = {}) => api.get(apiUrl('/portal/documents'), { params }),
  get: (id) => api.get(apiUrl(`/portal/documents/${id}`)),
  nextNumber: (type) => api.get(apiUrl('/portal/documents/next-number'), { params: { type } }),
  create: (payload) => api.post(apiUrl('/portal/documents'), payload),
  update: (id, payload) => api.put(apiUrl(`/portal/documents/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/documents/${id}`)),
  pdf: (id) => api.get(apiUrl(`/portal/documents/${id}/pdf`), { responseType: 'blob' }),
};

// --- Live FX rates (server-side lookup, used by the Compare & Award grid) ---
export const fxAPI = {
  rates: (base) => api.get(apiUrl('/portal/fx-rates'), { params: { base } }),
};

// Convenience helper: is a staff auth token present?
export const isAuthenticated = () => !!localStorage.getItem('auth_token');

export default api;