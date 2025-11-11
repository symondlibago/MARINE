import { useState, useEffect } from 'react';
import { Menu, X } from 'lucide-react';

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
            ? 'bg-[#28364b] backdrop-blur-md shadow-lg' // Changed to dark blue
            : 'bg-[#28364b] backdrop-blur-sm' // Changed to dark blue
        }`}
      >
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
          <div className="flex justify-between items-center h-20">
            {/* Logo */}
            <div className="flex items-center space-x-3">
              <div 
                className="w-10 h-10 rounded-lg flex items-center justify-center"
                style={{ backgroundColor: '#cebd88' }} // Use gold accent
              >
                <span className="text-gray-900 font-bold text-lg">MM</span>
              </div>
              <span className="text-white font-bold text-xl hidden sm:inline font-playfair">
                Matria Marine
              </span>
            </div>

            {/* Desktop Navigation */}
            <div className="hidden lg:flex items-center space-x-8">
              {navItems.map((item) => (
                <button
                  key={item.id}
                  onClick={() => scrollToSection(item.id)}
                  className="relative text-white hover:text-[#cebd88] transition-colors duration-300 font-medium font-raleway group py-2"
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
                onClick={() => scrollToSection('about')}
                className="bg-[#cebd88] text-gray-900 font-bold px-6 py-2 rounded-lg hover:bg-[#bca971] transition-all font-raleway text-sm"
              >
                Get Started
              </button>
            </div>

            {/* Mobile Menu Button */}
            <button
              className="lg:hidden text-white"
              onClick={() => setIsOpen(!isOpen)}
            >
              {isOpen ? <X size={28} /> : <Menu size={28} />}
            </button>
          </div>

          {/* Mobile Navigation */}
          {isOpen && (
            <div 
              className="lg:hidden bg-[#28364b] border-t" // Changed from bg-black/95
              style={{ borderColor: 'rgba(206, 189, 136, 0.3)' }} // Gold border
            >
              <div className="flex flex-col space-y-4 px-4 py-6">
                {navItems.map((item) => (
                  <button
                    key={item.id}
                    onClick={() => scrollToSection(item.id)}
                    className="text-white hover:text-[#cebd88] transition-colors duration-300 text-left font-medium font-raleway py-2"
                  >
                    {item.label}
                  </button>
                ))}
                <button
                  onClick={() => scrollToSection('about')}
                  className="bg-[#cebd88] text-gray-900 font-bold px-6 py-3 rounded-lg hover:bg-[#bca971] transition-all font-raleway w-full mt-4"
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