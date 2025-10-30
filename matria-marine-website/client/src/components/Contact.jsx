import { useState } from 'react';
import { Mail, Phone, MapPin, Linkedin, Facebook, Twitter, Send } from 'lucide-react';

export default function Contact() {
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    message: '',
  });

  const [submitted, setSubmitted] = useState(false);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => ({
      ...prev,
      [name]: value,
    }));
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    console.log('Form submitted:', formData);
    setSubmitted(true);
    setTimeout(() => {
      setFormData({ name: '', email: '', phone: '', message: '' });
      setSubmitted(false);
    }, 3000);
  };

  const contactInfo = [
    {
      icon: MapPin,
      title: 'Address',
      content: '239 #15-92, Lorong 1 Toa Payoh, Singapore 310239',
    },
    {
      icon: Phone,
      title: 'Phone',
      content: '+65 9129 5283',
    },
    {
      icon: Mail,
      title: 'Email',
      content: 'sales@matriamarine.com',
    },
  ];

  const socialLinks = [
    { icon: Linkedin, url: '#', label: 'LinkedIn' },
    { icon: Facebook, url: '#', label: 'Facebook' },
    { icon: Twitter, url: '#', label: 'Twitter' },
  ];

  return (
    <section id="contact" className="section-padding bg-gray-50 dark:bg-gray-800">
      <div className="container mx-auto px-4">
        <div className="text-center mb-16">
          <h2 className="section-title text-black dark:text-white">
            Get In Touch
          </h2>
          <p className="section-subtitle text-gray-600 dark:text-gray-400">
            Let's discuss how we can support your maritime operations
          </p>
        </div>

        <div className="grid lg:grid-cols-2 gap-12">
          <div className="card-light dark:card-dark">
            <h3 className="text-2xl font-bold text-black dark:text-white mb-6">
              Send us a Message
            </h3>

            {submitted ? (
              <div className="flex items-center justify-center h-96 bg-[#00D9FF]/10 rounded-lg border border-[#00D9FF]/30">
                <div className="text-center">
                  <div className="text-5xl mb-4">✓</div>
                  <p className="text-lg font-semibold text-black dark:text-white">
                    Thank you for reaching out!
                  </p>
                  <p className="text-gray-600 dark:text-gray-400 mt-2">
                    We'll get back to you shortly.
                  </p>
                </div>
              </div>
            ) : (
              <form onSubmit={handleSubmit} className="space-y-4">
                <div>
                  <label className="block text-sm font-semibold text-black dark:text-white mb-2">
                    Full Name
                  </label>
                  <input
                    type="text"
                    name="name"
                    value={formData.name}
                    onChange={handleChange}
                    required
                    className="w-full px-4 py-3 rounded-lg bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-black dark:text-white focus:outline-none focus:border-[#00D9FF] transition-colors"
                    placeholder="Your name"
                  />
                </div>

                <div>
                  <label className="block text-sm font-semibold text-black dark:text-white mb-2">
                    Email Address
                  </label>
                  <input
                    type="email"
                    name="email"
                    value={formData.email}
                    onChange={handleChange}
                    required
                    className="w-full px-4 py-3 rounded-lg bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-black dark:text-white focus:outline-none focus:border-[#00D9FF] transition-colors"
                    placeholder="your@email.com"
                  />
                </div>

                <div>
                  <label className="block text-sm font-semibold text-black dark:text-white mb-2">
                    Phone Number
                  </label>
                  <input
                    type="tel"
                    name="phone"
                    value={formData.phone}
                    onChange={handleChange}
                    className="w-full px-4 py-3 rounded-lg bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-black dark:text-white focus:outline-none focus:border-[#00D9FF] transition-colors"
                    placeholder="+65 1234 5678"
                  />
                </div>

                <div>
                  <label className="block text-sm font-semibold text-black dark:text-white mb-2">
                    Message
                  </label>
                  <textarea
                    name="message"
                    value={formData.message}
                    onChange={handleChange}
                    required
                    rows={5}
                    className="w-full px-4 py-3 rounded-lg bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-black dark:text-white focus:outline-none focus:border-[#00D9FF] transition-colors resize-none"
                    placeholder="Tell us about your needs..."
                  />
                </div>

                <button
                  type="submit"
                  className="w-full btn-primary flex items-center justify-center space-x-2"
                >
                  <Send size={18} />
                  <span>Send Message</span>
                </button>
              </form>
            )}
          </div>

          <div className="space-y-8">
            <div className="space-y-4">
              {contactInfo.map((info, index) => {
                const Icon = info.icon;
                return (
                  <div
                    key={index}
                    className="flex items-start space-x-4 p-4 rounded-lg bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 hover:border-[#00D9FF]/50 transition-colors"
                  >
                    <div className="flex-shrink-0">
                      <div className="flex items-center justify-center h-12 w-12 rounded-lg bg-[#00D9FF]/20">
                        <Icon className="h-6 w-6 text-[#00D9FF]" />
                      </div>
                    </div>
                    <div>
                      <h4 className="font-bold text-black dark:text-white">
                        {info.title}
                      </h4>
                      <p className="text-gray-600 dark:text-gray-400 text-sm mt-1">
                        {info.content}
                      </p>
                    </div>
                  </div>
                );
              })}
            </div>

            <div className="rounded-lg overflow-hidden border border-gray-200 dark:border-gray-600 h-64">
              <iframe
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3988.8241458556944!2d103.84851!3d1.33521!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x31da190c5f5f5f5f%3A0x5f5f5f5f5f5f5f5f!2s239%20Lorong%201%20Toa%20Payoh%2C%20Singapore%20310239!5e0!3m2!1sen!2s!4v1234567890"
                width="100%"
                height="100%"
                style={{ border: 0 }}
                allowFullScreen={true}
                loading="lazy"
                referrerPolicy="no-referrer-when-downgrade"
              />
            </div>

            <div>
              <h4 className="font-bold text-black dark:text-white mb-4">
                Follow Us
              </h4>
              <div className="flex items-center space-x-4">
                {socialLinks.map((social, index) => {
                  const Icon = social.icon;
                  return (
                    <a
                      key={index}
                      href={social.url}
                      className="p-3 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 hover:bg-[#00D9FF] hover:text-black transition-all duration-300"
                      title={social.label}
                    >
                      <Icon size={20} />
                    </a>
                  );
                })}
              </div>
            </div>
          </div>
        </div>

        <div className="mt-16 bg-gradient-to-r from-black to-gray-900 rounded-2xl p-8 md:p-12 text-white">
          <div className="grid md:grid-cols-3 gap-8">
            <div>
              <h4 className="font-bold text-lg mb-2">Business Hours</h4>
              <p className="text-gray-400 text-sm">Monday - Friday</p>
              <p className="font-semibold">08:00 AM - 06:00 PM</p>
            </div>
            <div>
              <h4 className="font-bold text-lg mb-2">Weekend</h4>
              <p className="text-gray-400 text-sm">Saturday - Sunday</p>
              <p className="font-semibold">10:00 AM - 04:00 PM</p>
            </div>
            <div>
              <h4 className="font-bold text-lg mb-2">Emergency Support</h4>
              <p className="text-gray-400 text-sm">Available 24/7</p>
              <p className="font-semibold text-[#00D9FF]">+65 9129 5283</p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
