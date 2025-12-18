import axios from 'axios';

const api = axios.create({
  baseURL: 'https://marine-production.up.railway.app',
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

export default api;