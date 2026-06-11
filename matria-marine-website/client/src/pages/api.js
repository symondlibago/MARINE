import axios from 'axios';

// const api = axios.create({
//   baseURL: 'https://marine-production.up.railway.app',
//   headers: {
//     'Accept': 'application/json',
//     'Content-Type': 'application/json',
//   }
// });

const api = axios.create({
  baseURL: 'http://localhost:8000',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  }
});

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
  
  login: async (email, password) => {
    const response = await api.post(apiUrl('/login'), { email, password });
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
};

// --- Vendor purchase-order acceptance magic link (public, NO auth) ---
export const poAcceptanceAPI = {
  get: (token) => api.get(apiUrl(`/po/${token}`)),
  accept: (token, payload) => api.post(apiUrl(`/po/${token}/accept`), payload),
};

// --- Phase 1 masters (staff, authenticated) ---
export const vendorsAPI = {
  list: (search) => api.get(apiUrl('/portal/vendors'), { params: search ? { search } : {} }),
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
  compare: (id) => api.get(apiUrl(`/portal/rfqs/${id}/compare`)),
  saveAwards: (id, awards) => api.post(apiUrl(`/portal/rfqs/${id}/awards`), { awards }),
  finish: (id) => api.post(apiUrl(`/portal/rfqs/${id}/finish`)),
  updateQuoteRate: (quoteId, exchange_rate) =>
    api.patch(apiUrl(`/portal/quotes/${quoteId}`), { exchange_rate }),
  itemSuggestions: (q) => api.get(apiUrl('/portal/item-suggestions'), { params: q ? { q } : {} }),
  vendorAwardPdf: (id, vendorId) =>
    api.get(apiUrl(`/portal/rfqs/${id}/vendors/${vendorId}/award-pdf`), { responseType: 'blob' }),
  reopen: (id) => api.post(apiUrl(`/portal/rfqs/${id}/reopen`)),
  summaryPdf: (id) => api.get(apiUrl(`/portal/rfqs/${id}/quotation-pdf`), { responseType: 'blob' }),
};

// --- Phase 3: purchase orders (awards -> POs to vendors) ---
export const purchaseOrdersAPI = {
  list: (params = {}) => api.get(apiUrl('/portal/purchase-orders'), { params }),
  get: (id) => api.get(apiUrl(`/portal/purchase-orders/${id}`)),
  generate: (rfqId) => api.post(apiUrl(`/portal/rfqs/${rfqId}/purchase-orders`)),
  update: (id, payload) => api.patch(apiUrl(`/portal/purchase-orders/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/purchase-orders/${id}`)),
  pdf: (id) => api.get(apiUrl(`/portal/purchase-orders/${id}/pdf`), { responseType: 'blob' }),
  email: (id) => api.post(apiUrl(`/portal/purchase-orders/${id}/email`)),
};

// --- Phase 4: purchase invoices + Navision/BC CSV export ---
export const purchaseInvoicesAPI = {
  list: (params = {}) => api.get(apiUrl('/portal/purchase-invoices'), { params }),
  get: (id) => api.get(apiUrl(`/portal/purchase-invoices/${id}`)),
  createFromPo: (poId) => api.post(apiUrl(`/portal/purchase-orders/${poId}/invoice`)),
  create: (payload) => api.post(apiUrl('/portal/purchase-invoices'), payload),
  update: (id, payload) => api.patch(apiUrl(`/portal/purchase-invoices/${id}`), payload),
  remove: (id) => api.delete(apiUrl(`/portal/purchase-invoices/${id}`)),
  export: (payload = {}) => api.post(apiUrl('/portal/purchase-invoices/export'), payload, { responseType: 'blob' }),
};

// --- Phase 5: reports / analytics ---
export const reportsAPI = {
  spend: (params = {}) => api.get(apiUrl('/portal/reports/spend'), { params }),
  vendors: (params = {}) => api.get(apiUrl('/portal/reports/vendors'), { params }),
  pipeline: (params = {}) => api.get(apiUrl('/portal/reports/pipeline'), { params }),
};

// --- Customers master (for outgoing documents) ---
export const customersAPI = {
  list: (search) => api.get(apiUrl('/portal/customers'), { params: search ? { search } : {} }),
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

// Convenience helper: is a staff auth token present?
export const isAuthenticated = () => !!localStorage.getItem('auth_token');

export default api;