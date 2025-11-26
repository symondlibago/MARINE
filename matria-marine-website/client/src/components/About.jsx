import React, { useState, useEffect } from 'react';

// UPDATED: New data for the Key Values section with about1.jpeg - about4.jpeg
const newKeyValues = [
  {
    image: "/about1.jpeg",
    text: "Streamline your vessel's needs and unlock peak efficiency with Matria Marine's enhanced, comprehensive Full-Style Husbandry Service. We provide owners and clients with unparalleled support designed to minimize downtime and maximize peace of mind."
  },
  {
    image: "/about2.jpeg",
    text: "From seamless crew changes and swift spare part deliveries to optimized bunker calls and expert spares reconditioning, our dedicated team of experienced agents ensures every aspect of your ship's welfare is meticulously managed."
  },
  {
    image: "/about3.jpeg",
    text: "Experience the Matria Advantage: Our single point of contact guarantees smooth, hassle-free operations, unmatched responsiveness, and the shortest possible turnaround times."
  },
  {
    image: "/about4.jpeg",
    text: "Let Matria Marine be your trusted partner for a flawlessly managed fleet."
  }
];

// --- useIsMobile hook logic moved here ---
const MOBILE_BREAKPOINT = 768;

function useIsMobile() {
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

  return isMobile; 
}
// ------------------------------------------

// --- Fading Separator Line Component ---
const FadingSeparator = () => (
    <div className="flex justify-center items-center py-4">
        <div 
            className="w-full h-px" 
            style={{
                background: 'linear-gradient(to right, rgba(40, 54, 75, 0) 0%, rgba(40, 54, 75, 0.5) 50%, rgba(40, 54, 75, 0) 100%)',
                maxWidth: '80%', 
            }}
        />
    </div>
);
// ---------------------------------------------


// --- ValueItem COMPONENT ---
function ValueItem({ item }) {
  const [isExpanded, setIsExpanded] = useState(false);
  const toggleExpand = () => setIsExpanded(!isExpanded);

  const isLongText = item.text.length > 150; 
  const shortText = isLongText ? item.text.substring(0, 150) + "..." : item.text;
  const displayText = isExpanded ? item.text : shortText;

  return (
    <div
      className={`
        bg-white rounded-lg shadow-md hover:shadow-xl transition-shadow duration-300 
        flex flex-col overflow-hidden
      `}
    >
      {/* IMAGE CONTAINER with padding */}
      <div className="p-4"> 
        <img 
          src={item.image} 
          alt={item.text.substring(0, 30)}
          className="w-full h-48 object-cover shadow-md rounded-md" 
        />
      </div>
      
      {/* Separator Line */}
      <FadingSeparator />
      
      {/* Container for text and button (with padding) */}
      <div className="flex-1 p-6 pt-0 text-center"> 
        <p className={`
          font-raleway text-[#28364b] text-base leading-relaxed text-justify
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

      {/* UPDATED: Reduced padding from py-24 to py-16 */}
      <section id="about" className="py-16 bg-[#F1F0E8]">
        <div className="container mx-auto px-4 md:px-6 lg:px-12 xl:px-24">
          
          {/* --- SECTION HEADER --- */}
          {/* UPDATED: Reduced margin from mb-16 to mb-12 */}
          <div className="text-center mb-12">
            <h2 className="text-3xl md:text-4xl lg:text-5xl font-bold text-[#28364b] font-playfair">
              About Us
            </h2>
          </div>
          {/* ---------------------------------- */}

          {/* Main About Section */}
          {/* UPDATED: Reduced gap from gap-16 to gap-12 and margin from mb-24 to mb-16 */}
          <div className="grid md:grid-cols-2 gap-12 items-center mb-16">
            
            {/* Left Content (Text) */}
            <div className="space-y-6">
              <h2 className="text-3xl md:text-4xl font-bold text-[#28364b] font-playfair">
                Passionately Delivering Your Peace of Mind at Sea
              </h2>
              <h3 className="text-xl md:text-2xl font-semibold text-[#28364b] font-playfair">
                Driven by Excellence: Your Guarantee of Unrivaled Marine Service.
              </h3>
              <p className="text-[#28364b] text-base leading-relaxed font-raleway text-justify">
                At Matria Marine, our commitment to your success begins with
                our exceptional team. We cultivate a highly professional and
                collaborative environment, empowering our dedicated experts
                to deliver consistent, top-tier service.
              </p>
              <p className="text-[#28364b] text-base leading-relaxed font-raleway text-justify">
                Our specialists work with unparalleled efficiency and precision,
                ensuring every task is executed with the highest degree of
                accuracy. This technological advantage translates directly into
                smoother operations and optimized outcomes for your vessels.
              </p>
              <p className="text-[#28364b] text-base leading-relaxed font-raleway text-justify">
                We don't just aim for satisfaction; we guarantee it. Through
                rigorous standards in <strong>environment, safety, and workload
                management</strong>, we provide a service that is not only highly
                effective but also responsible and reliable.
              </p>
              <p className="text-[#28364b] text-base leading-relaxed font-raleway text-justify">
                Partner with Matria Marine and experience the confidence that
                comes from working with a team committed to your absolute
                satisfaction.
              </p>
            </div>

            {/* Right Content (Video) */}
            {/* UPDATED: Reduced padding from p-6 md:p-8 to p-4 md:p-6 */}
            <div className="bg-white/70 rounded-lg p-4 md:p-6 border border-gray-300/50 shadow-lg text-center">
              
              {/* UPDATED: Added aspect ratio container to constrain height */}
              <div className="relative w-full h-0 pb-[70%] rounded-lg mb-6 shadow-md overflow-hidden">
                <video 
                  className="absolute top-0 left-0 w-full h-full object-cover"
                  autoPlay
                  loop
                  muted
                  playsInline
                >
                  <source src="/aboutvid.mp4" type="video/mp4" />
                  Your browser does not support the video tag.
                </video>
              </div>
              
              <h3 className="text-xl md:text-2xl font-semibold text-[#28364b] font-playfair">
                Elevate Your Maritime Operations with Matria Marine Services.
              </h3>
            </div>
          </div>

          {/* Key Values Section */}
          <div className="text-center mb-16">
            <h2 className="text-3xl md:text-4xl lg:text-5xl font-bold text-[#28364b] font-playfair">
              Our Key Values
            </h2>
          </div>

          {/* Key Values Grid */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 max-w-6xl mx-auto"> 
            {newKeyValues.map((value, index) => (
              <ValueItem key={index} item={value} />
            ))}
          </div>
          
        </div>
      </section>
    </>
  );
}