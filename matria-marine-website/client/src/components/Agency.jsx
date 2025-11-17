import React from 'react';
// Import all the icons we need
import { 
  Globe, Landmark, Building2, MountainSnow, Mountain, 
  Building, TowerControl, Palmtree, Circle, Sunrise, Ship,
  Waves, Utensils, Leaf, Feather, MapPin // Kept MapPin as the default icon
} from 'lucide-react'; // Thematic icons


const ICONS = {
  Landmark, Building2, MountainSnow, Mountain, Building, TowerControl, 
  Palmtree, Circle, Sunrise, Ship, Globe, Waves, Utensils, Leaf, Feather,
  Default: MapPin
};

const Icon = ({ name, ...props }) => {
  const LucideIcon = ICONS[name] || ICONS.Default;
  return <LucideIcon {...props} />;
};


// Data for the lists
const asiaPacific = [
  { name: "Singapore", icon: "Ship" }, // Busiest port
  { name: "Malaysia", icon: "Building2" }, // Petronas Towers
  { name: "Indonesia", icon: "Waves" }, // Island nation
  { name: "Vietnam", icon: "Mountain" }, // Ha Long Bay
  { name: "Hong Kong", icon: "Building" }, // Skyline
  { name: "China", icon: "TowerControl" }, // Pagoda
  { name: "Thailand", icon: "Utensils" }, // Famous cuisine
  { name: "South Korea", icon: "Circle" }, // Taegeuk
  { name: "Japan", icon: "Sunrise" }, // Land of the Rising Sun
  { name: "Philippines", icon: "Waves" }, // Island nation
  { name: "Sri Lanka", icon: "Leaf" }, // Tea
];

const globalFootprint = [
  { name: "South Africa", icon: "Globe" }, // Global
  { name: "USA", icon: "Landmark" }, // Statue of Liberty
  { name: "Panama", icon: "Ship" }, // Panama Canal
  { name: "Costa Rica", icon: "Feather" }, // Quetzal (biodiversity)
  { name: "Chile", icon: "MountainSnow" }, // Andes
  { name: "Peru", icon: "MountainSnow" }, // Andes
  { name: "Brazil", icon: "Palmtree" }, // Amazon
  { name: "Honduras", icon: "Palmtree" }, // Tropical
];

