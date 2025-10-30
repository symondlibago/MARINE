import { useState } from 'react';
import { ChevronDown } from 'lucide-react';

export default function FAQ() {
  const [openIndex, setOpenIndex] = useState(0);

  const faqs = [
    {
      question: 'What services does Matria Marine provide?',
      answer:
        'We offer comprehensive maritime services including mechanical repairs, ship spares supply, safety equipment servicing, underwater diving and inspection, crew changes, bunker optimization, and complete port husbandry services across 50+ global locations.',
    },
    {
      question: 'How quickly can you respond to emergency requests?',
      answer:
        'We provide 24/7 emergency support with rapid response times. Our dedicated team can typically respond to urgent requests within hours, ensuring minimal vessel downtime.',
    },
    {
      question: 'Which ports do you operate in?',
      answer:
        'Matria Marine operates across a global network including Singapore, Malaysia, Indonesia, Vietnam, Hong Kong, China, Thailand, South Korea, Japan, Philippines, Sri Lanka, South Africa, USA, Panama, Costa Rica, Chile, Peru, Brazil, and Honduras.',
    },
    {
      question: 'Do you supply genuine OEM spare parts?',
      answer:
        'Yes, we supply both OEM (Original Equipment Manufacturer) and genuine spare parts with complete logistics support. We ensure quality and authenticity for all components.',
    },
    {
      question: 'What is your turnaround time for mechanical repairs?',
      answer:
        'Our average turnaround time for mechanical repairs is 24-48 hours, depending on the complexity of the work. We prioritize efficiency without compromising quality.',
    },
    {
      question: 'Do you offer crew change services?',
      answer:
        'Yes, we provide seamless crew change coordination and management. Our team handles all logistics to ensure smooth transitions with minimal operational disruption.',
    },
    {
      question: 'Are your services available 24/7?',
      answer:
        'Our emergency support is available 24/7. Regular business hours are Monday-Friday 08:00 AM - 06:00 PM and Saturday-Sunday 10:00 AM - 04:00 PM.',
    },
    {
      question: 'How do I get a quote for services?',
      answer:
        'Contact us directly via phone (+65 9129 5283), email (sales@matriamarine.com), or use our contact form. Our team will provide a detailed quote based on your specific needs.',
    },
  ];

  return (
    <section className="section-padding bg-white dark:bg-gray-900">
      <div className="container mx-auto px-4 max-w-3xl">
        <div className="text-center mb-16">
          <h2 className="section-title text-black dark:text-white">
            Frequently Asked Questions
          </h2>
          <p className="section-subtitle text-gray-600 dark:text-gray-400">
            Find answers to common questions about our services
          </p>
        </div>

        <div className="space-y-4">
          {faqs.map((faq, index) => (
            <div
              key={index}
              className="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden hover:border-[#00D9FF]/50 transition-colors"
            >
              <button
                onClick={() => setOpenIndex(openIndex === index ? null : index)}
                className="w-full flex items-center justify-between p-6 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
              >
                <h3 className="text-lg font-bold text-black dark:text-white text-left">
                  {faq.question}
                </h3>
                <ChevronDown
                  size={24}
                  className={`flex-shrink-0 text-[#00D9FF] transition-transform duration-300 ${
                    openIndex === index ? 'transform rotate-180' : ''
                  }`}
                />
              </button>

              {openIndex === index && (
                <div className="px-6 py-4 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600">
                  <p className="text-gray-700 dark:text-gray-300 leading-relaxed">
                    {faq.answer}
                  </p>
                </div>
              )}
            </div>
          ))}
        </div>

        <div className="mt-12 text-center">
          <p className="text-gray-600 dark:text-gray-400 mb-4">
            Didn't find your answer?
          </p>
          <button className="btn-primary">
            Contact Our Team
          </button>
        </div>
      </div>
    </section>
  );
}