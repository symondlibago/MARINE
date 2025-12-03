import React, { useState } from 'react';
import { X, ShipWheel, Lock, User, Loader2, AlertCircle } from 'lucide-react';
import { authAPI } from '../pages/api'; // Ensure this path matches your file structure

export default function LoginModal({ isOpen, onClose, onLoginSuccess }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');

  if (!isOpen) return null;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setIsLoading(true);
  
    try {
      // 1. Get CSRF cookie
      console.log('Getting CSRF cookie...');
      await authAPI.getCsrf();
      
      // 2. Small delay to ensure cookie is set
      await new Promise(resolve => setTimeout(resolve, 100));
      
      // 3. Check if cookie was set
      console.log('Cookies:', document.cookie);
      
      // 4. Login
      console.log('Attempting login...');
      const response = await authAPI.login(email, password);
      
      const user = response.data.user;
      sessionStorage.setItem('user_name', user.name);
  
      console.log("Login Success:", user);
      
      // --- UPDATE START ---
      // Notify parent component (Navigation) that login succeeded
      if (onLoginSuccess) {
        onLoginSuccess();
      } else {
        onClose(); // Fallback if prop isn't passed
      }
      // --- UPDATE END ---

      alert(`Welcome back, ${user.name}!`);
  
    } catch (err) {
      console.error("Login Failed", err);
      console.error("Response:", err.response);
      if (err.response && err.response.data) {
        setError(err.response.data.message || 'Invalid credentials');
      } else {
        setError('Server error. Ensure Backend is running.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-[#28364b]/60 backdrop-blur-sm animate-fade-in">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-8 relative mx-4 border border-[#cebd88]">
        
        <button onClick={onClose} className="absolute top-4 right-4 text-gray-400 hover:text-[#28364b]">
          <X size={24} />
        </button>

        <div className="text-center mb-8">
          <div className="flex justify-center mb-4">
            <div className="p-3 bg-[#F1F0E8] rounded-full">
              <ShipWheel size={32} className="text-[#28364b]" />
            </div>
          </div>
          <h2 className="text-2xl font-bold font-playfair text-[#28364b]">Admin Portal</h2>
          <p className="font-raleway text-gray-500 text-sm mt-2">Authorized Personnel Only</p>
        </div>

        {error && (
          <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg flex items-center gap-2 text-red-600 text-sm font-raleway">
            <AlertCircle size={16} />
            <span>{error}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="space-y-2">
            <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">Email Address</label>
            <div className="relative">
              <User className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={18} />
              <input 
                type="email" 
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] transition-all font-raleway"
                placeholder="admin@matriamarine.com"
                required
              />
            </div>
          </div>
          
          <div className="space-y-2">
            <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">Password</label>
            <div className="relative">
              <Lock className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={18} />
              <input 
                type="password" 
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] transition-all font-raleway"
                placeholder="Enter Password"
                required
              />
            </div>
          </div>

          <button 
            type="submit"
            disabled={isLoading}
            className="w-full bg-[#28364b] text-white font-bold py-3 rounded-lg hover:bg-[#3c4a63] transition-all font-raleway shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 flex justify-center items-center gap-2 disabled:opacity-70"
          >
            {isLoading ? <><Loader2 size={18} className="animate-spin" /> Verifying...</> : 'Access Dashboard'}
          </button>
        </form>
      </div>
    </div>
  );
}