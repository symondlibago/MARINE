import { useState, useEffect } from 'react';
import { Menu, X, Star } from 'lucide-react'; // Added Star icon

export default function Navigation() {
  const [isOpen, setIsOpen] = useState(false);
  const [isScrolled, setIsScrolled] = useState(false);

  useEffect(() => {
    const handleScroll = () => {
      setIsScrolled(window.scrollY > 50);
    };

    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  const scrollToSection = (id) => {
    const element = document.getElementById(id);
    if (element) {
      element.scrollIntoView({ behavior: 'smooth' });
      setIsOpen(false);
    }
  };

  const navItems = [
    { label: 'Home', id: 'home' },
    { label: 'About', id: 'about' },
    { label: 'Services', id: 'services' },
    { label: 'Agency', id: 'agency' },
    { label: 'Contact', id: 'contact' },
  ];

  return (
    <>
      {/* Import the Playfair Display and Raleway fonts */}
      <style>
        {`
          @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Raleway:wght@400;500;700&display=swap');
          
          .font-playfair {
            font-family: 'Playfair Display', serif;
          }
          
          .font-raleway {
            font-family: 'Raleway', sans-serif;
          }
        `}
      </style>

      <nav
        className={`sticky top-0 z-50 transition-all duration-300 ${
          isScrolled
            ? 'bg-white backdrop-blur-md shadow-lg' // CHANGED: White background
            : 'bg-white/95 backdrop-blur-sm shadow-sm' // CHANGED: White background
        }`}
      >
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
          <div className="flex justify-between items-center h-20">
            {/* Logo - UPDATED */}
            <div className="flex items-center space-x-3">
              {/* Replaced MM box with logo.png */}
              <img 
                src="/logo.png" 
                alt="Matria Marine Logo" 
                className="w-12 h-12" // Made it slightly larger
              />
              
              {/* New text styling - ADDED items-center */}
              <div className="flex-col hidden sm:flex items-center">
                <span 
                  className="font-bold text-2xl font-raleway tracking-wide leading-none"
                  style={{ color: '#28364b' }} // CHANGED: Dark blue text
                >
                  M A T R I A
                </span>
                <span 
                  className="font-raleway text-[10.5px] tracking-[0.2em] -mt-0.5"
                  style={{ color: '#28364b' }} // CHANGED: Dark blue text
                >
                  MARINE SERVICES
                </span>
                <div className="flex space-x-0.5 text-[#cebd88]"> 
                  <Star size={12} fill="#cebd88" strokeWidth={0} />
                  <Star size={12} fill="#cebd88" strokeWidth={0} />
                  <Star size={12} fill="#cebd88" strokeWidth={0} />
                  <Star size={12} fill="#cebd88" strokeWidth={0} />
                  <Star size={12} fill="#cebd88" strokeWidth={0} />
                </div>
              </div>
            </div>

            {/* Desktop Navigation */}
            <div className="hidden lg:flex items-center space-x-8">
              {navItems.map((item) => (
                <button
                  key={item.id}
                  onClick={() => scrollToSection(item.id)}
                  className="relative hover:text-[#cebd88] transition-colors duration-300 font-medium font-raleway group py-2"
                  style={{ color: '#28364b' }} // CHANGED: Dark blue text
                >
                  {item.label}
                  {/* Underline hover effect */}
                  <span className="absolute bottom-0 left-0 w-full h-0.5 bg-[#cebd88] scale-x-0 group-hover:scale-x-100 transition-transform duration-300 ease-out origin-center" />
                </button>
              ))}
            </div>

            {/* CTA Button */}
            <div className="hidden lg:block">
              <button
                onClick={() => scrollToSection('contact')}
                // CHANGED: New button style for better contrast
                className="bg-[#28364b] text-white font-bold px-6 py-2 rounded-lg hover:bg-[#3c4a63] transition-all font-raleway text-sm"
              >
                Get Started
              </button>
            </div>

            {/* Mobile Menu Button */}
            <button
              className="lg:hidden" // CHANGED: Dark blue text
              style={{ color: '#28364b' }}
              onClick={() => setIsOpen(!isOpen)}
            >
              {isOpen ? <X size={28} /> : <Menu size={28} />}
            </button>
          </div>

          {/* Mobile Navigation */}
          {isOpen && (
            <div 
              className="lg:hidden bg-white border-t" // CHANGED: White background
              style={{ borderColor: 'rgba(206, 189, 136, 0.3)' }} // Gold border
            >
              <div className="flex flex-col space-y-4 px-4 py-6">
                {navItems.map((item) => (
                  <button
                    key={item.id}
                    onClick={() => scrollToSection(item.id)}
                    className="hover:text-[#cebd88] transition-colors duration-300 text-left font-medium font-raleway py-2"
                    style={{ color: '#28364b' }} // CHANGED: Dark blue text
                  >
                    {item.label}
                  </button>
                ))}
                <button
                  onClick={() => scrollToSection('contact')}
                  // CHANGED: New button style for better contrast
                  className="bg-[#28364b] text-white font-bold px-6 py-3 rounded-lg hover:bg-[#3c4a63] transition-all font-raleway w-full mt-4"
                >
                  Get Started
                </button>
              </div>
            </div>
          )}
        </div>
      </nav>
    </>
  );
}