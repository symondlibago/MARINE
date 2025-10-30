import { Wrench, Anchor, Shield, Radio, Droplets, Truck } from 'lucide-react';

export default function Services() {
  const services = [
    {
      icon: Wrench,
      title: 'Mechanical Repairs',
      description: 'ME/AE mechanical components repair and reconditioning with precision engineering',
    },
    {
      icon: Anchor,
      title: 'Ship Spares Supply',
      description: 'OEM and genuine spare parts delivery with complete logistics support',
    },
    {
      icon: Shield,
      title: 'Safety Equipment',
      description: 'Safety equipment servicing and load testing to ensure compliance',
    },
    {
      icon: Radio,
      title: 'Radio & Surveys',
      description: 'Radio survey and UTM pre-docking surveys for vessel certification',
    },
    {
      icon: Droplets,
      title: 'Underwater Services',
      description: 'Underwater diving, inspection, and in-water stern tube seal repair',
    },
    {
      icon: Truck,
      title: 'Logistics & Supply',
      description: 'Fresh water supply, de-slopping, garbage disposal, and OPL services',
    },
  ];

  const additionalServices = [
    'Vessel Renaming (Rope Access)',
    'Crane Load Testing',
    'Complete Logistics Management',
    'Crew Changes',
    'Bunker Optimization',
    'Spares Reconditioning',
  ];

  return (
    <section id="services" className="section-padding bg-gray-50 dark:bg-gray-800">
      <div className="container mx-auto px-4">
        {/* Section Header */}
        <div className="text-center mb-16">
          <h2 className="section-title text-black dark:text-white">
            Our Services
          </h2>
          <p className="section-subtitle text-gray-600 dark:text-gray-400">
            Comprehensive maritime solutions for all your vessel needs
          </p>
        </div>

        {/* Main Services Grid */}
        <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-8 mb-16">
          {services.map((service, index) => {
            const Icon = service.icon;
            return (
              <div
                key={index}
                className="group card-light dark:card-dark hover:shadow-xl dark:hover:shadow-electric-blue/20"
              >
                <div className="flex items-start space-x-4">
                  <div className="flex-shrink-0">
                    <div className="flex items-center justify-center h-12 w-12 rounded-lg bg-[#00D9FF]/20 group-hover:bg-[#00D9FF]/40 transition-colors">
                      <Icon className="h-6 w-6 text-[#00D9FF]" />
                    </div>
                  </div>
                  <div className="flex-1">
                    <h3 className="text-lg font-bold text-black dark:text-white mb-2">
                      {service.title}
                    </h3>
                    <p className="text-gray-600 dark:text-gray-400 text-sm leading-relaxed">
                      {service.description}
                    </p>
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        {/* Additional Services */}
        <div className="bg-gradient-to-r from-black to-gray-900 rounded-2xl p-12 text-white">
          <h3 className="text-3xl font-bold mb-8 text-center">
            And Much More...
          </h3>
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            {additionalServices.map((service, index) => (
              <div
                key={index}
                className="flex items-center space-x-3 p-4 bg-white/5 rounded-lg hover:bg-[#00D9FF]/20 transition-colors"
              >
                <div className="w-2 h-2 bg-[#00D9FF] rounded-full" />
                <span className="font-semibold">{service}</span>
              </div>
            ))}
          </div>
          <p className="text-center text-[#00D9FF] font-bold text-lg mt-8">
            "Name it - We can do it!"
          </p>
        </div>

        {/* Service Guarantee */}
        <div className="mt-16 bg-[#00D9FF]/10 border border-[#00D9FF]/30 rounded-2xl p-8 md:p-12">
          <div className="max-w-3xl mx-auto">
            <h3 className="text-2xl font-bold text-black dark:text-white mb-4 text-center">
              Our Service Guarantee
            </h3>
            <p className="text-gray-700 dark:text-gray-300 text-lg text-center leading-relaxed">
              Through rigorous standards in environment, safety, and workload management, we
              provide a service that is not only highly effective but also responsible and
              reliable. Your vessel's welfare is our priority, managed with precision and care.
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
