import React, { useState } from 'react';
import { TransformWrapper, TransformComponent } from "react-zoom-pan-pinch";
import { 
  Plus, Minus 
} from 'lucide-react';
import WorldMap from '/src/asset/world.svg?react';

// --- Configuration ---

// Merged List: Global Networks
const globalNetworks = [
  // Former Asia Pacific
  { name: "Singapore" },
  { name: "Malaysia" },
  { name: "Indonesia" },
  { name: "Vietnam" },
  { name: "Hong Kong" },
  { name: "China" },
  { name: "Thailand" },
  { name: "South Korea" },
  { name: "Japan" },
  { name: "Philippines" },
  { name: "Sri Lanka" },
  // Former Global Footprint
  { name: "South Africa" },
  { name: "USA" },
  { name: "Panama" },
  { name: "Costa Rica" },
  { name: "Chile" },
  { name: "Peru" },
  { name: "Brazil" },
  { name: "Honduras" },
];

// --- STEP 1: Full Country Name to SVG ID (Code) Map ---
const countryNameToCode = {
  "Singapore": "SG",
  "Malaysia": "MY",
  "Indonesia": "ID",
  "Vietnam": "VN",
  "Hong Kong": "HK",
  "China": "CN",
  "Thailand": "TH",
  "South Korea": "KR",
  "Japan": "JP",
  "Philippines": "PH",
  "Sri Lanka": "LK",
  "South Africa": "ZA",
  "USA": "US",
  "Panama": "PA",
  "Costa Rica": "CR",
  "Chile": "CL",
  "Peru": "PE",
  "Brazil": "BR",
  "Honduras": "HN",
};

// --- STEP 2: Create a REVERSE map for our tooltip ---
const codeToCountryName = Object.fromEntries(
  Object.entries(countryNameToCode).map(([name, code]) => [code, name])
);

// Separate SG and MY for pin/country hover
codeToCountryName["MY"] = "Malaysia & Singapore"; 


