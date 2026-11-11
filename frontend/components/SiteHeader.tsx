"use client";
import Link from "next/link";
import { BookOpen, ChevronDown, Menu, Search, ShoppingBag } from "lucide-react";
import { LanguageSwitcher, useLanguage } from "@/components/LanguageProvider";

const moreLinks = [
  ["Track donation", "/donation-portal"],
  ["Membership", "/membership"],
  ["How it works", "/how-it-works"],
  ["Volunteer", "/volunteer"],
  ["FAQs", "/faqs"],
  ["Contact", "/contact"],
  ["Transparency", "/transparency"],
  ["Privacy centre", "/privacy-centre"]
] as const;

export function SiteHeader() {
  const { tx } = useLanguage();
  const hindiNav: Record<string, string> = { "Track donation": "दान ट्रैक करें", Membership: "सदस्यता", "How it works": "यह कैसे काम करता है", Volunteer: "स्वयंसेवा", FAQs: "अक्सर पूछे जाने वाले प्रश्न", Contact: "संपर्क", Transparency: "पारदर्शिता", "Privacy centre": "गोपनीयता केंद्र" };
  return (
    <header className="site-header">
      <div className="container header-inner">
        <Link href="/" className="brand" aria-label="FreeBooks home">
          <span className="brand-mark"><BookOpen size={23} strokeWidth={2.3} /></span>
          <span className="brand-copy"><strong>FreeBooks</strong><small>Read. Reuse. Rise.</small></span>
        </Link>
        <nav className="desktop-nav" aria-label="Main navigation">
          <Link href="/shop">{tx("Explore books", "पुस्तकें देखें")}</Link>
          <Link href="/donate">{tx("Donate", "दान करें")}</Link>
          <Link href="/community">{tx("Community", "समुदाय")}</Link>
          <Link href="/impact">{tx("Impact", "प्रभाव")}</Link>
          <Link href="/about">{tx("About", "हमारे बारे में")}</Link>
          <Link href="/account">{tx("Account", "खाता")}</Link>
          <details className="nav-more">
            <summary>{tx("More", "और")} <ChevronDown size={14}/></summary>
            <div className="nav-more-menu">{moreLinks.map(([label,href])=><Link href={href} key={href}>{tx(label, hindiNav[label] || label)}</Link>)}</div>
          </details>
        </nav>
        <div className="header-actions">
          <LanguageSwitcher />
          <Link className="icon-link" href="/shop" aria-label="Search books"><Search size={19} /></Link>
          <Link className="icon-link cart-header-link" href="/cart" aria-label="Shopping cart"><ShoppingBag size={19} /><span>{tx("Cart", "कार्ट")}</span></Link>
          <Link className="button button-primary header-cta" href="/donate">{tx("Donate a book", "पुस्तक दान करें")}</Link>
          <details className="mobile-nav">
            <summary aria-label="Open navigation"><Menu size={23} /></summary>
            <nav aria-label="Mobile navigation">
              <Link href="/shop">{tx("Explore books", "पुस्तकें देखें")}</Link><Link href="/donate">{tx("Donate books", "पुस्तकें दान करें")}</Link><Link href="/donation-portal">{tx("Track donation", "दान ट्रैक करें")}</Link>
              <Link href="/cart">{tx("Shopping cart", "कार्ट")}</Link><Link href="/checkout">{tx("Checkout", "चेकआउट")}</Link><Link href="/account">{tx("My account", "मेरा खाता")}</Link><Link href="/membership">{tx("Membership", "सदस्यता")}</Link><Link href="/community">{tx("Community", "समुदाय")}</Link>
              <Link href="/impact">{tx("Our impact", "हमारा प्रभाव")}</Link><Link href="/about">{tx("About us", "हमारे बारे में")}</Link><Link href="/how-it-works">{tx("How it works", "यह कैसे काम करता है")}</Link>
              <Link href="/volunteer">{tx("Volunteer", "स्वयंसेवा")}</Link><Link href="/faqs">{tx("FAQs", "अक्सर पूछे जाने वाले प्रश्न")}</Link><Link href="/contact">{tx("Contact", "संपर्क")}</Link><Link href="/transparency">{tx("Transparency", "पारदर्शिता")}</Link><Link href="/privacy-centre">{tx("Privacy centre", "गोपनीयता केंद्र")}</Link>
            </nav>
          </details>
        </div>
      </div>
    </header>
  );
}
