import React from 'react';
import { ShipWheel } from 'lucide-react';

export default function LoadingScreen() {
  return (
    <div className="fixed inset-0 z-[60] flex flex-col items-center justify-center bg-white">
      <div className="relative">
        {/* The Wheel Icon with Spin Animation */}
        <ShipWheel 
          size={80} 
          className="text-[#28364b] animate-spin-slow" 
          strokeWidth={1.5}
        />
        {/* Center Accent */}
        <div className="absolute inset-0 m-auto w-4 h-4 bg-[#cebd88] rounded-full" />
      </div>
      
      <h2 className="mt-8 text-xl font-bold font-playfair text-[#28364b] animate-pulse">
        Matria Marine Services
      </h2>

      <style>{`
        @keyframes spin-slow {
          from { transform: rotate(0deg); }
          to { transform: rotate(360deg); }
        }
        .animate-spin-slow {
          animation: spin-slow 3s linear infinite;
        }
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&display=swap');
        .font-playfair { font-family: 'Playfair Display', serif; }
      `}</style>
    </div>
  );
}