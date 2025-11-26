import React, { useState, useEffect } from 'react';
import {
  Wrench,
  Package,
  Ship,
  Truck
} from 'lucide-react';

// --- Data Structure ---
const serviceCategories = [
  {
    title: "Marine Services",
    icon: Wrench,
    // Assigning a set of service images for this category
    images: [
      "/services1.jpeg",
      "/services2.jpeg",
      "/services3.jpeg",
      "/services4.jpeg",
      "/services6.jpeg"
    ], 
    items: [
      "ME / AE Mechanical components",
      "Safety Equipment Servicing",
      "UTM (Pre-docking Survey)",
      "In-water Stern Tube Seal Repair",
      "Crane Load Testing",
      "Radio Survey",
      "Vessel renaming (rope access)",
      "Underwater Diving & Inspection"
    ]
  },
  {
    title: "Ship Supply",
    icon: Package,
    // UPDATED: Specific images requested
    images: [
      "/newhome1.jpeg",
      "/newhome3.jpeg",
      "/services5.jpeg"
    ],
    items: [
      "Ship Spares Supply (OEM, GENUINE)",
      "Provision",
      "Bonded Stores"
    ]
  },
  {
    title: "Agency",
    icon: Ship,
    // Assigning a set of relevant images
    images: [
      "/services3.jpeg",
      "/services2.jpeg",
      "/newhome3.jpeg",
      "/about1.jpeg",
      "/services1.jpeg"
    ],
    items: [
      {
        title: "Husbandry Owners matters",
        subItems: [
            "Garbage Disposal",
            "Fresh Water Supply",
            "De-slopping",
            "CTM (Cash to Master)"
        ]
      },
      "Protective Agency",
      "Crew Change",
      "Singapore OPL Services"
    ]
  },
  {
    title: "Complete Logistics",
    icon: Truck,
    // UPDATED: specific images requested ("the same with Complete Logistics")
    images: [
      "/newhome1.jpeg",
      "/newhome3.jpeg",
      "/services5.jpeg"
    ],
    items: [
      "Complete Logistics Solutions",
      "Freight Forwarding",
      "End-to-end Delivery"
    ]
  }
];

