import { ArrowUp, Mail, Phone, MapPin } from 'lucide-react';

export default function Footer() {
  const scrollToTop = () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const currentYear = new Date().getFullYear();

  return (
    <footer className="bg-black text-white">
      {/* Scroll to Top Button */}
      <button
        onClick={scrollToTop}
        className="fixed bottom-8 right-8 p-3 rounded-full bg-[#00D9FF] text-black hover:bg-cyan-400 transition-all duration-300 transform hover:scale-110 shadow-lg z-40"
        title="Scroll to top"
      >
        <ArrowUp size={24} />
      </button>

      {/* Main Footer Content */}
      <div className="container mx-auto px-4 py-16">
        <div className="grid md:grid-cols-4 gap-8 mb-12">
          {/* Company Info */}
          <div>
            <div className="flex items-center space-x-2 mb-4">
              <div className="w-10 h-10 bg-[#00D9FF] rounded-lg flex items-center justify-center">
                <span className="text-black font-bold">MM</span>
              </div>
              <span className="font-bold text-lg">Matria Marine</span>
            </div>
            <p className="text-gray-400 text-sm leading-relaxed">
              Your trusted partner for comprehensive maritime solutions and global port operations.
            </p>
          </div>

          {/* Quick Links */}
          <div>
            <h4 className="font-bold text-lg mb-4">Quick Links</h4>
            <ul className="space-y-2 text-sm">
              {[
                { label: 'Home', id: 'home' },
                { label: 'About', id: 'about' },
                { label: 'Services', id: 'services' },
                { label: 'Projects', id: 'projects' },
              ].map((link) => (
                <li key={link.id}>
                  <button
                    onClick={() => {
                      const element = document.getElementById(link.id);
                      if (element) {
                        element.scrollIntoView({ behavior: 'smooth' });
                      }
                    }}
                    className="text-gray-400 hover:text-[#00D9FF] transition-colors"
                  >
                    {link.label}
                  </button>
                </li>
              ))}
            </ul>
          </div>

          {/* Services */}
          <div>
            <h4 className="font-bold text-lg mb-4">Services</h4>
            <ul className="space-y-2 text-sm">
              {[
                'Mechanical Repairs',
                'Ship Spares Supply',
                'Safety Equipment',
                'Underwater Services',
              ].map((service, index) => (
                <li key={index}>
                  <span className="text-gray-400 hover:text-[#00D9FF] transition-colors cursor-pointer">
                    {service}
                  </span>
                </li>
              ))}
            </ul>
          </div>

          {/* Contact Info */}
          <div>
            <h4 className="font-bold text-lg mb-4">Contact</h4>
            <div className="space-y-3 text-sm">
              <div className="flex items-start space-x-2">
                <Phone size={16} className="text-[#00D9FF] flex-shrink-0 mt-1" />
                <span className="text-gray-400">+65 9129 5283</span>
              </div>
              <div className="flex items-start space-x-2">
                <Mail size={16} className="text-[#00D9FF] flex-shrink-0 mt-1" />
                <span className="text-gray-400">sales@matriamarine.com</span>
              </div>
              <div className="flex items-start space-x-2">
                <MapPin size={16} className="text-[#00D9FF] flex-shrink-0 mt-1" />
                <span className="text-gray-400">
                  239 #15-92, Lorong 1 Toa Payoh, Singapore 310239
                </span>
              </div>
            </div>
          </div>
        </div>

        {/* Divider */}
        <div className="border-t border-gray-800 py-8">
          <div className="grid md:grid-cols-3 gap-8 text-sm text-gray-400">
            {/* Business Hours */}
            <div>
              <h5 className="font-semibold text-white mb-2">Business Hours</h5>
              <p>Monday - Friday: 08:00 AM - 06:00 PM</p>
              <p>Saturday - Sunday: 10:00 AM - 04:00 PM</p>
              <p className="text-[#00D9FF] mt-2">Emergency: 24/7 Available</p>
            </div>

            {/* Global Presence */}
            <div>
              <h5 className="font-semibold text-white mb-2">Global Presence</h5>
              <p>Asia Pacific: Singapore, Malaysia, Indonesia, Vietnam, Hong Kong, China, Thailand, South Korea, Japan, Philippines, Sri Lanka</p>
              <p className="mt-2">Expanding: South Africa, USA, Americas</p>
            </div>

            {/* Certifications */}
            <div>
              <h5 className="font-semibold text-white mb-2">Quality Assurance</h5>
              <p>ISO Certified Services</p>
              <p>Safety & Environmental Standards Compliant</p>
              <p>Professional Team of Experts</p>
            </div>
          </div>
        </div>

        {/* Copyright */}
        <div className="border-t border-gray-800 pt-8 text-center text-gray-400 text-sm">
          <p>
            &copy; {currentYear} Matria Marine Services. All rights reserved. | Elevating Maritime Operations Worldwide
          </p>
        </div>
      </div>
    </footer>
  );
}
