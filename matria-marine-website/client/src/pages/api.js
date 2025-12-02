import axios from 'axios';

// 1. Configure your Backend URL here
// If using Laravel Sail or `php artisan serve`, it's usually localhost:8000
const API_URL = 'http://localhost:8000/api'; 

const api = axios.create({
  baseURL: API_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: true, // Important for SANCTUM cookie handling if needed
});

// 2. Request Interceptor: Auto-attach the token to every request
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('admin_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// 3. Define your API calls
export const authAPI = {
  login: (email, password) => api.post('/login', { email, password }),
  logout: () => api.post('/logout'),
  getUser: () => api.get('/user'),
};

export default api;