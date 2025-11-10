import { ArrowUp, Mail, Phone, MapPin } from 'lucide-react';

export default function Footer() {
  const scrollToTop = () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const currentYear = new Date().getFullYear();

  return (
    <>
      {/* Import the Playfair Display and Raleway fonts */}
      <style>
        {`
          @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Raleway:wght@400&display=swap');
          
          .font-playfair {
            font-family: 'Playfair Display', serif;
          }
          
          .font-raleway {
            font-family: 'Raleway', sans-serif;
          }
        `}
      </style>

      <footer 
        className="relative bg-cover bg-center bg-no-repeat"
        style={{ 
          backgroundImage: "url('/matria2.jpg')", 
        }}
      >
        {/* Dark Overlay REMOVED */}

        {/* Scroll to Top Button - Styled to match new theme */}
        <button
          onClick={scrollToTop}
          className="fixed bottom-8 right-8 p-3 rounded-full bg-[#cebd88] text-gray-900 hover:bg-[#bca971] transition-all duration-300 transform hover:scale-110 shadow-lg z-40"
          title="Scroll to top"
        >
          <ArrowUp size={24} />
        </button>

        {/* Main Footer Content - Added relative z-10 and text-white */}
        <div className="relative z-10 container mx-auto px-4 py-16 text-white">
          <div className="grid md:grid-cols-4 gap-8 mb-12">
            
            {/* Company Info */}
            <div>
              <div className="flex items-center space-x-2 mb-4">
                <div 
                  className="w-10 h-10 rounded-lg flex items-center justify-center"
                  style={{ backgroundColor: '#cebd88' }} // Changed to gold
                >
                  <span className="text-gray-900 font-bold">MM</span>
                </div>
                <span className="font-bold text-xl font-playfair text-white">Matria Marine</span>
              </div>
              <p className="font-raleway text-base leading-relaxed text-gray-300">
                Your trusted partner for comprehensive maritime solutions and global port operations.
              </p>
            </div>

            {/* Quick Links */}
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

            {/* Services */}
            <div>
              <h4 className="font-playfair font-bold text-xl mb-4 text-white">Services</h4>
              <ul className="space-y-2 text-base font-raleway text-gray-300">
                {[
                  'Mechanical Repairs',
                  'Ship Spares Supply',
                  'Safety Equipment',
                  'Underwater Services',
                ].map((service, index) => (
                  <li key={index}>
                    <span className="hover:text-[#cebd88] transition-colors cursor-pointer">
                      {service}
                    </span>
                  </li>
                ))}
              </ul>
            </div>

            {/* Contact Info */}
            <div>
              <h4 className="font-playfair font-bold text-xl mb-4 text-white">Contact</h4>
              <div className="space-y-3 text-base font-raleway text-gray-300">
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

          {/* Divider - Changed border color */}
          <div className="border-t border-gray-700/50 py-8">
            <div className="grid md:grid-cols-3 gap-8 text-base font-raleway text-gray-300">
              {/* Business Hours */}
              <div>
                <h5 className="font-semibold font-playfair text-lg mb-2 text-white">Business Hours</h5>
                <p>Monday - Friday: 08:00 AM - 06:00 PM</p>
                <p>Saturday - Sunday: 10:00 AM - 04:00 PM</p>
                <p className="font-semibold mt-2" style={{ color: '#cebd88' }}>Emergency: 24/7 Available</p>
              </div>

              {/* Global Presence */}
              <div>
                <h5 className="font-semibold font-playfair text-lg mb-2 text-white">Global Presence</h5>
                <p>Asia Pacific: Singapore, Malaysia, Indonesia, Vietnam, Hong Kong, China, Thailand, South Korea, Japan, Philippines, Sri Lanka</p>
                <p className="mt-2">Expanding: South Africa, USA, Americas</p>
              </div>

              {/* Certifications */}
              <div>
                <h5 className="font-semibold font-playfair text-lg mb-2 text-white">Quality Assurance</h5>
                <p>ISO Certified Services</p>
                <p>Safety & Environmental Standards Compliant</p>
                <p>Professional Team of Experts</p>
              </div>
            </div>
          </div>

          {/* Copyright - Changed border color */}
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