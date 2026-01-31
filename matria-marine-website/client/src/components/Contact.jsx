import { useState } from 'react';
import { Mail, Phone, MapPin, Send, CheckCircle2, Loader2 } from 'lucide-react'; // Added Loader2

export default function Contact() {
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    message: '',
  });

  const [submitted, setSubmitted] = useState(false);
  const [loading, setLoading] = useState(false); // Added loading state

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => ({
      ...prev,
      [name]: value,
    }));
  };

  // UPDATED: This is now a real submit function
  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true); // Start loading spinner

    // This is the URL you get from Formspree
    const WEB3FORMS_ENDPOINT = "https://api.web3forms.com/submit";

  try {
    const response = await fetch(WEB3FORMS_ENDPOINT, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify({
        access_key: "fa3e3097-ab67-4143-8ccb-97dbdfda9f3b",
        name: formData.name,
        email: formData.email,
        message: formData.message,
      }),
    });

    const result = await response.json();

    if (response.ok && result.success) {
      setSubmitted(true);

      setTimeout(() => {
        setFormData({
          name: "",
          email: "",
          message: "",
        });
        setSubmitted(false);
      }, 4000);
    } else {
      alert("There was an error sending your message. Please try again.");
    }
  } catch (error) {
    console.error("Form submission error:", error);
    alert("There was an error sending your message. Please try again.");
  } finally {
    setLoading(false);
  }
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
      content: 'sales@matriamarine.com', // Updated as requested
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
        {/* Container */}
        <div className="container relative z-10 mx-auto px-4 md:px-8 sm:px-12 lg:px-16">
          
          {/* Section Header */}
          <div className="text-center mb-16">
            <h2 
              className="text-3xl md:text-4xl lg:text-5xl font-bold font-playfair"
              style={{ color: '#28364b' }}
            >
              Get In Touch
            </h2>
            <p 
              className="font-raleway text-base mt-4 max-w-xl mx-auto"
              style={{ color: '#28364b' }}
            >
              Let's discuss how we can support your maritime operations.
              We're available 24/7 for all your needs.
            </p>
          </div>

          <div className="grid lg:grid-cols-2 gap-12 max-w-6xl mx-auto">
            
            {/* Left Column: Contact Form */}
            <div className="bg-white/80 backdrop-blur-sm p-8 rounded-lg shadow-2xl">
              {submitted ? (
                <div className="flex flex-col items-center justify-center h-full min-h-[400px]">
                  <CheckCircle2 className="w-16 h-16 text-[#cebd88]" />
                  <h3 
                    className="font-playfair text-2xl font-bold mt-4"
                    style={{ color: '#28364b' }}
                  >
                    Thank You!
                  </h3>
                  <p 
                    className="font-raleway text-base mt-2"
                    style={{ color: '#28364b' }}
                  >
                    Your message has been sent. We'll be in touch soon.
                  </p>
                </div>
              ) : (
                <form onSubmit={handleSubmit} className="space-y-6">
                  <div>
                    <label 
                      className="block text-sm font-bold mb-2 font-raleway"
                      style={{ color: '#28364b' }}
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
                      style={{ color: '#28364b' }}
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
                      style={{ color: '#28364b' }}
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
                    disabled={loading} // Disable button while loading
                    className="w-full bg-[#cebd88] text-gray-900 font-bold px-8 py-3 rounded-lg hover:bg-[#bca971] transition-all duration-300 transform hover:scale-105 font-raleway flex items-center justify-center gap-2 disabled:opacity-70"
                  >
                    {loading ? (
                      <Loader2 size={18} className="animate-spin" />
                    ) : (
                      <Send size={18} />
                    )}
                    <span>{loading ? 'Sending...' : 'Send Message'}</span>
                  </button>
                </form>
              )}
            </div>

            {/* Right Column: Contact Info (MODIFIED) */}
            <div className="space-y-8 flex flex-col justify-center">
              <h3 
                className="text-3xl font-bold font-playfair"
                style={{ color: '#28364b' }}
              >
                Contact Details
              </h3>
              
              {contactInfo.map((info) => {
                const Icon = info.icon;
                // Check if the current item is the Email entry
                const isEmail = info.title === 'Email'; 

                return (
                  <div key={info.title} className="flex items-start gap-4">
                    <div className="flex-shrink-0">
                      <Icon className="w-8 h-8 text-[#cebd88]" />
                    </div>
                    <div>
                      <h4 
                        className="font-playfair text-xl font-bold"
                        style={{ color: '#28364b' }}
                      >
                        {info.title}
                      </h4>
                      
                      {/* Conditional rendering for Email as a hyperlink with underline */}
                      {isEmail ? (
                        <a 
                          href={`mailto:${info.content}`} 
                          className="font-raleway text-base mt-1 text-[#28364b] underline hover:text-[#cebd88] transition-colors"
                          style={{ color: '#8E44AD' }}
                        >
                          {info.content}
                        </a>
                      ) : (
                        <p 
                          className="font-raleway text-base mt-1"
                          style={{ color: '#28364b' }}
                        >
                          {info.content}
                        </p>
                      )}
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