import { ArrowUp, Mail, Phone, MapPin, Star } from 'lucide-react';

export default function Footer() {
  const scrollToTop = () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleServiceClick = (index) => {
    // 1. Force the Navigation bar to highlight 'Services' immediately
    window.dispatchEvent(new CustomEvent('force-nav-update', { detail: 'services' }));

    // 2. Scroll to the Services section
    const element = document.getElementById('services');
    if (element) {
      element.scrollIntoView({ behavior: 'smooth', block: 'center' });
      
      // 3. Dispatch event to highlight the specific card in Services.jsx (with delay for scroll)
      setTimeout(() => {
        window.dispatchEvent(new CustomEvent('trigger-service-highlight', { detail: index }));
      }, 500);
    }
  };

  const currentYear = new Date().getFullYear();

  return (
    <>
      <style>
        {`
          @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Raleway:wght@400&display=swap');
          .font-playfair { font-family: 'Playfair Display', serif; }
          .font-raleway { font-family: 'Raleway', sans-serif; }
        `}
      </style>

      <footer 
        className="relative bg-cover bg-center bg-no-repeat"
        style={{ 
          backgroundImage: "url('/matria2.jpg')", 
        }}
      >
        {/* Scroll to Top Button */}
        <button
          onClick={scrollToTop}
          className="fixed bottom-8 right-8 p-3 rounded-full bg-[#cebd88] text-gray-900 hover:bg-[#bca971] transition-all duration-300 transform hover:scale-110 shadow-lg z-40"
          title="Scroll to top"
        >
          <ArrowUp size={24} />
        </button>

        {/* Main Footer Content */}
        <div className="relative z-10 container mx-auto px-4 md:px-12 lg:px-24 py-16 text-white">
          
          {/* Top Grid: Info, Links, Services, Quality */}
          <div className="grid md:grid-cols-4 gap-8 mb-12">
            
            {/* 1. Company Info */}
            <div className="space-y-4">
              <div className="flex items-center space-x-3">
                <img 
                  src="/logo.png" 
                  alt="Matria Marine Logo" 
                  className="w-12 h-12"
                />
                <div className="flex-col flex items-center">
                  <span className="text-white font-bold text-2xl font-raleway tracking-wide leading-none">
                    M A T R I A
                  </span>
                  <span className="text-white font-raleway text-[10.5px] tracking-[0.2em] -mt-0.5">
                    MARINE SERVICES
                  </span>
                  <div className="flex space-x-0.5 text-[#cebd88] mt-1"> 
                    <Star size={12} fill="#cebd88" strokeWidth={0} />
                    <Star size={12} fill="#cebd88" strokeWidth={0} />
                    <Star size={12} fill="#cebd88" strokeWidth={0} />
                    <Star size={12} fill="#cebd88" strokeWidth={0} />
                    <Star size={12} fill="#cebd88" strokeWidth={0} />
                  </div>
                </div>
              </div>
              <p className="font-raleway text-base leading-relaxed text-gray-300">
                Your trusted partner for comprehensive maritime solutions and global port operations.
              </p>
            </div>

            {/* 2. Quick Links */}
            <div>
              <h4 className="font-playfair font-bold text-xl mb-4 text-white">Quick Links</h4>
              <ul className="space-y-2 text-base font-raleway text-gray-300">
                {[
                  { label: 'Home', id: 'home' },
                  { label: 'About', id: 'about' },
                  { label: 'Services', id: 'services' },
                  { label: 'Agency', id: 'agency' },
                ].map((link) => (
                  <li key={link.id}>
                    <button
                      onClick={() => {
                        const element = document.getElementById(link.id);
                        if (element) {
                          element.scrollIntoView({ behavior: 'smooth' });
                        }
                      }}
                      className="hover:text-[#cebd88] transition-colors"
                    >
                      {link.label}
                    </button>
                  </li>
                ))}
              </ul>
            </div>

            {/* 3. Services */}
            <div>
              <h4 className="font-playfair font-bold text-xl mb-4 text-white">Services</h4>
              <ul className="space-y-2 text-base font-raleway text-gray-300">
                {[
                  'Marine Services',
                  'Ship Supply',
                  'Agency',
                  'Complete Logistics',
                ].map((service, index) => (
                  <li key={index}>
                    <button 
                      onClick={() => handleServiceClick(index)}
                      className="hover:text-[#cebd88] transition-colors cursor-pointer text-left"
                    >
                      {service}
                    </button>
                  </li>
                ))}
              </ul>
            </div>

            {/* 4. Quality Assurance */}
            <div>
              <h4 className="font-playfair font-bold text-xl mb-4 text-white">Quality Assurance</h4>
              <div className="space-y-2 text-base font-raleway text-gray-300">
                <p>ISO Certified Services</p>
                <p>Safety & Environmental Standards Compliant</p>
                <p>Professional Team of Experts</p>
              </div>
            </div>

          </div>

          {/* Divider */}
          <div className="border-t border-gray-700/50 py-8">
            {/* Bottom Grid: Business Hours & Contact */}
            <div className="grid md:grid-cols-2 gap-8 text-base font-raleway text-gray-300">
              
              {/* Business Hours */}
              <div>
                <h5 className="font-semibold font-playfair text-lg mb-2 text-white">Business Hours</h5>
                <p className="leading-relaxed mb-2">
                   We maintain round-the-clock operational readiness to ensure your fleet never stops, providing seamless support whenever and wherever you need us.
                </p>
                <p className="font-bold text-lg" style={{ color: '#cebd88' }}>
                  24/7 Available
                </p>
              </div>

              {/* Contact Info */}
              <div>
                <h5 className="font-semibold font-playfair text-lg mb-2 text-white">Contact Us</h5>
                <div className="space-y-3">
                  <div className="flex items-start space-x-2">
                    <Phone size={16} className="text-[#cebd88] flex-shrink-0 mt-1" />
                    <span>+65 9129 5283</span>
                  </div>
                  <div className="flex items-start space-x-2">
                    <Mail size={16} className="text-[#cebd88] flex-shrink-0 mt-1" />
                    <span>sales@matriamarine.com</span>
                  </div>
                  <div className="flex items-start space-x-2">
                    <MapPin size={16} className="text-[#cebd88] flex-shrink-0 mt-1" />
                    <span>
                      239 #15-92, Lorong 1 Toa Payoh, Singapore 310239
                    </span>
                  </div>
                </div>
              </div>

            </div>
          </div>

          {/* Copyright */}
          <div className="border-t border-gray-700/50 pt-8 text-center text-sm font-raleway text-gray-400">
            <p>
              &copy; {currentYear} Matria Marine Services. All rights reserved. | Elevating Maritime Operations Worldwide
            </p>
          </div>
        </div>
      </footer>
    </>
  );
}