import Link from "next/link";
import { HeartHandshake, Recycle, ShieldCheck, ArrowRight } from "lucide-react";
import { ContentHero } from "@/components/ContentHero";
import { LocaleText } from "@/components/LanguageProvider";

const principles = [
  { icon: Recycle, title: "Keep useful books moving", text: "Books and learning materials that are no longer needed by one reader may still be valuable to another." },
  { icon: ShieldCheck, title: "Review before listing", text: "Every submitted donation is reviewed. Submission alone does not guarantee acceptance or a catalogue listing." },
  { icon: HeartHandshake, title: "Purpose-led proceeds", text: "Proceeds from sales support students in need. Necessary shipping and operating costs may also be met from those proceeds." }
];

export const metadata = { title: "About FreeBooks", description: "Learn about FreeBooks, an initiative helping books and learning materials find new readers." };

export default function AboutPage() {
  return <>
    <ContentHero eyebrow={<LocaleText en="Our story" hi="हमारी कहानी" />} title={<><LocaleText en="A book can finish one chapter" hi="एक किताब एक अध्याय पूरा करके" /><br /><em><LocaleText en="and begin another." hi="दूसरा शुरू कर सकती है।" /></em></>} description={<LocaleText en="FreeBooks helps useful books and learning materials move from shelves where they are no longer needed to readers who can use them." hi="FreeBooks उपयोगी किताबों और अध्ययन सामग्री को उन अलमारियों से नए पाठकों तक पहुँचाने में मदद करता है जहाँ उनकी अब आवश्यकता नहीं है।" />} >
      <Link href="/donate" className="button button-primary button-large">Pass a book forward <ArrowRight size={17} /></Link>
      <Link href="/shop" className="button button-outline button-large">Explore the catalogue</Link>
    </ContentHero>
    <section className="section"><div className="container story-layout"><div><span className="eyebrow">Who we are</span><h2>Knowledge should keep travelling.</h2></div><div className="story-copy"><p>FreeBooks is an initiative of <strong>SamagraShriSai Foundation</strong>. It is built around a simple idea: useful books, notes, magazines, journals and stationery should have the chance to serve more than one person.</p><p>People can submit learning materials for review. Suitable items may be prepared and listed in the catalogue, where readers can discover them. The process is designed to make reuse easier while keeping item condition, availability and pricing visible.</p><p>FreeBooks is a book and learning-material reuse programme. <strong>It does not accept monetary donations through this programme.</strong></p></div></div></section>
    <section className="section values-section"><div className="container"><div className="section-heading"><div><span className="eyebrow">What guides us</span><h2>Simple principles. Practical action.</h2></div></div><div className="value-grid">{principles.map(({icon:Icon,title,text})=><article className="value-card" key={title}><span className="value-icon"><Icon size={24}/></span><h3>{title}</h3><p>{text}</p></article>)}</div></div></section>
    <section className="donate-band"><div className="container donate-band-inner"><div><span className="eyebrow eyebrow-light">Be part of the next chapter</span><h2>Have a book ready to travel?</h2><p>Tell us what you would like to share. Our team will review the submission.</p></div><Link href="/donate" className="button button-cream button-large">Donate books <ArrowRight size={17}/></Link></div></section>
  </>;
}
