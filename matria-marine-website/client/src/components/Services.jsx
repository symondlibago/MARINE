import React from 'react';
// Import a variety of thematic icons
import {
  Wrench,
  Package,
  LifeBuoy,
  Radio,
  Scan,
  Waves,
  Ship,
  Anchor,
  Truck,
  Droplets,
  Trash,
  Paintbrush, // Changed from 'Paint' to 'Paintbrush'
  Container,
  Sailboat
} from 'lucide-react';

// This list is now an array of objects, pairing text with an icon
const servicesList = [
  { 
    icon: Wrench, 
    text: "ME / AE Mechanical components repair and reconditioning" 
  },
  { 
    icon: Package, 
    text: "Ship Spares Supply (OEM, GENUINE)" 
  },
  { 
    icon: LifeBuoy, 
    text: "Safety Equipment Servicing and Load Test" 
  },
  { 
    icon: Radio, 
    text: "Radio Survey" 
  },
  { 
    icon: Scan, 
    text: "UTM (Pre-docking Survey)" 
  },
  { 
    icon: Waves, 
    text: "Underwater Diving and Inspection" 
  },
  { 
    icon: Wrench, 
    text: "In-water Stern Tube Seal Repair" 
  },
  { 
    icon: Paintbrush, // Changed from 'Paint' to 'Paintbrush'
    text: "Vessel renaming (rope access)" 
  },
  { 
    icon: Anchor, 
    text: "Crane Load Testing" 
  },
  { 
    icon: Truck, 
    text: "Complete Logistics" 
  },
  { 
    icon: Droplets, 
    text: "Fresh Water Supply" 
  },
  { 
    icon: Container, 
    text: "De-slopping" 
  },
  { 
    icon: Trash, 
    text: "Garbage Disposal" 
  },
  { 
    icon: Sailboat, 
    text: "OPL Services" 
  }
];

export default function Services() {
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

      {/* Set a clean white background for this section */}
      <section id="services" className="py-24 bg-white">
        {/* Container with responsive horizontal margins */}
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">

          {/* Main Section Header */}
          <div className="text-center mb-16">
            <h2 
              className="text-3xl md:text-4xl lg:text-5xl font-bold font-playfair"
              style={{ color: '#28364b' }} // Use the requested color
            >
              Our Services
            </h2>
          </div>

          {/* Content Card: A nice container for the service list */}
          <div className="max-w-4xl mx-auto bg-[#F1F0E8]/50 rounded-lg shadow-xl p-8 md:p-12">
            
            {/* Main Quote */}
            <h3 
              className="text-2xl md:text-3xl font-bold font-playfair text-center"
              style={{ color: '#28364b' }}
            >
              "Marine Services Simplified. One Partner, Total Solutions."
            </h3>

            {/* Main Paragraph */}
            <p 
              className="font-raleway text-base text-center mt-6"
              style={{ color: '#28364b' }}
            >
              Matria Marine Services: Your single, expert source for precision mechanical
              repairs, vital surveys, and comprehensive marine solutions, guaranteeing optimal
              vessel performance and seamless operations.
            </p>

            {/* Separator Line */}
            <hr className="my-8 md:my-10 border-gray-300" />

            {/* Services Grid: 2 columns on desktop, 1 on mobile */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
              {servicesList.map((service, index) => {
                // Dynamically assign the icon component
                const Icon = service.icon; 
                return (
                  <div key={index} className="flex items-center gap-3">
                    <Icon 
                      className="w-5 h-5 flex-shrink-0" 
                      style={{ color: '#cebd88' }} // Use accent color
                    />
                    <span 
                      className="font-raleway text-base"
                      style={{ color: '#28364b' }}
                    >
                      {service.text}
                    </span>
                  </div>
                );
              })}
            </div>

            {/* Footer Quote */}
            <p 
              className="font-raleway text-base text-center italic font-semibold mt-10"
              style={{ color: '#28364b' }}
            >
              "Name it - We can do it!"
            </p>

          </div>
        </div>
      </section>
    </>
  );
}