export default function Agency() {

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

      {/* Set a clean white background */}
      <section id="agency" className="py-24 bg-white">
        {/* Container with responsive horizontal margins */}
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">

          {/* SECTION HEADER */}
          <h1 
            className="text-4xl md:text-5xl font-bold font-playfair text-center mb-16"
            style={{ color: '#28364b' }}
          >
            Agency
          </h1>

          {/* Main Grid: Back to 2 columns on large screens */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-16 items-start">
            
            {/* Left Column: Text Content */}
            <div className="space-y-6">
              <h2 
                className="text-3xl md:text-4xl font-bold font-playfair"
                style={{ color: '#28364b' }}
              >
                Matria Marine: Your Global Partner for Seamless Port Operations
              </h2>
              
              <p 
                className="font-raleway text-base text-justify"
                style={{ color: '#28364b' }}
              >
                Navigate the complexities of international maritime operations with confidence. Matria Marine offers an extensive network of husbandry agency services, ensuring consistent quality and unparalleled support for your vessels at strategic ports across the globe.
              </p>
              <p 
                className="font-raleway text-base text-justify"
                style={{ color: '#28364b' }}
              >
                Our expertise spans key maritime hubs, providing you with a single, reliable point of contact for all your port call needs:
              </p>

              <p 
                className="font-raleway text-base text-justify"
                style={{ color: '#28364b' }}
              >
                With Matria Marine, you gain the advantage of a truly global presence, simplifying your operations, minimizing downtime, and ensuring efficient, hassle-free port calls, no matter where your journey takes you.
              </p>
            </div>

            {/* Right Column: Lists */}
            <div className="space-y-8">
              {/* Asia Pacific List */}
              <div className="space-y-4">
                <h3 
                  className="text-2xl font-bold font-playfair"
                  style={{ color: '#28364b' }}
                >
                  Asia Pacific Powerhouse
                </h3>
                {/* This grid will remain left-aligned within its column */}
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-2">
                  {asiaPacific.map((item, index) => (
                    <div key={index} className="flex items-center gap-3">
                      <Icon name={item.icon} className="w-5 h-5 flex-shrink-0 text-[#cebd88]" />
                      <span className="font-raleway text-base" style={{ color: '#28364b' }}>
                        {item.name}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Global Footprint List */}
              <div className="space-y-4">
                <h3 
                  className="text-2xl font-bold font-playfair"
                  style={{ color: '#28364b' }}
                >
                  Expanding Global Footprint
                </h3>
                {/* This grid will also remain left-aligned */}
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-2">
                  {globalFootprint.map((item, index) => (
                    <div key={index} className="flex items-center gap-3">
                      <Icon name={item.icon} className="w-5 h-5 flex-shrink-0 text-[#cebd88]" />
                      <span className="font-raleway text-base" style={{ color: '#28364b' }}>
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

      {/* NEW SVG MAP SECTION */}
      <section id="agency-maps" className="pb-24 bg-white">
        <div className="container mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
          
          {/* MAP SECTION HEADER */}
          <h2 
            className="text-4xl md:text-5xl font-bold font-playfair text-center mb-16"
            style={{ color: '#28364b' }}
          >
            Global Networks
          </h2>

          {/* Grid for the maps - REMOVED GAP */}
          <div className="grid grid-cols-1 md:grid-cols-4">
            
            {/* Peru Map - Added relative positioning */}
            <div className="relative flex flex-col items-center">
              <img 
                src="/peru.svg" 
                alt="Map of Peru" 
                className="w-full h-auto max-h-80 object-contain"
              />
              {/* New Pin Icon and Label - Absolute Positioned - CENTERED */}
              <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex items-center gap-2 bg-white/70 backdrop-blur-sm px-3 py-1 rounded-full shadow-lg">
                <MapPin className="w-5 h-5 flex-shrink-0 text-[#cebd88]" />
                <span 
                  className="text-lg font-bold font-playfair"
                  style={{ color: '#28364b' }}
                >
                  Peru
                </span>
              </div>
            </div>

            {/* South Africa Map - Added relative positioning */}
            <div className="relative flex flex-col items-center">
              <img 
                src="/southafrica.svg" 
                alt="Map of South Africa" 
                className="w-full h-auto max-h-80 object-contain"
              />
              {/* New Pin Icon and Label - Absolute Positioned - CENTERED */}
              <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex items-center gap-2 bg-white/70 backdrop-blur-sm px-3 py-1 rounded-full shadow-lg">
                <MapPin className="w-5 h-5 flex-shrink-0 text-[#cebd88]" />
                <span 
                  className="text-lg font-bold font-playfair"
                  style={{ color: '#28364b' }}
                >
                  South Africa
                </span>
              </div>
            </div>

            {/* Costa Rica Map - Added relative positioning */}
            <div className="relative flex flex-col items-center">
              <img 
                src="/costarica.svg" 
                alt="Map of Costa Rica" 
                className="w-full h-auto max-h-80 object-contain"
              />
              {/* New Pin Icon and Label - Absolute Positioned - CENTERED */}
              <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex items-center gap-2 bg-white/70 backdrop-blur-sm px-3 py-1 rounded-full shadow-lg">
                <MapPin className="w-5 h-5 flex-shrink-0 text-[#cebd88]" />
                <span 
                  className="text-lg font-bold font-playfair"
                  style={{ color: '#28364b' }}
                >
                  Costa Rica
                </span>
              </div>
            </div>

            {/* Honduras Map - Added relative positioning */}
            <div className="relative flex flex-col items-center">
              <img 
                src="/honduras.svg" 
                alt="Map of Honduras" 
                className="w-full h-auto max-h-80 object-contain"
              />
              {/* New Pin Icon and Label - Absolute Positioned - CENTERED */}
              <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex items-center gap-2 bg-white/70 backdrop-blur-sm px-3 py-1 rounded-full shadow-lg">
                <MapPin className="w-5 h-5 flex-shrink-0 text-[#cebd88]" />
                <span 
                  className="text-lg font-bold font-playfair"
                  style={{ color: '#28364b' }}
                >
                  Honduras
                </span>
              </div>
            </div>

          </div>
        </div>
      </section>
    </>
  );
}