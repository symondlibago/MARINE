import { useState, useEffect } from 'react';
import { Menu, X, Star } from 'lucide-react'; 

export default function Navigation() {
  const [isOpen, setIsOpen] = useState(false);
  const [isScrolled, setIsScrolled] = useState(false);
  // State to track the currently active section ID
  const [activeSection, setActiveSection] = useState('home');

  const navItems = [
    { label: 'Home', id: 'home' },
    { label: 'About', id: 'about' },
    { label: 'Services', id: 'services' },
    { label: 'Agency', id: 'agency' },
    { label: 'Contact', id: 'contact' },
  ];

  // Effect for detecting scroll position for the sticky header
  useEffect(() => {
    const handleScroll = () => {
      setIsScrolled(window.scrollY > 50);
    };

    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  // NEW: Effect to listen for manual navigation updates from Footer
  useEffect(() => {
    const handleManualUpdate = (event) => {
      if (event.detail) {
        setActiveSection(event.detail);
      }
    };

    window.addEventListener('force-nav-update', handleManualUpdate);
    return () => window.removeEventListener('force-nav-update', handleManualUpdate);
  }, []);

  // Effect for active link highlighting using Intersection Observer
  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            setActiveSection(entry.target.id);
          }
        });
      },
      {
        root: null, 
        rootMargin: '0px 0px -50% 0px', 
        threshold: 0, 
      }
    );

    navItems.forEach((item) => {
      const element = document.getElementById(item.id);
      if (element) {
        observer.observe(element);
      }
    });

    return () => {
      navItems.forEach((item) => {
        const element = document.getElementById(item.id);
        if (element) {
          observer.unobserve(element);
        }
      });
    };
  }, []); 

  const scrollToSection = (id) => {
    const element = document.getElementById(id);
    if (element) {
      setActiveSection(id); 
      element.scrollIntoView({ behavior: 'smooth' });
      setIsOpen(false);
    }
  };

  // Define the colors
  const darkBlue = '#28364b';
  const accentGold = '#cebd88';

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
            ? 'bg-white backdrop-blur-md shadow-lg' 
            : 'bg-white/95 backdrop-blur-sm shadow-sm'
        }`}
      >
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
          <div className="flex justify-between items-center h-20 relative"> 
            
            {/* Logo */}
            <div className="flex items-center space-x-3">
              <img 
                src="/logo.png" 
                alt="Matria Marine Logo" 
                className="w-12 h-12"
              />
              
              {/* Logo Text (Desktop) */}
              <div className="flex-col hidden sm:flex items-center">
                <span 
                  className="font-bold text-2xl font-raleway tracking-wide leading-none"
                  style={{ color: darkBlue }}
                >
                  M A T R I A
                </span>
                <span 
                  className="font-raleway text-[10.5px] tracking-[0.2em] -mt-0.5"
                  style={{ color: darkBlue }}
                >
                  MARINE SERVICES
                </span>
                <div className="flex space-x-0.5" style={{ color: accentGold }}> 
                  <Star size={12} fill={accentGold} strokeWidth={0} />
                  <Star size={12} fill={accentGold} strokeWidth={0} />
                  <Star size={12} fill={accentGold} strokeWidth={0} />
                  <Star size={12} fill={accentGold} strokeWidth={0} />
                  <Star size={12} fill={accentGold} strokeWidth={0} />
                </div>
              </div>
            </div>

            {/* Logo Text (Mobile) */}
            <div 
              className="lg:hidden absolute left-1/2 transform -translate-x-1/2 flex flex-col items-center" 
              style={{ color: darkBlue }}
            >
              <span 
                className="font-bold text-xl font-raleway tracking-wide leading-none" 
              >
                M A T R I A
              </span>
              <span 
                className="font-raleway text-[9px] tracking-[0.2em] -mt-0.5" 
              >
                MARINE SERVICES
              </span>
              <div className="flex space-x-0.5" style={{ color: accentGold }}> 
                <Star size={10} fill={accentGold} strokeWidth={0} />
                <Star size={10} fill={accentGold} strokeWidth={0} />
                <Star size={10} fill={accentGold} strokeWidth={0} />
                <Star size={10} fill={accentGold} strokeWidth={0} />
                <Star size={10} fill={accentGold} strokeWidth={0} />
              </div>
            </div>
            
            {/* Desktop Navigation */}
            <div className="hidden lg:flex items-center space-x-8">
              {navItems.map((item) => (
                <button
                  key={item.id}
                  onClick={() => scrollToSection(item.id)}
                  className={`
                    relative transition-colors duration-300 font-raleway group py-2
                    ${
                      activeSection === item.id
                        ? 'font-bold' 
                        : 'font-medium'
                    }
                  `}
                  style={{ color: activeSection === item.id ? darkBlue : darkBlue }} 
                >
                  {item.label}
                  {/* Underline hover effect */}
                  <span 
                    className={`
                      absolute bottom-0 left-0 w-full h-0.5 bg-[${accentGold}] 
                      transition-transform duration-300 ease-out origin-center
                      ${activeSection === item.id || 'group-hover:scale-x-100'} 
                      ${activeSection === item.id ? 'scale-x-100' : 'scale-x-0'}
                    `}
                  />
                </button>
              ))}
            </div>

            {/* CTA Button */}
            <div className="hidden lg:block">
              <button
                onClick={() => scrollToSection('contact')}
                className="bg-[#28364b] text-white font-bold px-6 py-2 rounded-lg hover:bg-[#3c4a63] transition-all font-raleway text-sm"
              >
                Get Started
              </button>
            </div>

            {/* Mobile Menu Button */}
            <button
              className="lg:hidden" 
              style={{ color: darkBlue }}
              onClick={() => setIsOpen(!isOpen)}
            >
              {isOpen ? <X size={28} /> : <Menu size={28} />}
            </button>
          </div>

          {/* Mobile Navigation Panel */}
          {isOpen && (
            <div 
              className="lg:hidden bg-white border-t" 
              style={{ borderColor: `rgba(206, 189, 136, 0.3)` }}
            >
              <div className="flex flex-col space-y-4 px-4 py-6">

                {navItems.map((item) => (
                  <button
                    key={item.id}
                    onClick={() => scrollToSection(item.id)}
                    className={`
                      transition-colors duration-300 text-center font-raleway py-2
                      ${activeSection === item.id ? 'font-bold' : 'font-medium'}
                    `}
                    style={{ 
                      color: activeSection === item.id ? darkBlue : darkBlue,
                      transition: 'color 0.3s',
                    }}
                    onMouseEnter={e => e.currentTarget.style.color = activeSection === item.id ? darkBlue : accentGold}
                    onMouseLeave={e => e.currentTarget.style.color = activeSection === item.id ? darkBlue : darkBlue}
                  >
                    {item.label}
                  </button>
                ))}
                <button
                  onClick={() => scrollToSection('contact')}
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