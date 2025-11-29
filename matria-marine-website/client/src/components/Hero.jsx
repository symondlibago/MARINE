import { useState, useEffect } from 'react';
import { ChevronDown } from 'lucide-react';

// UPDATED: List of background assets (images and video)
const assets = [
  '/newhome1.jpeg',
  '/newhome2.jpeg',
  '/newhome3.jpeg',
  '/newhome4.jpeg',
  '/newhome5.jpeg',
  '/newhome6.jpeg',
  '/newhome7.jpeg',
  '/newhome8.jpg',
  '/newhome9.jpg',
  '/newhome10.mp4', // Video file
  '/newhome11.jpg',
  '/newhome12.jpg',
  '/newhome13.jpg',
];

export default function Hero() {
  const [currentAssetIndex, setCurrentAssetIndex] = useState(0);

  // Effect to handle the carousel transition
  useEffect(() => {
    const interval = setInterval(() => {
      setCurrentAssetIndex((prevIndex) =>
        prevIndex === assets.length - 1 ? 0 : prevIndex + 1
      );
    }, 5000); // Change asset every 5 seconds

    return () => clearInterval(interval);
  }, []);

  const scrollToSection = (id) => {
    const element = document.getElementById(id);
    if (element) {
      element.scrollIntoView({ behavior: 'smooth' });
    }
  };

  return (
    <>
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

      <section
        id="home"
        className="relative h-screen flex items-center justify-center overflow-hidden"
      >
        {/* Background Asset Carousel */}
        <div className="absolute inset-0 z-0">
          {assets.map((asset, index) => {
            const isVideo = asset.endsWith('.mp4');
            const isActive = index === currentAssetIndex;

            return (
              <div
                key={asset}
                className={`
                  absolute inset-0 w-full h-full transition-opacity duration-1000 ease-in-out
                  ${isActive ? 'opacity-100' : 'opacity-0'}
                `}
              >
                {isVideo ? (
                  <video
                    src={asset}
                    autoPlay
                    muted
                    loop
                    playsInline
                    className="w-full h-full object-cover"
                  />
                ) : (
                  <div
                    className="w-full h-full bg-cover bg-center"
                    style={{ backgroundImage: `url(${asset})` }}
                  />
                )}
              </div>
            );
          })}
          {/* Dark overlay for text readability */}
          <div className="absolute inset-0 bg-black/40 z-10" />
        </div>

        {/* Content */}
        <div className="relative z-20 container mx-auto px-4 text-center">
          <div className="space-y-6 animate-fade-in">

            {/* Main Headline */}
            <h1 className="text-4xl md:text-6xl lg:text-7xl font-bold text-white leading-tight font-playfair">
              Elevate Your
              <span className="block">
                Maritime Operations
              </span>
            </h1>

            {/* Subtitle */}
            <p className="text-lg md:text-lg text-white/90 max-w-3xl mx-auto leading-relaxed font-raleway">
              Streamline your vessel's needs and unlock peak efficiency with Matria Marine's
              comprehensive full-service husbandry solutions. Your trusted partner for seamless
              global port operations.
            </p>

            {/* CTA Buttons */}
            <div className="flex flex-col sm:flex-row gap-4 justify-center pt-8">
              <button
                onClick={() => scrollToSection('services')}
                className="bg-[#cebd88] text-gray-900 font-bold px-8 py-3 rounded-lg hover:bg-[#28364b] hover:text-[#cebd88] transition-all duration-300 transform hover:scale-105 font-raleway"
              >
                Explore Services
              </button>
              <button
                onClick={() => scrollToSection('contact')}
                className="border-2 border-[#f1f0e8] text-[#f1f0e8] font-bold px-8 py-3 rounded-lg hover:bg-[#cebd88] hover:text-gray-900 transition-all duration-300 font-raleway"
              >
                Contact Us Today
              </button>
            </div>

            {/* Stats */}
            <div className="grid grid-cols-3 gap-4 md:gap-8 pt-12 max-w-2xl mx-auto">
              <div className="space-y-2">
                <p className="text-2xl md:text-3xl font-bold text-white">50+</p>
                <p className="text-white/70 text-sm">Global Ports</p>
              </div>
              <div className="space-y-2">
                <p className="text-2xl md:text-3xl font-bold text-white">1000+</p>
                <p className="text-white/70 text-sm">Vessels Served</p>
              </div>
              <div className="space-y-2">
                <p className="text-2xl md:text-3xl font-bold text-white">24/7</p>
                <p className="text-white/70 text-sm">Support</p>
              </div>
            </div>
          </div>
        </div>

        {/* Scroll Indicator */}
        <div className="absolute bottom-8 left-1/2 transform -translate-x-1/2 z-20">
          <button
            onClick={() => scrollToSection('about')}
            className="animate-bounce text-white hover:text-white/80 transition-colors"
          >
            <ChevronDown size={32} />
          </button>
        </div>

        {/* Rope Separator */}
        <div
          className="absolute bottom-0 left-0 w-full h-5 z-20"
          style={{
            backgroundImage: "url('/matria4.jpg')",
            backgroundRepeat: 'repeat-x',
            backgroundPosition: 'center',
            backgroundSize: 'auto 100%',
          }}
        />
      </section>
    </>
  );
}