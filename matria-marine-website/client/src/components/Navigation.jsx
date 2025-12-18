import { useState, useEffect } from 'react';
import { Menu, X, Star, ShipWheel, LogOut } from 'lucide-react'; 
import LoginModal from './LoginModal'; 
import ProfileModal from './ProfileModal';

export default function Navigation({ currentPage, onPageChange }) {
  const [isOpen, setIsOpen] = useState(false);
  const [isScrolled, setIsScrolled] = useState(false);
  const [activeSection, setActiveSection] = useState('home');
  
  // Modal States
  const [isLoginOpen, setIsLoginOpen] = useState(false); 
  const [isProfileOpen, setIsProfileOpen] = useState(false);
  const [isLoggedIn, setIsLoggedIn] = useState(false);

  // New State for Logout Modal
  const [showLogoutModal, setShowLogoutModal] = useState(false);

  // Check login status on mount
  useEffect(() => {
    const checkLoginStatus = () => {
      const user = sessionStorage.getItem('user_name');
      setIsLoggedIn(!!user);
    };
    checkLoginStatus();
    window.addEventListener('storage', checkLoginStatus);
    return () => window.removeEventListener('storage', checkLoginStatus);
  }, []);

  // Handle Steering Wheel Click
  const handleAdminClick = () => {
    setIsOpen(false);
    if (isLoggedIn) {
      setIsProfileOpen(true);
    } else {
      setIsLoginOpen(true);
    }
  };

  const handleLoginSuccess = () => {
    setIsLoggedIn(true);
    setIsLoginOpen(false);
    // Success modal is now handled inside LoginModal.jsx
  };

  const handleLogoutSuccess = () => {
    sessionStorage.removeItem('user_name');
    setIsLoggedIn(false);
    setIsProfileOpen(false);
    // Show custom modal instead of alert
    setShowLogoutModal(true);
    // Auto close after 2 seconds
    setTimeout(() => setShowLogoutModal(false), 2000);
  };

  const navItems = [
    { label: 'Home', id: 'home', isPage: false },
    { label: 'About', id: 'about', isPage: false },
    { label: 'Services', id: 'services', isPage: false },
    { label: 'Agency', id: 'agency', isPage: false },
    { label: 'Contact', id: 'contact', isPage: false },
    { label: 'Media', id: 'media', isPage: true },
    { label: 'MMS Updates', id: 'mms-updates', isPage: true },
  ];

  const showAdminIcon = currentPage === 'media' || currentPage === 'mms-updates';
  const darkBlue = '#28364b';
  const accentGold = '#cebd88';
  
  useEffect(() => {
    const handleScroll = () => {
      setIsScrolled(window.scrollY > 50);
    };
    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  useEffect(() => {
    if (currentPage !== 'home') return; 

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            setActiveSection(entry.target.id);
          }
        });
      },
      { root: null, rootMargin: '0px 0px -50% 0px', threshold: 0 }
    );

    navItems.forEach((item) => {
      if (!item.isPage) {
        const element = document.getElementById(item.id);
        if (element) observer.observe(element);
      }
    });

    return () => observer.disconnect();
  }, [currentPage]);

  const handleNavClick = (item) => {
    setIsOpen(false);
    if (item.isPage) {
      if (currentPage !== item.id) {
        onPageChange(item.id);
      }
    } else {
      if (currentPage !== 'home') {
        onPageChange('home', item.id);
      } else {
        const element = document.getElementById(item.id);
        if (element) {
          setActiveSection(item.id);
          element.scrollIntoView({ behavior: 'smooth' });
        }
      }
    }
  };

  const isLinkActive = (item) => {
    if (!item.isPage) {
      return currentPage === 'home' && activeSection === item.id;
    }
    return currentPage === item.id;
  };

  return (
    <>
      <style>
        {`
          @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Raleway:wght@400;500;700&display=swap');
          .font-playfair { font-family: 'Playfair Display', serif; }
          .font-raleway { font-family: 'Raleway', sans-serif; }
          @keyframes fade-in {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
          }
          .animate-fade-in { animation: fade-in 0.2s ease-out forwards; }
        `}
      </style>

      {/* Login Modal */}
      <LoginModal 
        isOpen={isLoginOpen} 
        onClose={() => setIsLoginOpen(false)} 
        onLoginSuccess={handleLoginSuccess} 
      />

      {/* Profile Modal */}
      <ProfileModal 
        isOpen={isProfileOpen} 
        onClose={() => setIsProfileOpen(false)} 
        onLogout={handleLogoutSuccess}
      />

      {/* NEW: Logout Success Modal */}
      {showLogoutModal && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/40 backdrop-blur-sm animate-fade-in">
           <div className="bg-white rounded-xl shadow-2xl p-8 flex flex-col items-center max-w-sm w-full mx-4 border border-[#cebd88]">
              <div className="h-16 w-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                  <LogOut className="h-8 w-8 text-gray-600" />
              </div>
              <h3 className="text-xl font-bold font-playfair text-[#28364b] mb-2">Logged Out</h3>
              <p className="text-sm font-raleway text-gray-500 text-center">
                You have been securely logged out of the admin session.
              </p>
           </div>
        </div>
      )}

      <nav
        className={`sticky top-0 z-50 transition-all duration-300 ${
          isScrolled || currentPage !== 'home'
            ? 'bg-white backdrop-blur-md shadow-lg' 
            : 'bg-white/95 backdrop-blur-sm shadow-sm'
        }`}
      >
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
            <div className="flex justify-between items-center h-20 relative"> 
            
            {/* 1. LEFT SIDE: Logo Image (Always Visible) + Desktop Text */}
            <div 
              className="flex items-center space-x-3 cursor-pointer shrink-0" 
              onClick={() => handleNavClick({ id: 'home', isPage: false })}
            >
              <img src="/logo.png" alt="Matria Marine Logo" className="w-12 h-12"/>
              <div className="hidden lg:flex flex-col items-center">
                <span className="font-bold text-2xl font-raleway tracking-wide leading-none whitespace-nowrap" style={{ color: darkBlue }}>
                  M A T R I A
                </span>
                <span className="font-raleway text-[10.5px] tracking-[0.2em] -mt-0.5 whitespace-nowrap" style={{ color: darkBlue }}>
                  MARINE SERVICES
                </span>
                <div className="flex space-x-0.5" style={{ color: accentGold }}> 
                  {[...Array(5)].map((_, i) => <Star key={i} size={12} fill={accentGold} strokeWidth={0} />)}
                </div>
              </div>
            </div>

            {/* 2. CENTER: Text Only (Mobile Only) */}
            <div 
              className="lg:hidden absolute left-1/2 transform -translate-x-1/2 flex flex-col items-center" 
              style={{ color: darkBlue }}
              onClick={() => handleNavClick({ id: 'home', isPage: false })}
            >
              <span className="font-bold text-xl font-raleway tracking-wide leading-none whitespace-nowrap">
                M A T R I A
              </span>
              <span className="font-raleway text-[9px] tracking-[0.2em] -mt-0.5 whitespace-nowrap">
                MARINE SERVICES
              </span>
              <div className="flex space-x-0.5" style={{ color: accentGold }}> 
                {[...Array(5)].map((_, i) => <Star key={i} size={10} fill={accentGold} strokeWidth={0} />)}
              </div>
            </div>

            {/* Desktop Navigation Links */}
            <div className="hidden lg:flex items-center space-x-8">
              {navItems.map((item) => {
                const active = isLinkActive(item);
                return (
                  <button
                    key={item.id}
                    onClick={() => handleNavClick(item)}
                    className={`relative transition-colors duration-300 font-raleway group py-2
                      ${active ? 'font-bold' : 'font-medium'}
                    `}
                    style={{ color: darkBlue }}
                  >
                    {item.label}
                    <span 
                      className={`absolute bottom-0 left-0 w-full h-0.5 bg-[${accentGold}] transition-transform duration-300 ease-out origin-center
                        ${active ? 'scale-x-100' : 'scale-x-0 group-hover:scale-x-100'}
                      `}
                    />
                  </button>
                );
              })}
            </div>

            {/* CTA Button & Admin Icon (Desktop) */}
            <div className="hidden lg:flex items-center gap-4">
              <button
                onClick={() => handleNavClick({ id: 'contact', isPage: false })}
                className="bg-[#28364b] text-white font-bold px-6 py-2 rounded-lg hover:bg-[#3c4a63] transition-all font-raleway text-sm whitespace-nowrap"
              >
                Get Started
              </button>

              {showAdminIcon && (
                <button 
                  onClick={handleAdminClick} 
                  className={`group text-[#28364b] hover:text-[#cebd88] transition-colors p-1 ${isLoggedIn ? 'text-[#cebd88]' : ''}`}
                  title={isLoggedIn ? "My Profile" : "Admin Login"}
                >
                  <ShipWheel 
                    size={28} 
                    strokeWidth={1.5}
                    className="transition-transform duration-700 ease-in-out group-hover:rotate-180"
                  />
                  {isLoggedIn && <span className="absolute top-1 right-1 w-2 h-2 bg-green-500 rounded-full border border-white"></span>}
                </button>
              )}
            </div>

            {/* Mobile Menu Button */}
            <button className="lg:hidden ml-auto" style={{ color: darkBlue }} onClick={() => setIsOpen(!isOpen)}>
              {isOpen ? <X size={28} /> : <Menu size={28} />}
            </button>
          </div>

          {/* Mobile Navigation Panel */}
          {isOpen && (
            <div className="lg:hidden bg-white border-t" style={{ borderColor: `rgba(206, 189, 136, 0.3)` }}>
              <div className="flex flex-col space-y-4 px-4 py-6">
                {navItems.map((item) => {
                   const active = isLinkActive(item);
                   return (
                    <button
                      key={item.id}
                      onClick={() => handleNavClick(item)}
                      className={`transition-colors duration-300 text-center font-raleway py-2
                        ${active ? 'font-bold' : 'font-medium'}
                      `}
                      style={{ color: darkBlue }}
                    >
                      {item.label}
                    </button>
                  );
                })}
                {showAdminIcon && (
                   <button 
                    onClick={handleAdminClick} 
                    className="flex items-center justify-center gap-2 text-[#28364b] font-raleway font-bold pt-4 border-t border-gray-100"
                   >
                     <ShipWheel size={20} /> {isLoggedIn ? 'My Profile' : 'Admin Login'}
                   </button>
                )}
              </div>
            </div>
          )}
        </div>
      </nav>
    </>
  );
}