import { ChevronDown } from 'lucide-react';

export default function Hero() {
  const scrollToSection = (id) => {
    const element = document.getElementById(id);
    if (element) {
      element.scrollIntoView({ behavior: 'smooth' });
    }
  };

  return (
    <section
      id="home"
      className="relative h-screen flex items-center justify-center overflow-hidden"
    >
      {/* Background with gradient overlay */}
      <div className="absolute inset-0 z-0">
        <div className="absolute inset-0 bg-black" />
        <div
          className="absolute inset-0 opacity-30"
          style={{
            backgroundImage: `url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 600"><defs><pattern id="dots" x="0" y="0" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="2" fill="%2300D9FF" opacity="0.5"/></pattern></defs><rect width="1200" height="600" fill="none"/><rect width="1200" height="600" fill="url(%23dots)"/></svg>')`,
          }}
        />
        <div className="absolute top-0 right-0 w-96 h-96 bg-[#00D9FF]/10 rounded-full blur-3xl" />
        <div className="absolute bottom-0 left-0 w-96 h-96 bg-[#E63946]/10 rounded-full blur-3xl" />
      </div>

      {/* Content */}
      <div className="relative z-20 container mx-auto px-4 text-center">
        <div className="space-y-6 animate-fade-in">
          {/* Badge */}
          <div className="inline-block">
            <span className="inline-block px-4 py-2 bg-[#00D9FF]/20 border border-[#00D9FF] text-[#00D9FF] rounded-full text-sm font-semibold">
              🌊 Maritime Excellence Since 2020
            </span>
          </div>

          {/* Main Headline */}
          <h1 className="text-5xl md:text-7xl lg:text-8xl font-bold text-white leading-tight">
            Elevate Your
            <span className="block text-transparent bg-clip-text bg-gradient-to-r from-electric-blue to-cyan-400">
              Maritime Operations
            </span>
          </h1>

          {/* Subtitle */}
          <p className="text-xl md:text-2xl text-gray-300 max-w-3xl mx-auto leading-relaxed">
            Streamline your vessel's needs and unlock peak efficiency with Matria Marine's
            comprehensive full-service husbandry solutions. Your trusted partner for seamless
            global port operations.
          </p>

          {/* CTA Buttons */}
          <div className="flex flex-col sm:flex-row gap-4 justify-center pt-8">
            <button
              onClick={() => scrollToSection('services')}
              className="btn-primary"
            >
              Explore Services
            </button>
            <button
              onClick={() => scrollToSection('contact')}
              className="btn-outline"
            >
              Contact Us Today
            </button>
          </div>

          {/* Stats */}
          <div className="grid grid-cols-3 gap-4 md:gap-8 pt-12 max-w-2xl mx-auto">
            <div className="space-y-2">
              <p className="text-3xl md:text-4xl font-bold text-[#00D9FF]">50+</p>
              <p className="text-gray-400 text-sm">Global Ports</p>
            </div>
            <div className="space-y-2">
              <p className="text-3xl md:text-4xl font-bold text-[#00D9FF]">1000+</p>
              <p className="text-gray-400 text-sm">Vessels Served</p>
            </div>
            <div className="space-y-2">
              <p className="text-3xl md:text-4xl font-bold text-[#00D9FF]">24/7</p>
              <p className="text-gray-400 text-sm">Support</p>
            </div>
          </div>
        </div>
      </div>

      {/* Scroll Indicator */}
      <div className="absolute bottom-8 left-1/2 transform -translate-x-1/2 z-20">
        <button
          onClick={() => scrollToSection('about')}
          className="animate-bounce text-[#00D9FF] hover:text-cyan-400 transition-colors"
        >
          <ChevronDown size={32} />
        </button>
      </div>
    </section>
  );
}