// --- Individual Service Card Component ---
const ServiceCard = ({ category, isHighlighted }) => {
  const Icon = category.icon;
  // Initialize with the first image in the category's specific list
  const [currentBg, setCurrentBg] = useState(category.images[0]);

  // Helper to get an image based on index from the category's specific list
  const getHoverImage = (index) => {
    return category.images[index % category.images.length];
  };

  return (
    <div 
      className={`
        relative bg-white rounded-xl shadow-lg transition-all duration-500 overflow-hidden group flex flex-col h-full border border-gray-100
        ${isHighlighted ? 'shadow-2xl ring-2 ring-[#cebd88]' : 'hover:shadow-2xl'}
      `}
      // Reset to the first image when mouse leaves
      onMouseLeave={() => setCurrentBg(category.images[0])} 
    >
      {/* --- Dynamic Background Image --- */}
      <div 
        className={`
          absolute inset-0 z-0 transition-opacity duration-700 ease-in-out 
          ${isHighlighted ? 'opacity-20' : 'opacity-15 group-hover:opacity-50'}
        `}
        style={{
          backgroundImage: `url(${currentBg})`,
          backgroundSize: 'cover',
          backgroundPosition: 'center',
        }}
      />
      
      {/* --- Content Overlay --- */}
      <div 
        className={`
          relative z-10 p-6 md:p-8 flex flex-col h-full transition-colors duration-300
          ${isHighlighted ? 'bg-white/40' : 'bg-white/60 hover:bg-white/40'}
        `}
      >
        
        {/* Header */}
        <div className="flex flex-col items-center text-center mb-6">
          <div 
            className={`
              p-3 bg-[#28364b] rounded-full shadow-md mb-3 transition-transform duration-300
              ${isHighlighted ? 'scale-110' : 'group-hover:scale-110'}
            `}
          >
            <Icon className="w-8 h-8 text-[#cebd88]" />
          </div>
          <h3 className="text-2xl font-bold font-playfair text-[#28364b]">
            {category.title}
          </h3>
          <div className="w-16 h-0.5 bg-[#cebd88] mt-3 rounded-full"></div>
        </div>

        {/* List Items */}
        <ul className="space-y-3 flex-1">
          {category.items.map((item, index) => {
            if (typeof item === 'object') {
              return (
                <li key={index} className="flex flex-col text-[#28364b]">
                  <div className="flex items-start gap-2 mb-1">
                    <span className="mt-2.5 w-1.5 h-1.5 bg-[#cebd88] rounded-full flex-shrink-0" />
                    <span className="font-raleway font-bold text-sm md:text-base">
                      {item.title}
                    </span>
                  </div>
                  <ul className="pl-6 space-y-1 mt-1 border-l-2 border-[#cebd88]/30 ml-1.5">
                    {item.subItems.map((sub, subIndex) => (
                      <li 
                        key={subIndex}
                        className="font-raleway text-sm cursor-pointer hover:text-[#cebd88] hover:font-semibold transition-colors duration-200 py-0.5"
                        onMouseEnter={() => setCurrentBg(getHoverImage(index + subIndex + 2))}
                      >
                        • {sub}
                      </li>
                    ))}
                  </ul>
                </li>
              );
            }
            return (
              <li 
                key={index} 
                className="flex items-start gap-3 text-[#28364b] group/item cursor-default"
                onMouseEnter={() => setCurrentBg(getHoverImage(index))}
              >
                <span className={`
                  mt-2 w-1.5 h-1.5 bg-[#cebd88] rounded-full flex-shrink-0 transition-colors
                  ${isHighlighted ? 'bg-[#28364b]' : 'group-hover/item:bg-[#28364b]'}
                `} />
                <span className={`
                  font-raleway text-sm md:text-base leading-relaxed transition-all duration-200
                  ${isHighlighted ? 'font-semibold' : 'group-hover/item:font-semibold'}
                `}>
                  {item}
                </span>
              </li>
            );
          })}
        </ul>

      </div>
    </div>
  );
};

// --- Main Component ---
export default function Services() {
  const [highlightedIndex, setHighlightedIndex] = useState(null);

  useEffect(() => {
    // Listen for the custom event dispatched from Footer
    const handleHighlight = (event) => {
      setHighlightedIndex(event.detail);
      
      // Remove highlight after 2 seconds
      setTimeout(() => {
        setHighlightedIndex(null);
      }, 2000);
    };

    window.addEventListener('trigger-service-highlight', handleHighlight);

    return () => {
      window.removeEventListener('trigger-service-highlight', handleHighlight);
    };
  }, []);

  return (
    <>
      <style>
        {`
          @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Raleway:wght@400;600&display=swap');
          .font-playfair { font-family: 'Playfair Display', serif; }
          .font-raleway { font-family: 'Raleway', sans-serif; }
        `}
      </style>

      <section id="services" className="py-24 bg-white">
        {/* Margin adjustment to match footer requirements */}
        <div className="container mx-auto px-4 md:px-12 lg:px-24">

          <div className="text-center mb-16">
            <h2 
              className="text-3xl md:text-4xl lg:text-5xl font-bold font-playfair"
              style={{ color: '#28364b' }}
            >
              Our Services
            </h2>
            <p className="font-raleway text-[#28364b] mt-4 text-lg max-w-3xl mx-auto">
              "Marine Services Simplified. One Partner, Total Solutions."
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
            {serviceCategories.map((category, index) => (
              <ServiceCard 
                key={index} 
                category={category} 
                isHighlighted={highlightedIndex === index}
              />
            ))}
          </div>

          <div className="text-center mt-20">
             <p 
              className="font-raleway text-xl italic font-bold"
              style={{ color: '#28364b' }}
            >
              From basic services to comprehensive logistics solutions—whatever you need, we’ll deliver. Name it, We can do it!
            </p>
          </div>

        </div>
      </section>
    </>
  );
}