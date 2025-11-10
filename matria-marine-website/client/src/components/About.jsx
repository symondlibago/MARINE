import React, { useState, useEffect } from 'react';

// New data for the Key Values section based on your image
const newKeyValues = [
  {
    image: "/key1.png",
    text: "Streamline your vessel's needs and unlock peak efficiency with Matria Marine's enhanced, comprehensive Full-Style Husbandry Service. We provide owners and clients with unparalleled support designed to minimize downtime and maximize peace of mind."
  },
  {
    image: "/key2.png",
    text: "From seamless crew changes and swift spare part deliveries to optimized bunker calls and expert spares reconditioning, our dedicated team of experienced agents ensures every aspect of your ship's welfare is meticulously managed."
  },
  {
    image: "/key3.png",
    text: "Experience the Matria Advantage: Our single point of contact guarantees smooth, hassle-free operations, unmatched responsiveness, and the shortest possible turnaround times."
  },
  {
    image: "/key4.png",
    text: "Let Matria Marine be your trusted partner for a flawlessly managed fleet."
  }
];

// --- useIsMobile hook logic moved here ---
const MOBILE_BREAKPOINT = 768;

function useIsMobile() {
  // CORRECTION: Initialized state to `false` instead of using TypeScript types
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const mql = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);
    
    const onChange = () => {
      setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);
    };

    mql.addEventListener("change", onChange);
    
    // Set initial state on mount
    setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);
    
    return () => mql.removeEventListener("change", onChange);
  }, []);

  return isMobile; // Return the boolean
}
// ------------------------------------------

// --- NEW ValueItem COMPONENT ---
// This component manages its own state for expanding/collapsing text
function ValueItem({ item, isMobile }) {
  const [isExpanded, setIsExpanded] = useState(false);
  const toggleExpand = () => setIsExpanded(!isExpanded);

  // Check if text is long enough to truncate
  const isLongText = item.text.length > 150; // Truncate after 150 chars
  
  // Generate truncated text
  const shortText = isLongText ? item.text.substring(0, 150) + "..." : item.text;
  
  // Determine which text to display
  const displayText = isExpanded ? item.text : shortText;

  return (
    <div
      className={`
        bg-white rounded-lg shadow-md p-6 hover:shadow-xl transition-shadow duration-300 
        flex ${isMobile ? 'flex-col' : 'flex-row'} items-center gap-6
      `}
    >
      <img 
        src={item.image} 
        alt={item.text.substring(0, 30)}
        className="rounded-full w-24 h-24 object-cover shadow-md flex-shrink-0" 
      />
      {/* Container for text and button */}
      <div className={`flex-1 ${isMobile ? 'text-center' : 'text-left'}`}>
        <p className={`
          font-raleway text-gray-700 text-lg leading-relaxed 
        `}>
          {displayText}
        </p>
        
        {/* Only show the button if the text is long enough to be truncated */}
        {isLongText && (
          <button
            onClick={toggleExpand}
            className="font-raleway text-[#cebd88] font-normal text-sm underline hover:text-[#bca971] transition-colors mt-4"
          >
            {isExpanded ? "See Less" : "See More"}
          </button>
        )}
      </div>
    </div>
  );
}
// -------------------------------

export default function About() {
  // Call the hook (now defined in this file)
  const isMobile = useIsMobile();

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

      <section id="about" className="py-24 bg-[#F1F0E8]">
        {/* Adjusted horizontal padding for mobile */}
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
          
          {/* --- ADDED THIS SECTION HEADER --- */}
          <div className="text-center mb-16">
            <h2 className="text-3xl md:text-4xl lg:text-5xl font-bold text-gray-900 font-playfair">
              About Us
            </h2>
          </div>
          {/* ---------------------------------- */}

          {/* Main About Section */}
          <div className="grid md:grid-cols-2 gap-16 items-center mb-24">
            
            {/* Left Content (Text) */}
            <div className="space-y-6">
              {/* Responsive font size for mobile */}
              <h2 className="text-3xl md:text-4xl font-bold text-gray-900 font-playfair">
                Passionately Delivering Your Peace of Mind at Sea
              </h2>
              {/* Responsive font size for mobile */}
              <h3 className="text-xl md:text-2xl font-semibold text-gray-800 font-playfair">
                Driven by Excellence: Your Guarantee of Unrivaled Marine Service.
              </h3>
              <p className="text-gray-700 text-lg leading-relaxed font-raleway">
                At Matria Marine, our commitment to your success begins with
                our exceptional team. We cultivate a highly professional and
                collaborative environment, empowering our dedicated experts
                to deliver consistent, top-tier service.
              </p>
              <p className="text-gray-700 text-lg leading-relaxed font-raleway">
                Our specialists work with unparalleled efficiency and precision,
                ensuring every task is executed with the highest degree of
                accuracy. This technological advantage translates directly into
                smoother operations and optimized outcomes for your vessels.
              </p>
              <p className="text-gray-700 text-lg leading-relaxed font-raleway">
                We don't just aim for satisfaction; we guarantee it. Through
                rigorous standards in <strong>environment, safety, and workload
                management</strong>, we provide a service that is not only highly
                effective but also responsible and reliable.
              </p>
              <p className="text-gray-700 text-lg leading-relaxed font-raleway">
                Partner with Matria Marine and experience the confidence that
                comes from working with a team committed to your absolute
                satisfaction.
              </p>
            </div>

            {/* Right Content (Image) */}
            <div className="bg-white/70 rounded-lg p-6 md:p-8 border border-gray-300/50 shadow-lg text-center">
              <img 
                src="/about1.png" 
                alt="Matria Marine Services" 
                className="rounded-lg mb-6 shadow-md max-w-lg mx-auto w-full"
              />
              {/* Responsive font size for mobile */}
              <h3 className="text-xl md:text-2xl font-semibold text-gray-900 font-playfair">
                Elevate Your Maritime Operations with Matria Marine Services.
              </h3>
            </div>
          </div>

          {/* Key Values Section - UPDATED */}
          <div className="text-center mb-16">
            {/* Responsive font size for mobile */}
            <h2 className="text-3xl md:text-4xl lg:text-5xl font-bold text-gray-900 font-playfair">
              Our Key Values
            </h2>
          </div>

          {/* New List Layout - Using isMobile hook */}
          <div className="grid grid-cols-1 gap-8 max-w-4xl mx-auto">
            {newKeyValues.map((value, index) => (
              <ValueItem key={index} item={value} isMobile={isMobile} />
            ))}
          </div>
          
        </div>
      </section>
    </>
  );
}