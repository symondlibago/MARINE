import { useState } from 'react';
import { ChevronLeft, ChevronRight, Star } from 'lucide-react';

export default function Testimonials() {
  const [currentIndex, setCurrentIndex] = useState(0);

  const testimonials = [
    {
      name: 'Captain Ahmed Hassan',
      role: 'Fleet Manager, Global Shipping Co.',
      rating: 5,
      text: 'Matria Marine has been instrumental in optimizing our fleet operations. Their responsiveness and expertise are unmatched in the industry.',
      image: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=100&h=100&fit=crop',
    },
    {
      name: 'Ms. Priya Sharma',
      role: 'Operations Director, Asian Maritime',
      rating: 5,
      text: 'The level of professionalism and attention to detail from Matria Marine is exceptional. They truly deliver on their promise of peace of mind.',
      image: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=100&h=100&fit=crop',
    },
    {
      name: 'Mr. Johan Petersen',
      role: 'Vessel Owner, Nordic Shipping',
      rating: 5,
      text: 'Outstanding service across all our port calls. Matria Marine has become our go-to partner for comprehensive husbandry services.',
      image: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=100&h=100&fit=crop',
    },
    {
      name: 'Dr. Elena Rossi',
      role: 'Technical Manager, Mediterranean Fleet',
      rating: 5,
      text: 'Their mechanical repair expertise and quick turnaround times have significantly reduced our vessel downtime and operational costs.',
      image: 'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=100&h=100&fit=crop',
    },
    {
      name: 'Mr. Kenji Tanaka',
      role: 'Chief Operations Officer, Pacific Vessels',
      rating: 5,
      text: 'Matria Marine\'s global network and local expertise make them the ideal partner for seamless port operations worldwide.',
      image: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=100&h=100&fit=crop',
    },
  ];

  const nextSlide = () => {
    setCurrentIndex((prevIndex) => (prevIndex + 1) % testimonials.length);
  };

  const prevSlide = () => {
    setCurrentIndex((prevIndex) =>
      prevIndex === 0 ? testimonials.length - 1 : prevIndex - 1
    );
  };

  const visibleTestimonials = [
    testimonials[currentIndex],
    testimonials[(currentIndex + 1) % testimonials.length],
    testimonials[(currentIndex + 2) % testimonials.length],
  ];

  return (
    <section id="testimonials" className="section-padding bg-white dark:bg-gray-900">
      <div className="container mx-auto px-4">
        {/* Section Header */}
        <div className="text-center mb-16">
          <h2 className="section-title text-black dark:text-white">
            Client Testimonials
          </h2>
          <p className="section-subtitle text-gray-600 dark:text-gray-400">
            Hear from our satisfied clients worldwide
          </p>
        </div>

        {/* Testimonials Carousel */}
        <div className="relative">
          {/* Cards Grid */}
          <div className="grid md:grid-cols-3 gap-8 mb-8">
            {visibleTestimonials.map((testimonial, index) => (
              <div
                key={index}
                className="card-light dark:card-dark hover:shadow-xl dark:hover:shadow-electric-blue/20 transition-all duration-300 flex flex-col"
              >
                {/* Stars */}
                <div className="flex items-center space-x-1 mb-4">
                  {Array.from({ length: testimonial.rating }).map((_, i) => (
                    <Star
                      key={i}
                      size={18}
                      className="fill-electric-blue text-[#00D9FF]"
                    />
                  ))}
                </div>

                {/* Quote */}
                <p className="text-gray-700 dark:text-gray-300 text-base leading-relaxed mb-6 flex-grow">
                  "{testimonial.text}"
                </p>

                {/* Author */}
                <div className="flex items-center space-x-4 pt-6 border-t border-gray-200 dark:border-gray-700">
                  <img
                    src={testimonial.image}
                    alt={testimonial.name}
                    className="w-12 h-12 rounded-full object-cover"
                  />
                  <div>
                    <p className="font-bold text-black dark:text-white">
                      {testimonial.name}
                    </p>
                    <p className="text-sm text-gray-600 dark:text-gray-400">
                      {testimonial.role}
                    </p>
                  </div>
                </div>
              </div>
            ))}
          </div>

          {/* Navigation Buttons */}
          <div className="flex items-center justify-center space-x-4">
            <button
              onClick={prevSlide}
              className="p-3 rounded-full bg-gray-100 dark:bg-gray-800 text-black dark:text-white hover:bg-[#00D9FF] hover:text-black transition-all duration-300"
            >
              <ChevronLeft size={24} />
            </button>

            {/* Dots */}
            <div className="flex items-center space-x-2">
              {testimonials.map((_, index) => (
                <button
                  key={index}
                  onClick={() => setCurrentIndex(index)}
                  className={`w-3 h-3 rounded-full transition-all duration-300 ${
                    index === currentIndex
                      ? 'bg-[#00D9FF] w-8'
                      : 'bg-gray-300 dark:bg-gray-600'
                  }`}
                />
              ))}
            </div>

            <button
              onClick={nextSlide}
              className="p-3 rounded-full bg-gray-100 dark:bg-gray-800 text-black dark:text-white hover:bg-[#00D9FF] hover:text-black transition-all duration-300"
            >
              <ChevronRight size={24} />
            </button>
          </div>

          {/* Counter */}
          <div className="text-center mt-8 text-gray-600 dark:text-gray-400">
            <p className="text-sm font-semibold">
              {currentIndex + 1} / {testimonials.length}
            </p>
          </div>
        </div>

        {/* Trust Badge */}
        <div className="mt-16 bg-gradient-to-r from-electric-blue/10 to-deep-red/10 border border-[#00D9FF]/30 rounded-2xl p-8 text-center">
          <p className="text-lg font-semibold text-black dark:text-white mb-2">
            Trusted by 1000+ Vessels Worldwide
          </p>
          <p className="text-gray-600 dark:text-gray-400">
            Join the fleet of satisfied clients who rely on Matria Marine for
            excellence
          </p>
        </div>
      </div>
    </section>
  );
}
