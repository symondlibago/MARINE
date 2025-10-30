import { Linkedin, Mail } from 'lucide-react';

export default function Team() {
  const team = [
    {
      name: 'Captain James Morrison',
      role: 'President & CEO',
      bio: 'Maritime industry veteran with 30+ years of experience in global port operations',
      image: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=300&h=300&fit=crop',
      social: { linkedin: '#', email: '#' },
    },
    {
      name: 'Dr. Sarah Chen',
      role: 'Vice President Operations',
      bio: 'Expert in vessel management and logistics optimization across Asia Pacific',
      image: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=300&h=300&fit=crop',
      social: { linkedin: '#', email: '#' },
    },
    {
      name: 'Michael Rodriguez',
      role: 'Co-Partner & Head of Services',
      bio: 'Specialist in marine engineering and comprehensive husbandry services',
      image: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=300&h=300&fit=crop',
      social: { linkedin: '#', email: '#' },
    },
    {
      name: 'Emma Thompson',
      role: 'Co-Founder & Director',
      bio: 'Pioneer in sustainable maritime practices and global network development',
      image: 'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=300&h=300&fit=crop',
      social: { linkedin: '#', email: '#' },
    },
    {
      name: 'David Park',
      role: 'Head of Technical Services',
      bio: 'Master mariner with expertise in mechanical repairs and vessel maintenance',
      image: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=300&h=300&fit=crop',
      social: { linkedin: '#', email: '#' },
    },
    {
      name: 'Lisa Andersen',
      role: 'Director of Client Relations',
      bio: 'Customer success specialist ensuring seamless port operations worldwide',
      image: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=300&h=300&fit=crop',
      social: { linkedin: '#', email: '#' },
    },
  ];

  return (
    <section id="team" className="section-padding bg-gray-50 dark:bg-gray-800">
      <div className="container mx-auto px-4">
        {/* Section Header */}
        <div className="text-center mb-16">
          <h2 className="section-title text-black dark:text-white">
            Our Leadership Team
          </h2>
          <p className="section-subtitle text-gray-600 dark:text-gray-400">
            Experienced professionals dedicated to maritime excellence
          </p>
        </div>

        {/* Team Grid */}
        <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
          {team.map((member, index) => (
            <div
              key={index}
              className="group rounded-xl overflow-hidden card-light dark:card-dark hover:shadow-2xl dark:hover:shadow-electric-blue/30 transition-all duration-300"
            >
              {/* Image Container */}
              <div className="relative overflow-hidden h-64 bg-gradient-to-br from-electric-blue/20 to-deep-red/20">
                <img
                  src={member.image}
                  alt={member.name}
                  className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300"
                />
                <div className="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300" />
              </div>

              {/* Content */}
              <div className="p-6">
                <h3 className="text-xl font-bold text-black dark:text-white mb-1">
                  {member.name}
                </h3>
                <p className="text-[#00D9FF] font-semibold text-sm mb-3">
                  {member.role}
                </p>
                <p className="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                  {member.bio}
                </p>

                {/* Social Links */}
                <div className="flex items-center space-x-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                  <a
                    href={member.social.linkedin}
                    className="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 hover:bg-[#00D9FF] hover:text-black transition-all duration-300"
                  >
                    <Linkedin size={18} />
                  </a>
                  <a
                    href={member.social.email}
                    className="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 hover:bg-[#00D9FF] hover:text-black transition-all duration-300"
                  >
                    <Mail size={18} />
                  </a>
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Team Stats */}
        <div className="mt-16 grid md:grid-cols-4 gap-6">
          {[
            { number: '500+', label: 'Team Members' },
            { number: '30+', label: 'Years Avg Experience' },
            { number: '50+', label: 'Global Locations' },
            { number: '24/7', label: 'Support Available' },
          ].map((stat, index) => (
            <div
              key={index}
              className="text-center p-6 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 hover:border-[#00D9FF]/50 transition-colors"
            >
              <p className="text-3xl md:text-4xl font-bold text-[#00D9FF] mb-2">
                {stat.number}
              </p>
              <p className="text-gray-600 dark:text-gray-400 font-semibold">
                {stat.label}
              </p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
