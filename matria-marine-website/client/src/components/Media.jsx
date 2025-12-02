import React, { useEffect } from 'react';

// --- Data Configuration ---
const mediaCategories = [
  {
    title: "Cylinder Liner Reconditioning",
    description: "Expert reconditioning services to restore cylinder liners to their optimal geometric specifications, ensuring extended engine life and peak performance.",
    items: [
      { type: 'img', src: '/cylinder1.jpg' },
      { type: 'img', src: '/cylinder2.jpg' },
      { type: 'img', src: '/cylinder3.png' },
      { type: 'img', src: '/cylinder4.jpg' },
      { type: 'img', src: '/cylinder5.png' },
      { type: 'img', src: '/cylinder6.png' },
      { type: 'img', src: '/cylinder7.jpg' },
      { type: 'img', src: '/cylinder8.jpg' },
      { type: 'img', src: '/cylinder9.jpg' },
    ]
  },
  {
    title: "Hydraulic Cylinder Repair",
    description: "Comprehensive repair and testing of hydraulic cylinders, including chroming, honing, and seal replacement for heavy-duty maritime applications.",
    items: [
      { type: 'img', src: '/hydraulic1.JPG' },
      { type: 'img', src: '/hydraulic2.JPG' },
      { type: 'img', src: '/hydraulic4.JPG' },
      { type: 'img', src: '/hydraulic5.JPG' },
      { type: 'vid', src: '/hydraulic6.MP4' },
      { type: 'vid', src: '/hydraulic7.MP4' },
    ]
  },
  {
    title: "Stern Tube Repair",
    description: "Specialized stern tube seal repair and bonding services performed in-situ or dry dock, guaranteeing watertight integrity and propulsion efficiency.",
    items: [
      { type: 'img', src: '/stern1.JPG' },
      { type: 'img', src: '/stern2.JPG' },
      { type: 'img', src: '/stern3.JPG' },
      { type: 'img', src: '/stern4.JPG' },
      { type: 'img', src: '/stern5.JPG' },
      { type: 'img', src: '/stern6.JPG' },
      { type: 'img', src: '/stern7.JPG' },
      { type: 'img', src: '/stern8.JPG' },
      { type: 'img', src: '/stern9.JPG' },
      { type: 'img', src: '/stern10.JPG' },
      { type: 'img', src: '/stern11.JPG' },
      { type: 'img', src: '/stern12.JPG' },
      { type: 'vid', src: '/stern13.MP4' },
    ]
  },
  {
    title: "Valve Repair",
    description: "Precision overhaul and lapping of various marine valves, ensuring leak-free operation and compliance with safety standards.",
    items: [
      { type: 'img', src: '/valve1.JPG' },
      { type: 'vid', src: '/valve2.MP4' },
      { type: 'img', src: '/valve3.JPG' },
      { type: 'img', src: '/valve4.JPG' },
      { type: 'vid', src: '/valve5.MP4' },
      { type: 'img', src: '/valve6.JPG' },
      { type: 'img', src: '/valve7.JPG' },
    ]
  }
];

export default function Media() {
  
  // Scroll to top on mount
  useEffect(() => {
    window.scrollTo(0, 0);
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

      <section className="min-h-screen bg-[#F1F0E8] py-24">
        <div className="container mx-auto px-4 md:px-8 lg:px-12">
          
          {/* --- Page Header --- */}
          <div className="text-center mb-20 animate-fade-in-up">
            <h1 className="text-3xl md:text-5xl lg:text-6xl font-bold font-playfair text-[#28364b] mb-6">
              Elevate Your Maritime Operations with <br className="hidden md:block"/> Matria Marine Services
            </h1>
            <div className="w-24 h-1 bg-[#cebd88] mx-auto rounded-full"/>
          </div>

          {/* --- Categories Loop --- */}
          <div className="space-y-24">
            {mediaCategories.map((cat, index) => (
              <div key={index} className="bg-white rounded-xl shadow-lg p-8 md:p-12 border border-gray-100">
                
                {/* Category Text */}
                <div className="mb-8">
                  <h2 className="text-2xl md:text-3xl font-bold font-playfair text-[#28364b] mb-4 flex items-center gap-3">
                    <span className="w-3 h-3 bg-[#cebd88] rounded-full"></span>
                    {cat.title}
                  </h2>
                  <p className="font-raleway text-[#28364b] text-lg leading-relaxed max-w-4xl">
                    {cat.description}
                  </p>
                </div>

                {/* Media Grid */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                  {cat.items.map((item, i) => (
                    <div 
                      key={i} 
                      className="group relative overflow-hidden rounded-lg shadow-md aspect-[4/3] bg-gray-100 cursor-pointer"
                    >
                      {item.type === 'img' ? (
                        <img 
                          src={item.src} 
                          alt={`${cat.title} ${i + 1}`} 
                          className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
                          loading="lazy"
                        />
                      ) : (
                        <video 
                          src={item.src} 
                          className="w-full h-full object-cover"
                          controls
                          muted
                          playsInline
                        />
                      )}
                      
                      {/* Overlay for Images */}
                      {item.type === 'img' && (
                        <div className="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none" />
                      )}
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
          
        </div>
      </section>
    </>
  );
}