import { useState } from 'react';
import { Mail, Phone, MapPin, Send, CheckCircle2 } from 'lucide-react';

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
    // In a real app, you'd send this data to a server or email service
    setSubmitted(true);
    setTimeout(() => {
      setFormData({ name: '', email: '', phone: '', message: '' });
      setSubmitted(false);
    }, 4000);
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

  return (
    <>
      {/* Import the Playfair Display and Raleway fonts */}
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
        id="contact" 
        className="relative py-24 bg-cover bg-center bg-no-repeat"
        style={{ backgroundImage: "url('/matria3.jpg')" }}
      >
        {/* Dark Overlay - REMOVED */}
        
        {/* Container - REMOVED text-shadow */}
        <div className="container relative z-10 mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
          
          {/* Section Header */}
          <div className="text-center mb-16">
            <h2 
              className="text-3xl md:text-4xl lg:text-5xl font-bold font-playfair"
              style={{ color: '#28364b' }} // Changed to dark color
            >
              Get In Touch
            </h2>
            <p 
              className="font-raleway text-base mt-4 max-w-xl mx-auto"
              style={{ color: '#28364b' }} // Changed to dark color
            >
              Let's discuss how we can support your maritime operations.
              We're available 24/7 for all your needs.
            </p>
          </div>

          <div className="grid lg:grid-cols-2 gap-12 max-w-6xl mx-auto">
            
            {/* Left Column: Contact Form (MOVED) */}
            <div className="bg-white/80 backdrop-blur-sm p-8 rounded-lg shadow-2xl">
              {submitted ? (
                <div className="flex flex-col items-center justify-center h-full min-h-[400px]">
                  <CheckCircle2 className="w-16 h-16 text-[#cebd88]" />
                  <h3 
                    className="font-playfair text-2xl font-bold mt-4"
                    style={{ color: '#28364b' }} // Changed to dark color
                  >
                    Thank You!
                  </h3>
                  <p 
                    className="font-raleway text-base mt-2"
                    style={{ color: '#28364b' }} // Changed to dark color
                  >
                    Your message has been sent. We'll be in touch soon.
                  </p>
                </div>
              ) : (
                <form onSubmit={handleSubmit} className="space-y-6">
                  <div>
                    <label 
                      className="block text-sm font-bold mb-2 font-raleway"
                      style={{ color: '#28364b' }} // Changed to dark color
                    >
                      Full Name
                    </label>
                    <input
                      type="text"
                      name="name"
                      value={formData.name}
                      onChange={handleChange}
                      required
                      className="w-full px-4 py-3 rounded-lg bg-white/50 border border-black/20 text-[#28364b] font-raleway text-base focus:outline-none focus:border-[#cebd88] transition-colors placeholder:text-gray-600"
                      placeholder="Your name"
                    />
                  </div>

                  <div>
                    <label 
                      className="block text-sm font-bold mb-2 font-raleway"
                      style={{ color: '#28364b' }} // Changed to dark color
                    >
                      Email Address
                    </label>
                    <input
                      type="email"
                      name="email"
                      value={formData.email}
                      onChange={handleChange}
                      required
                      className="w-full px-4 py-3 rounded-lg bg-white/50 border border-black/20 text-[#28364b] font-raleway text-base focus:outline-none focus:border-[#cebd88] transition-colors placeholder:text-gray-600"
                      placeholder="your@email.com"
                    />
                  </div>

                  <div>
                    <label 
                      className="block text-sm font-bold mb-2 font-raleway"
                      style={{ color: '#28364b' }} // Changed to dark color
                    >
                      Message
                    </label>
                    <textarea
                      name="message"
                      value={formData.message}
                      onChange={handleChange}
                      required
                      rows={5}
                      className="w-full px-4 py-3 rounded-lg bg-white/50 border border-black/20 text-[#28364B] font-raleway text-base focus:outline-none focus:border-[#cebd88] transition-colors resize-none placeholder:text-gray-600"
                      placeholder="Tell us about your needs..."
                    />
                  </div>

                  <button
                    type="submit"
                    className="w-full bg-[#cebd88] text-gray-900 font-bold px-8 py-3 rounded-lg hover:bg-[#bca971] transition-all duration-300 transform hover:scale-105 font-raleway flex items-center justify-center gap-2"
                  >
                    <Send size={18} />
                    <span>Send Message</span>
                  </button>
                </form>
              )}
            </div>

            {/* Right Column: Contact Info (MOVED) */}
            <div className="space-y-8 flex flex-col justify-center">
              <h3 
                className="text-3xl font-bold font-playfair"
                style={{ color: '#28364b' }} // Changed to dark color
              >
                Contact Details
              </h3>
              
              {contactInfo.map((info) => {
                const Icon = info.icon;
                return (
                  <div key={info.title} className="flex items-start gap-4">
                    <div className="flex-shrink-0">
                      <Icon className="w-8 h-8 text-[#cebd88]" />
                    </div>
                    <div>
                      <h4 
                        className="font-playfair text-xl font-bold"
                        style={{ color: '#28364b' }} // Changed to dark color
                      >
                        {info.title}
                      </h4>
                      <p 
                        className="font-raleway text-base mt-1"
                        style={{ color: '#28364b' }} // Changed to dark color
                      >
                        {info.content}
                      </p>
                    </div>
                  </div>
                );
              })}
            </div>

          </div>
        </div>
      </section>
    </>
  );
}