"use client";
import Link from "next/link";
import { BookOpen, HeartHandshake } from "lucide-react";
import { NewsletterSignup } from "@/components/NewsletterSignup";
import { useLanguage } from "@/components/LanguageProvider";

export function SiteFooter() {
  const { tx } = useLanguage();
  return (
    <footer className="site-footer">
      <div className="container"><NewsletterSignup /></div>
      <div className="container footer-grid">
        <div className="footer-brand">
          <Link href="/" className="brand brand-light"><span className="brand-mark"><BookOpen size={22} /></span><span className="brand-copy"><strong>FreeBooks</strong><small>Read. Reuse. Rise.</small></span></Link>
          <p>{tx("Giving books another chapter — and making learning resources easier to access.", "किताबों को एक नया अध्याय देना और सीखने की सामग्री को सबकी पहुँच में लाना।")}</p>
          <span className="foundation-note"><HeartHandshake size={16} /> {tx("An initiative of SamagraShriSai Foundation", "समग्र श्री साई फाउंडेशन की एक पहल")}</span>
        </div>
        <div><h3>{tx("Discover", "खोजें")}</h3><Link href="/shop">{tx("Browse books", "पुस्तकें देखें")}</Link><Link href="/how-it-works">{tx("How it works", "यह कैसे काम करता है")}</Link><Link href="/donate">{tx("Donate books", "पुस्तकें दान करें")}</Link><Link href="/donation-portal">{tx("Track donation", "दान ट्रैक करें")}</Link></div>
        <div><h3>{tx("Community", "समुदाय")}</h3><Link href="/about">{tx("About FreeBooks", "फ्रीबुक्स के बारे में")}</Link><Link href="/impact">{tx("Our impact", "हमारा प्रभाव")}</Link><Link href="/volunteer">{tx("Volunteer", "स्वयंसेवा")}</Link><Link href="/community">{tx("Community updates", "समुदाय की खबरें")}</Link><Link href="/faqs">{tx("FAQs", "अक्सर पूछे जाने वाले प्रश्न")}</Link></div>
        <div><h3>{tx("Information", "जानकारी")}</h3><Link href="/transparency">{tx("Transparency", "पारदर्शिता")}</Link><Link href="/contact">{tx("Contact us", "संपर्क करें")}</Link><Link href="/privacy">{tx("Privacy policy", "गोपनीयता नीति")}</Link><Link href="/terms">{tx("Terms & conditions", "नियम और शर्तें")}</Link><Link href="/shipping-returns">{tx("Shipping & returns", "शिपिंग और वापसी")}</Link></div>
      </div>
      <div className="container footer-bottom"><span>© {new Date().getFullYear()} FreeBooks. {tx("All rights reserved.", "सर्वाधिकार सुरक्षित।")}</span><span>{tx("Books deserve more than one life.", "किताबों को एक से अधिक जीवन मिलना चाहिए।")}</span></div>
    </footer>
  );
}
