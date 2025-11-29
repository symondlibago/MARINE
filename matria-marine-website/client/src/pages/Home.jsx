import React, { useState, useEffect } from 'react';
import Navigation from '@/components/Navigation';
import Hero from '@/components/Hero';
import About from '@/components/About';
import Services from '@/components/Services';
import Agency from '@/components/Agency';
import Contact from '@/components/Contact';
import Footer from '@/components/Footer';
import Media from '@/components/Media'; // Import the new Media page
import LoadingScreen from '@/components/LoadingScreen'; // Import Loading Screen

export default function Home() { // Or name this App
  const [currentPage, setCurrentPage] = useState('home'); // 'home' or 'media'
  const [isLoading, setIsLoading] = useState(false);
  
  // Logic to handle page switching with loading effect
  const handlePageChange = (targetPage, targetSectionId = null) => {
    setIsLoading(true);

    // Simulate loading time (e.g., 1.5 seconds)
    setTimeout(() => {
      setCurrentPage(targetPage);
      setIsLoading(false);

      // If going back to home with a specific section target (e.g., clicked Contact while on Media)
      if (targetPage === 'home' && targetSectionId) {
        setTimeout(() => {
          const element = document.getElementById(targetSectionId);
          if (element) element.scrollIntoView({ behavior: 'smooth' });
        }, 100);
      }
    }, 1500);
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
        {currentPage === 'home' ? (
          <>
            <Hero />
            <About />
            <Services />
            <Agency />
            <Contact />
          </>
        ) : (
          <Media />
        )}
      </main>

      <Footer />
    </div>
  );
}