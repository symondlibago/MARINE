import Navigation from '@/components/Navigation';
import Hero from '@/components/Hero';
import About from '@/components/About';
import Services from '@/components/Services';
import Agency from '@/components/Agency';
import Team from '@/components/Team';
import Testimonials from '@/components/Testimonials';
import FAQ from '@/components/FAQ';
import Contact from '@/components/Contact';
import Footer from '@/components/Footer';

export default function Home() {
  return (
    <div className="min-h-screen flex flex-col bg-white dark:bg-gray-900">
      <Navigation />
      <main>
        <Hero />
        <About />
        <Services />
        <Agency />
        {/* <Team /> */}
        {/* <Testimonials /> */}
        {/* <FAQ /> */}
        <Contact />
      </main>
      <Footer />
    </div>
  );
}
