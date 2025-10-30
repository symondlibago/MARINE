import { ExternalLink } from 'lucide-react';

export default function Projects() {
  const projects = [
    {
      title: 'Container Ship Maintenance',
      category: 'Mechanical Repair',
      description: 'Complete ME/AE overhaul and reconditioning for 8000 TEU container vessel',
      image: 'https://images.unsplash.com/photo-1578575437980-63e5f900b51f?w=600&h=400&fit=crop',
      stats: '48 hours turnaround',
    },
    {
      title: 'Bulk Carrier Spares Supply',
      category: 'Logistics',
      description: 'Emergency spare parts delivery and installation for bulk carrier in Singapore',
      image: 'https://images.unsplash.com/photo-1537996051336-ea92d56b9d4d?w=600&h=400&fit=crop',
      stats: '24 hours delivery',
    },
    {
      title: 'Tanker Safety Certification',
      category: 'Safety Services',
      description: 'Complete safety equipment servicing and load testing for oil tanker',
      image: 'https://images.unsplash.com/photo-1581092918056-0c4c3acd3789?w=600&h=400&fit=crop',
      stats: 'Full compliance',
    },
    {
      title: 'Underwater Inspection',
      category: 'Diving Services',
      description: 'Comprehensive underwater hull inspection and stern tube seal repair',
      image: 'https://images.unsplash.com/photo-1559827260-dc66d52bef19?w=600&h=400&fit=crop',
      stats: 'Zero downtime',
    },
    {
      title: 'Crew Change Operations',
      category: 'Port Operations',
      description: 'Seamless crew change coordination for multinational vessel fleet',
      image: 'https://images.unsplash.com/photo-1552664730-d307ca884978?w=600&h=400&fit=crop',
      stats: '100% on-time',
    },
    {
      title: 'Global Port Agency',
      category: 'Husbandry Services',
      description: 'Full-service port operations across Asia Pacific and global network',
      image: 'https://images.unsplash.com/photo-1494783367193-149034c05e41?w=600&h=400&fit=crop',
      stats: '50+ ports',
    },
  ];

  return (
    <section id="projects" className="section-padding bg-white dark:bg-gray-900">
      <div className="container mx-auto px-4">
        {/* Section Header */}
        <div className="text-center mb-16">
          <h2 className="section-title text-black dark:text-white">
            Recent Projects
          </h2>
          <p className="section-subtitle text-gray-600 dark:text-gray-400">
            Showcasing our expertise and commitment to excellence
          </p>
        </div>

        {/* Projects Grid */}
        <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
          {projects.map((project, index) => (
            <div
              key={index}
              className="group overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 hover:border-[#00D9FF]/50 transition-all duration-300 hover:shadow-xl dark:hover:shadow-electric-blue/20"
            >
              {/* Image Container */}
              <div className="relative overflow-hidden h-48 bg-gray-200 dark:bg-gray-800">
                <img
                  src={project.image}
                  alt={project.title}
                  className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300"
                />
                <div className="absolute inset-0 bg-black/40 group-hover:bg-black/60 transition-colors" />
                <div className="absolute top-4 right-4">
                  <span className="inline-block px-3 py-1 bg-[#00D9FF] text-black text-xs font-bold rounded-full">
                    {project.category}
                  </span>
                </div>
              </div>

              {/* Content */}
              <div className="p-6">
                <h3 className="text-xl font-bold text-black dark:text-white mb-2 group-hover:text-[#00D9FF] transition-colors">
                  {project.title}
                </h3>
                <p className="text-gray-600 dark:text-gray-400 text-sm mb-4 leading-relaxed">
                  {project.description}
                </p>

                {/* Stats */}
                <div className="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                  <span className="text-sm font-semibold text-[#00D9FF]">
                    {project.stats}
                  </span>
                  <button className="text-gray-600 dark:text-gray-400 hover:text-[#00D9FF] transition-colors">
                    <ExternalLink size={18} />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* CTA */}
        <div className="mt-16 text-center">
          <p className="text-gray-600 dark:text-gray-400 mb-6 text-lg">
            Ready to see your vessel in our success stories?
          </p>
          <button className="btn-primary">
            View All Projects
          </button>
        </div>
      </div>
    </section>
  );
}
