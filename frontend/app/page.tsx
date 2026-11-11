import Link from "next/link";
import { ArrowRight, BookHeart, BookOpenCheck, HandHeart, Recycle, ShieldCheck, Sparkles } from "lucide-react";
import { BookCard } from "@/components/BookCard";
import { getFeaturedBooks, getFrontendConfig } from "@/lib/wordpress";
import { LocaleText } from "@/components/LanguageProvider";

const categories = [
  { title: "School books", titleHi: "स्कूल की किताबें", detail: "Learning resources for every stage", detailHi: "हर स्तर के लिए अध्ययन सामग्री", icon: "01" },
  { title: "College & exam prep", titleHi: "कॉलेज और परीक्षा तैयारी", detail: "Textbooks, guides and notes", detailHi: "पाठ्यपुस्तकें, गाइड और नोट्स", icon: "02" },
  { title: "Novels & general reading", titleHi: "उपन्यास और सामान्य पठन", detail: "Stories, ideas and new perspectives", detailHi: "कहानियाँ, विचार और नए दृष्टिकोण", icon: "03" },
  { title: "Magazines & stationery", titleHi: "पत्रिकाएँ और स्टेशनरी", detail: "Useful resources ready to be reused", detailHi: "पुनः उपयोग के लिए उपयोगी सामग्री", icon: "04" }
];

