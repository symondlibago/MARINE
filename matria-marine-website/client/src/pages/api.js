import axios from 'axios';

const api = axios.create({
  baseURL: 'https://marine-production.up.railway.app', 
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: true, 
});

// Help Axios find the CSRF token from Laravel's cookie
api.interceptors.request.use(config => {
  const token = document.cookie
      .split('; ')
      .find(row => row.startsWith('XSRF-TOKEN='))
      ?.split('=')[1];

  if (token) {
      config.headers['X-XSRF-TOKEN'] = decodeURIComponent(token);
  }
  return config;
});

const apiUrl = (path) => `/api${path}`;

export const authAPI = {
  getCsrf: () => api.get('/sanctum/csrf-cookie'),
  login: (email, password) => api.post(apiUrl('/login'), { email, password }),
  logout: () => api.post(apiUrl('/logout')),
  getUser: () => api.get(apiUrl('/user')),
  
  updateProfile: (name) => api.post(apiUrl('/user/update-profile'), { name }),
  updatePassword: (current_password, password, password_confirmation) => 
    api.post(apiUrl('/user/update-password'), { 
      current_password, 
      password, 
      password_confirmation 
    }),

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