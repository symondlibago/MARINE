import { Quote } from 'lucide-react';

export default function About() {
  const quotes = [
    {
      text: 'Marine Services Simplified. One Partner, Total Solutions.',
      author: 'Matria Marine Philosophy',
    },
    {
      text: 'Passionately Delivering Your Peace of Mind at Sea',
      author: 'Our Commitment',
    },
    {
      text: 'We don\'t just aim for satisfaction; we guarantee it.',
      author: 'Service Promise',
    },
  ];

  return (
    <section id="about" className="section-padding bg-white dark:bg-gray-900">
      <div className="container mx-auto px-4">
        {/* Section Header */}
        <div className="text-center mb-16">
          <h2 className="section-title text-black dark:text-white">
            About Matria Marine
          </h2>
          <p className="section-subtitle text-gray-600 dark:text-gray-400">
            Driven by excellence and commitment to your success
          </p>
        </div>

        {/* Main Content */}
        <div className="grid md:grid-cols-2 gap-12 items-center mb-16">
          {/* Left Content */}
          <div className="space-y-6">
            <h3 className="text-3xl font-bold text-black dark:text-white">
              Your Trusted Maritime Partner
            </h3>
            <p className="text-gray-700 dark:text-gray-300 text-lg leading-relaxed">
              At Matria Marine, our commitment to your success begins with our exceptional
              team. We cultivate a highly professional and collaborative environment,
              empowering our dedicated experts to deliver consistent, top-tier service.
            </p>
            <p className="text-gray-700 dark:text-gray-300 text-lg leading-relaxed">
              Our specialists work with unparalleled efficiency and precision, ensuring
              every task is executed with the highest degree of accuracy. This technological
              advantage translates directly into smoother operations and optimized outcomes
              for your vessels.
            </p>
            <p className="text-gray-700 dark:text-gray-300 text-lg leading-relaxed">
              From seamless crew changes and swift spare part deliveries to optimized bunker
              calls and expert spares reconditioning, our dedicated team of experienced agents
              ensures every aspect of your ship's welfare is meticulously managed.
            </p>
          </div>

          {/* Right Visual */}
          <div className="relative">
            <div className="absolute inset-0 bg-gradient-to-br from-electric-blue/20 to-deep-red/20 rounded-2xl blur-2xl" />
            <div className="relative bg-gradient-to-br from-electric-blue/10 to-deep-red/10 rounded-2xl p-8 border border-[#00D9FF]/30">
              <div className="space-y-6">
                <div className="text-5xl font-bold text-[#00D9FF]">
                  20+
                </div>
                <p className="text-xl font-semibold text-black dark:text-white">
                  Years of Maritime Excellence
                </p>
                <p className="text-gray-600 dark:text-gray-400">
                  Trusted by vessel owners and operators worldwide for comprehensive
                  husbandry services and port operations.
                </p>
              </div>
            </div>
          </div>
        </div>

        {/* Quotes Section */}
        <div className="bg-gradient-to-r from-black via-gray-900 to-black rounded-2xl p-12 md:p-16">
          <h3 className="text-3xl font-bold text-white text-center mb-12">
            What Drives Us
          </h3>
          <div className="grid md:grid-cols-3 gap-8">
            {quotes.map((quote, index) => (
              <div
                key={index}
                className="bg-white/5 backdrop-blur border border-[#00D9FF]/30 rounded-xl p-8 hover:border-[#00D9FF]/60 transition-all duration-300"
              >
                <Quote className="text-[#00D9FF] mb-4" size={32} />
                <p className="text-white text-lg font-semibold mb-4 leading-relaxed">
                  "{quote.text}"
                </p>
                <p className="text-[#00D9FF] font-semibold">— {quote.author}</p>
              </div>
            ))}
          </div>
        </div>

        {/* Key Values */}
        <div className="grid md:grid-cols-4 gap-6 mt-16">
          {[
            { icon: '⚡', title: 'Efficiency', desc: 'Swift turnaround times' },
            { icon: '🛡️', title: 'Reliability', desc: 'Consistent quality service' },
            { icon: '🌍', title: 'Global Reach', desc: '50+ strategic ports' },
            { icon: '👥', title: 'Expert Team', desc: 'Highly trained professionals' },
          ].map((value, index) => (
            <div
              key={index}
              className="text-center p-6 rounded-xl bg-gray-50 dark:bg-gray-800 hover:shadow-lg transition-all duration-300"
            >
              <div className="text-4xl mb-3">{value.icon}</div>
              <h4 className="font-bold text-lg text-black dark:text-white mb-2">
                {value.title}
              </h4>
              <p className="text-gray-600 dark:text-gray-400 text-sm">
                {value.desc}
              </p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
