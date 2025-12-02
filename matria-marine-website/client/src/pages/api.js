import axios from 'axios';

// 1. Setup Axios
const api = axios.create({
  baseURL: 'http://localhost:8000', // Note: removed /api from base to hit /sanctum/csrf-cookie
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: true, // REQUIRED: Sends the secure cookies
});

// Add after creating the api instance
api.interceptors.request.use(
    (config) => {
      // Get XSRF token from cookie
      const token = document.cookie
        .split('; ')
        .find(row => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];
      
      if (token) {
        config.headers['X-XSRF-TOKEN'] = decodeURIComponent(token);
      }
      
      return config;
    },
    (error) => {
      return Promise.reject(error);
    }
  );
  
  api.interceptors.response.use(
    (response) => {
      console.log('Response:', response.status, response.config.url);
      return response;
    },
    (error) => {
      console.error('Response Error:', error.response?.status, error.config?.url);
      return Promise.reject(error);
    }
  );

// 2. Helper to get the API URL easily
const apiUrl = (path) => `/api${path}`;

// 3. Define API calls
export const authAPI = {
  // First, get the CSRF cookie protection
  getCsrf: () => api.get('/sanctum/csrf-cookie'),
  
  // Then login (no token needed, cookie handles it)
  login: (email, password) => api.post(apiUrl('/login'), { email, password }),
  
  logout: () => api.post(apiUrl('/logout')),
  getUser: () => api.get(apiUrl('/user')),
};

export default api;