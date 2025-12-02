import React, { useEffect } from 'react';
import { Calendar } from 'lucide-react';

// --- Mock Data for Updates ---
const updates = [
  {
    id: 1,
    title: "Service Delay Advisory: Severe Weather Conditions",
    image: "/about3.jpeg",
    description: "Please be advised that due to the current typhoon warning in the East Asian region, some vessel supply schedules and crew changes may experience minor delays. We are monitoring the situation closely to ensure the safety of all operations.",
    date: "December 01, 2024"
  },
  {
    id: 2,
    title: "Expansion of Warehouse Facilities",
    image: "/about1.jpeg",
    description: "We are excited to announce the expansion of our bonded stores warehouse. This upgrade allows us to stock a wider variety of provisions and technical spares, ensuring even faster turnaround times for your vessel requirements.",
    date: "November 15, 2024"
  },
  {
    id: 3,
    title: "New Safety Protocols Implemented",
    image: "/about2.jpeg",
    description: "Matria Marine Services has successfully rolled out updated safety protocols for all in-port operations. These new measures align with the latest international maritime safety standards to guarantee zero incidents.",
    date: "October 28, 2024"
  }
];

export default function MMSUpdates() {
  
  // Scroll to top on mount
  useEffect(() => {
    window.scrollTo(0, 0);
  }, []);

  return (
    <>
      <style>
        {`
          @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Raleway:wght@400;600;700&display=swap');
          .font-playfair { font-family: 'Playfair Display', serif; }
          .font-raleway { font-family: 'Raleway', sans-serif; }
        `}
      </style>

      {/* Reduced section padding from py-24 to py-16 */}
      <section className="min-h-screen bg-[#F1F0E8] py-16">
        <div className="container mx-auto px-4 md:px-8 lg:px-20">
          
          {/* --- Page Header --- */}
          {/* Reduced margins and text sizes */}
          <div className="text-center mb-12 animate-fade-in-up">
            <h1 className="text-3xl md:text-4xl font-bold font-playfair text-[#28364b] mb-4">
              Matria Marine Services Updates
            </h1>
            <p className="font-raleway text-[#28364b] text-base max-w-2xl mx-auto mb-5">
              Stay informed with the latest news, operational advisories, and announcements from Matria Marine Services.
            </p>
            <div className="w-16 h-1 bg-[#cebd88] mx-auto rounded-full"/>
          </div>

          {/* --- Updates Grid --- */}
          {/* Reduced gap from 8 to 6 */}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {updates.map((item) => (
              <div 
                key={item.id} 
                className="bg-white rounded-xl shadow-md overflow-hidden hover:shadow-xl transition-all duration-300 flex flex-col h-full border border-gray-100 group"
              >
                {/* Image - Reduced height from h-64 to h-48 */}
                <div className="h-48 overflow-hidden relative">
                  <img 
                    src={item.image} 
                    alt={item.title} 
                    className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                  />
                  <div className="absolute inset-0 bg-black/10 group-hover:bg-black/0 transition-colors" />
                </div>

                {/* Content - Reduced padding from p-8 to p-6 */}
                <div className="p-6 flex flex-col flex-grow">
                  {/* Title - Reduced from text-2xl to text-xl */}
                  <h3 className="text-xl font-bold font-playfair text-[#28364b] mb-3 leading-tight group-hover:text-[#cebd88] transition-colors">
                    {item.title}
                  </h3>
                  
                  {/* Description - Added text-sm to make it smaller */}
                  <p className="font-raleway text-sm text-gray-600 mb-5 flex-grow leading-relaxed text-justify">
                    {item.description}
                  </p>

                  {/* Date Footer - Reduced top padding */}
                  <div className="pt-4 border-t border-gray-100 flex text-[#28364b] items-center font-raleway text-xs font-bold">
                    <Calendar size={14} className="mr-2" />
                    {item.date}
                  </div>
                </div>
              </div>
            ))}
          </div>
          
        </div>
      </section>
    </>
  );
}