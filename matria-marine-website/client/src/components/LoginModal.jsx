import React from 'react';
import { X, ShipWheel, Lock, User } from 'lucide-react';

export default function LoginModal({ isOpen, onClose }) {
  if (!isOpen) return null;

  const handleSubmit = (e) => {
    e.preventDefault();
    // TODO: Add your Backend/API Login logic here
    console.log("Login submitted");
  };

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-[#28364b]/60 backdrop-blur-sm animate-fade-in">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-8 relative mx-4 border border-[#cebd88]">
        
        {/* Close Button */}
        <button 
          onClick={onClose}
          className="absolute top-4 right-4 text-gray-400 hover:text-[#28364b] transition-colors"
        >
          <X size={24} />
        </button>

        {/* Header */}
        <div className="text-center mb-8">
          <div className="flex justify-center mb-4">
            <div className="p-3 bg-[#F1F0E8] rounded-full">
              <ShipWheel size={32} className="text-[#28364b]" />
            </div>
          </div>
          <h2 className="text-2xl font-bold font-playfair text-[#28364b]">Admin Portal</h2>
          <p className="font-raleway text-gray-500 text-sm mt-2">Authorized Personnel Only</p>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="space-y-2">
            <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">Username</label>
            <div className="relative">
              <User className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={18} />
              <input 
                type="text" 
                className="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] transition-all font-raleway"
                placeholder="Enter ID"
              />
            </div>
          </div>
          
          <div className="space-y-2">
            <label className="text-xs font-bold text-[#28364b] uppercase tracking-wider">Password</label>
            <div className="relative">
              <Lock className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={18} />
              <input 
                type="password" 
                className="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:border-[#cebd88] focus:ring-1 focus:ring-[#cebd88] transition-all font-raleway"
                placeholder="Enter Password"
              />
            </div>
          </div>

          <button 
            type="submit"
            className="w-full bg-[#28364b] text-white font-bold py-3 rounded-lg hover:bg-[#3c4a63] transition-all font-raleway shadow-lg hover:shadow-xl transform hover:-translate-y-0.5"
          >
            Access Dashboard
          </button>
        </form>
      </div>
    </div>
  );
}