export default function Agency() {

  // --- STEP 3: Add State for the Tooltip ---
  const [tooltip, setTooltip] = useState({
    visible: false,
    content: '',
    x: 0,
    y: 0,
  });

  // --- STEP 4: Generate Dynamic CSS for the Map ---
  const allActiveCountryNames = globalNetworks.map(c => c.name);

  const activeCountryCodes = allActiveCountryNames
    .map(name => countryNameToCode[name])
    .filter(Boolean);

  // Add the new pin ID to active selectors for styling the pin
  activeCountryCodes.push('SG_PIN');

  const activeCountrySelector = activeCountryCodes
    .map(code => `.world-map-svg #${code}`) 
    .join(', ');

  const mapStyles = `
    /* --- FIX for Responsiveness --- */
    .world-map-svg {
      width: 100%;
      height: auto; /* Maintains aspect ratio */
    }

    /* Default style for all countries */
    .world-map-svg path {
      fill: #e0e0e0; /* Light gray for non-active countries */
      stroke: #ffffff; /* White border */
      stroke-width: 0.5;
      transition: fill 0.2s ease;
    }

    /* Style for all countries on hover */
    .world-map-svg path:hover {
      fill: #cccccc; /* Slightly darker gray on hover */
    }

    /* --- Style for ONLY our active countries --- */
    ${activeCountrySelector} {
      fill: #cebd88; /* Your brand's gold color */
    }

    /* Style for active countries on hover */
    ${activeCountrySelector}:hover {
      fill: #b9a979; /* A slightly darker gold for hover */
    }
  `;

  // --- STEP 5: Create Event Handlers for the Map ---
  const handleMouseOver = (e) => {
    const target = e.target;
    // Check for both path (countries) and circle (pin) elements
    if ((target.tagName === 'path' || target.tagName === 'circle') && target.id) {
      const countryName = codeToCountryName[target.id];
      if (countryName) {
        setTooltip(prev => ({ ...prev, visible: true, content: countryName }));
      }
    }
  };

  const handleMouseLeave = () => {
    setTooltip(prev => ({ ...prev, visible: false, content: '' }));
  };

  const handleMouseMove = (e) => {
    setTooltip(prev => ({ ...prev, x: e.clientX, y: e.clientY }));
  };


  return (
    <>
      {/* Fonts */}
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

      {/* Inject our dynamic map styles */}
      <style>{mapStyles}</style>

      {/* --- STEP 6: Render the Tooltip --- */}
      {tooltip.visible && (
        <div
          className="font-playfair text-sm px-3 py-1 bg-white/80 backdrop-blur-sm rounded shadow-lg"
          style={{
            position: 'fixed',
            top: tooltip.y + 15, // Offset from cursor
            left: tooltip.x + 15, // Offset from cursor
            pointerEvents: 'none', // Lets mouse events pass through
            color: '#28364b',
            zIndex: 9999,
          }}
        >
          {tooltip.content}
        </div>
      )}

      <section id="agency" className="py-24 bg-white">
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
          <h1 
            className="text-4xl md:text-5xl font-bold font-playfair text-center mb-16"
            style={{ color: '#28364b' }}
          >
            Agency
          </h1>

          {/* 2-Column Layout */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-16 items-start">
            
            {/* LEFT - Text Content */}
            <div className="space-y-6">
              <h2 
                className="text-3xl md:text-4xl font-bold font-playfair"
                style={{ color: '#28364b' }}
              >
                Matria Marine: Your Global Partner for Seamless Port Operations
              </h2>
              
              <p className="font-raleway text-base text-justify" style={{ color: '#28364b' }}>
                Navigate the complexities of international maritime operations with confidence. 
                Matria Marine offers an extensive network of husbandry agency services, ensuring 
                consistent quality and unparalleled support for your vessels at strategic ports 
                across the globe.
              </p>

              <p className="font-raleway text-base text-justify" style={{ color: '#28364b' }}>
                Our expertise spans key maritime hubs, providing you with a single, reliable 
                point of contact for all your port call needs:
              </p>

              <p className="font-raleway text-base text-justify" style={{ color: '#28364b' }}>
                With Matria Marine, you gain the advantage of a truly global presence, 
                simplifying your operations, minimizing downtime, and ensuring efficient, 
                hassle-free port calls, no matter where your journey takes you.
              </p>
            </div>

            {/* RIGHT - List */}
            <div className="space-y-8">
              
              {/* MERGED: All countries in one list - CENTERED */}
              <div className="space-y-4">
                <h3 className="text-4xl font-bold font-playfair text-center" style={{ color: '#28364b' }}>
                  Global Networks
                </h3>
                
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-30 gap-y-5 justify-items-start w-fit mx-auto">
                  {globalNetworks.map((item, index) => (
                    <div key={index} className="flex items-center gap-3">
                      {/* Bullet Point */}
                      <span className="w-1.5 h-1.5 rounded-full bg-[#cebd88] flex-shrink-0" />
                      <span className="font-raleway" style={{ color: '#28364b' }}>
                        {item.name}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

            </div>
          </div>
        </div>
      </section>

      
      {/* Map Section */}
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
          <h2 
            className="text-4xl md:text-5xl font-bold font-playfair text-center mb-12"
            style={{ color: '#28364b' }}
          >
            Global Presence
          </h2>

          <div className="relative w-full overflow-hidden cursor-pointer active:cursor-grabbing">
            <TransformWrapper
              panning={{ velocityDisabled: true }} 
              zooming={{ disabled: false }}
              wheel={{ disabled: false }}
              doubleClick={{ disabled: false }}
            >
              {({ zoomIn, zoomOut, resetTransform, instance }) => (
                <div className="relative">
                  <TransformComponent
                    wrapperStyle={{ width: "100%", height: "100%" }}
                  >
                    <div className="min-w-[1200px] min-h-[500px] lg:min-w-0 lg:min-h-0 relative">
                        <WorldMap 
                            className="world-map-svg w-full max-w-6xl"
                            onMouseOver={handleMouseOver}
                            onMouseLeave={handleMouseLeave}
                            onMouseMove={handleMouseMove}
                        />
                    </div>
                  </TransformComponent>

                  <div className="absolute bottom-4 right-4 z-10 flex flex-col space-y-2">
                      <button
                          onClick={() => zoomIn(0.2, 300)}
                          className="p-2 bg-white rounded-full shadow-lg text-[#28364b] border border-gray-200 hover:bg-gray-100 transition-colors"
                          aria-label="Zoom In"
                      >
                          <Plus className="w-5 h-5" />
                      </button>
                      <button
                          onClick={() => zoomOut(0.2, 300)}
                          className="p-2 bg-white rounded-full shadow-lg text-[#28364b] border border-gray-200 hover:bg-gray-100 transition-colors"
                          aria-label="Zoom Out"
                      >
                          <Minus className="w-5 h-5" />
                      </button>
                  </div>
                </div>
              )}
            </TransformWrapper>
          </div>
        </div>
    </>
  );
}