export default async function HomePage() {
  const [books, config] = await Promise.all([getFeaturedBooks(), getFrontendConfig()]);
  const impact = config.impact;
  const impactItems = [
    { label: "Books listed for reuse", value: impact?.books_listed },
    { label: "Students supported", value: impact?.students_supported },
    { label: "Donations received", value: impact?.donations_received }
  ];
  return (
    <>
      <section className="hero">
        <div className="hero-orb orb-one" /><div className="hero-orb orb-two" />
        <div className="container hero-grid">
          <div className="hero-copy">
            <span className="eyebrow"><Sparkles size={15} /> <LocaleText en="A second life for every book" hi="हर किताब को एक नया जीवन" /></span>
            <h1><LocaleText en="Pass a book on." hi="किताब आगे बढ़ाएँ।" /><br /><em><LocaleText en="Pass possibility" hi="संभावनाएँ आगे बढ़ाएँ" /></em> <LocaleText en="forward." hi="आगे।" /></h1>
            <p><LocaleText en="Donate the books you no longer need. Discover useful reads at accessible prices. Together, we can keep knowledge moving." hi="अपनी अनावश्यक किताबें दान करें। उचित कीमतों पर उपयोगी किताबें खोजें। मिलकर ज्ञान को आगे बढ़ाएँ।" /></p>
            <div className="hero-actions"><Link href="/donate" className="button button-primary button-large"><LocaleText en="Donate books" hi="किताबें दान करें" /> <ArrowRight size={18} /></Link><Link href="/shop" className="button button-outline button-large"><LocaleText en="Explore books" hi="किताबें देखें" /></Link></div>
            <div className="hero-trust"><span><ShieldCheck size={16} /> <LocaleText en="Reviewed donations" hi="समीक्षित दान" /></span><span><Recycle size={16} /> <LocaleText en="Reuse with purpose" hi="उद्देश्यपूर्ण पुनः उपयोग" /></span></div>
          </div>
          <div className="hero-art" aria-label="Illustration of books being shared">
            <div className="art-sun" />
            <div className="art-note note-top">READ MORE <span>✦</span></div>
            <div className="book-stack">
              <div className="book book-back"><span>KNOWLEDGE</span></div>
              <div className="book book-mid"><span>NEW IDEAS</span></div>
              <div className="book book-front"><BookOpenCheck size={30} /><strong>One book.<br />Many futures.</strong></div>
            </div>
            <div className="art-leaf leaf-a">✳</div><div className="art-leaf leaf-b">✦</div>
            <div className="art-caption"><HandHeart size={17} /> Share what you have. Spark what’s next.</div>
          </div>
        </div>
        <div className="container hero-bottom"><span>DONATE</span><i /><span>DISCOVER</span><i /><span>REUSE</span><i /><span>MAKE AN IMPACT</span></div>
      </section>

      <section className="intro-strip"><div className="container intro-inner"><div><span className="eyebrow"><LocaleText en="The FreeBooks idea" hi="फ्रीबुक्स का विचार" /></span><h2><LocaleText en="Good books should keep travelling." hi="अच्छी किताबों का सफ़र जारी रहना चाहिए।" /></h2></div><p><LocaleText en="Every book shared is a resource saved from a shelf and made available for someone who needs it. FreeBooks helps make that exchange simple, useful and responsible." hi="साझा की गई हर किताब किसी ज़रूरतमंद के लिए उपलब्ध एक उपयोगी संसाधन है। FreeBooks इस आदान-प्रदान को सरल, उपयोगी और ज़िम्मेदार बनाता है।" /></p></div></section>

      <section className="section categories-section"><div className="container"><div className="section-heading"><div><span className="eyebrow"><LocaleText en="Find your next read" hi="अपनी अगली किताब खोजें" /></span><h2><LocaleText en="Explore by category" hi="श्रेणी के अनुसार खोजें" /></h2></div><Link href="/shop" className="text-link"><LocaleText en="View all books" hi="सभी किताबें देखें" /> <ArrowRight size={17} /></Link></div><div className="category-grid">{categories.map((item) => <Link href={`/shop?category=${encodeURIComponent(item.title)}`} className="category-card" key={item.title}><span className="category-number">{item.icon}</span><span className="category-arrow"><ArrowRight size={18} /></span><h3><LocaleText en={item.title} hi={item.titleHi} /></h3><p><LocaleText en={item.detail} hi={item.detailHi} /></p></Link>)}</div></div></section>

      <section className="section featured-section"><div className="container"><div className="section-heading"><div><span className="eyebrow"><LocaleText en="Recently listed" hi="हाल ही में सूचीबद्ध" /></span><h2><LocaleText en="Books looking for a new reader" hi="नई पाठक की प्रतीक्षा में किताबें" /></h2><p className="section-subtitle"><LocaleText en="Browse the latest items available in the FreeBooks catalogue." hi="FreeBooks सूची में उपलब्ध नवीनतम वस्तुएँ देखें।" /></p></div><Link href="/shop" className="text-link"><LocaleText en="Explore catalogue" hi="कैटलॉग देखें" /> <ArrowRight size={17} /></Link></div>{books.length ? <div className="book-grid">{books.map((book) => <BookCard key={book.id} book={book} />)}</div> : <div className="empty-books"><div className="empty-icon"><BookHeart size={26} /></div><h3>New reads are on their way</h3><p>Our catalogue will appear here as soon as books are approved and listed.</p><Link href="/donate" className="button button-outline">Donate the first book <ArrowRight size={16} /></Link></div>}</div></section>

      <section className="donate-band"><div className="container donate-band-inner"><div><span className="eyebrow eyebrow-light"><LocaleText en="Your shelf can change a story" hi="आपकी अलमारी बदल सकती है एक कहानी" /></span><h2><LocaleText en="Have books you’re ready to pass on?" hi="क्या आप अपनी किताबें आगे देना चाहते हैं?" /></h2><p><LocaleText en="Give them a new reader, a new purpose and another chapter." hi="उन्हें नया पाठक, नया उद्देश्य और एक नया अध्याय दें।" /></p></div><Link href="/donate" className="button button-cream button-large"><LocaleText en="Start a donation" hi="दान शुरू करें" /> <ArrowRight size={18} /></Link></div></section>

      <section className="section how-section"><div className="container"><div className="section-heading centered"><div><span className="eyebrow"><LocaleText en="Simple by design" hi="सरल प्रक्रिया" /></span><h2><LocaleText en="From your shelf to someone’s hands" hi="आपकी अलमारी से किसी के हाथों तक" /></h2></div></div><div className="steps-grid"><div className="step-card"><span>01</span><HandHeart size={27} /><h3><LocaleText en="Share your books" hi="अपनी किताबें साझा करें" /></h3><p><LocaleText en="Tell us what you would like to donate and choose an available handover option." hi="बताएँ कि आप क्या दान करना चाहते हैं और उपलब्ध हस्तांतरण विकल्प चुनें।" /></p></div><div className="step-card"><span>02</span><ShieldCheck size={27} /><h3><LocaleText en="We review & list" hi="हम समीक्षा करके सूचीबद्ध करते हैं" /></h3><p><LocaleText en="Our team checks the donation and lists suitable items in the catalogue." hi="हमारी टीम दान की जाँच करके उपयुक्त वस्तुओं को कैटलॉग में सूचीबद्ध करती है।" /></p></div><div className="step-card"><span>03</span><BookOpenCheck size={27} /><h3><LocaleText en="A new reader finds it" hi="नया पाठक इसे पाता है" /></h3><p><LocaleText en="Someone discovers a useful book and gives it another life." hi="किसी को उपयोगी किताब मिलती है और उसे नया जीवन मिलता है।" /></p></div></div></div></section>

      <section className="impact-section"><div className="container impact-inner"><div><span className="eyebrow"><LocaleText en="Progress with purpose" hi="उद्देश्य के साथ प्रगति" /></span><h2><LocaleText en="Small exchanges." hi="छोटे आदान-प्रदान।" /><br /><em><LocaleText en="Meaningful change." hi="सार्थक बदलाव।" /></em></h2><p><LocaleText en="We’ll share verified updates here as the FreeBooks community grows. Impact figures are shown only when confirmed by the platform." hi="FreeBooks समुदाय के बढ़ने के साथ हम सत्यापित अपडेट साझा करेंगे। प्रभाव के आँकड़े केवल पुष्टि होने पर दिखाए जाएँगे।" /></p><Link href="/impact" className="text-link"><LocaleText en="See how impact is measured" hi="प्रभाव कैसे मापा जाता है देखें" /> <ArrowRight size={17} /></Link></div><div className="impact-stats">{impactItems.map((item) => <div className="impact-stat" key={item.label}><strong>{item.value == null ? "—" : item.value.toLocaleString("en-IN")}</strong><span>{item.label}</span></div>)}</div></div></section>
    </>
  );
}
