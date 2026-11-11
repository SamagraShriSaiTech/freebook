import { HeartHandshake, BookOpen, Info, ShieldCheck } from "lucide-react";
import { DonationForm } from "@/components/DonationForm";
import { getFrontendConfig } from "@/lib/wordpress";
import { LocaleText } from "@/components/LanguageProvider";

export const metadata = {
  title: "Donate Books",
  description: "Donate books and learning materials through FreeBooks. Give useful resources another life."
};

export default async function DonatePage() {
  const config = await getFrontendConfig();
  return <div className="donate-page">
    <section className="donate-page-hero"><div className="container"><span className="eyebrow"><HeartHandshake size={15} /> Share knowledge</span><h1><LocaleText en="Give your books" hi="अपनी किताबों को दें" /> <em><LocaleText en="another chapter." hi="एक नया अध्याय।" /></em></h1><p><LocaleText en="Books, notes and learning materials you no longer need may be useful to someone else. Tell us what you would like to share." hi="आपकी अनावश्यक किताबें, नोट्स और अध्ययन सामग्री किसी दूसरे के काम आ सकती हैं। बताइए कि आप क्या साझा करना चाहते हैं।" /></p><div className="donate-hero-points"><span><BookOpen size={16} /> Books & learning resources</span><span><ShieldCheck size={16} /> Reviewed by our team</span></div></div></section>
    <section className="container donate-content">
      <div className="donate-form-intro"><h2><LocaleText en="Donation request form" hi="पुस्तक दान अनुरोध फ़ॉर्म" /></h2><p><LocaleText en="Complete the details below. Our team will review your submission and contact you about the next steps." hi="नीचे विवरण भरें। हमारी टीम आपके अनुरोध की समीक्षा करके आगे की प्रक्रिया के लिए आपसे संपर्क करेगी।" /></p></div>
      <DonationForm turnstileSiteKey={config.turnstileSiteKey} />
      <aside className="donation-notice"><Info size={19} /><div><strong><LocaleText en="Please note before submitting" hi="जमा करने से पहले ध्यान दें" /></strong><ul><li>FreeBooks accepts books and learning materials, not monetary donations.</li><li>Submitting a request does not guarantee acceptance. Each item is reviewed by the team.</li><li>Pickup is not guaranteed; our team will contact you to coordinate the available handover option.</li><li>No tax exemption or donation valuation is represented by this submission.</li></ul></div></aside>
    </section>
  </div>;
}
