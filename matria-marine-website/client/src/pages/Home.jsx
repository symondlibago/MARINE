import React, { useState, useEffect } from 'react';
import Navigation from '@/components/Navigation';
import Hero from '@/components/Hero';
import About from '@/components/About';
import Services from '@/components/Services';
import Agency from '@/components/Agency';
import Contact from '@/components/Contact';
import Footer from '@/components/Footer';
import Media from '@/components/Media'; 
import MMSUpdates from '@/components/MMSUpdates'; // Import the new page
import LoadingScreen from '@/components/LoadingScreen'; 

export default function Home() { 
  const [currentPage, setCurrentPage] = useState('home'); 
  
  // Start with isLoading as true so it shows immediately on refresh
  const [isLoading, setIsLoading] = useState(true);

  // Effect to handle the initial website load
  useEffect(() => {
    const timer = setTimeout(() => {
      setIsLoading(false);
    }, 2000); 

    return () => clearTimeout(timer); 
  }, []);
  
  // Logic to handle page switching with loading effect
  const handlePageChange = (targetPage, targetSectionId = null) => {
    setIsLoading(true);

    setTimeout(() => {
      setCurrentPage(targetPage);
      setIsLoading(false);

      // If going back to home with a specific section target
      if (targetPage === 'home' && targetSectionId) {
        setTimeout(() => {
          const element = document.getElementById(targetSectionId);
          if (element) element.scrollIntoView({ behavior: 'smooth' });
        }, 100);
      }
    }, 1500);
  };

  // Helper to render content based on currentPage
  const renderContent = () => {
    switch(currentPage) {
      case 'media':
        return <Media />;
      case 'mms-updates':
        return <MMSUpdates />;
      case 'home':
      default:
        return (
          <>
            <Hero />
            <About />
            <Services />
            <Agency />
            <Contact />
          </>
        );
    }
  };

  return (
    <div className="min-h-screen flex flex-col bg-white">
      
      {/* Show Loading Screen Overlay */}
      {isLoading && <LoadingScreen />}

      {/* Navigation - Passed props to control state */}
      <Navigation 
        currentPage={currentPage} 
        onPageChange={handlePageChange} 
      />

      <main className="flex-grow">
        {renderContent()}
      </main>

      <Footer />
    </div>
  );
}