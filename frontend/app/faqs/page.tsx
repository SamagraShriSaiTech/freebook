import Link from "next/link";
import { ContentHero } from "@/components/ContentHero";
import { getPublicFaqs, plainText } from "@/lib/wordpress";
import { LocaleText } from "@/components/LanguageProvider";
export const metadata = { title: "Frequently Asked Questions", description: "Answers about book donations, catalogue listings, orders and FreeBooks." };
const defaults = [
  ["What can I donate?", "FreeBooks accepts suitable books and learning materials such as notes, magazines, journals and stationery. Please provide accurate details and condition information."],
  ["Will every submitted item be accepted?", "No. Every submission is reviewed. The team may approve it, decline it or request more information. Submission does not guarantee acceptance or listing."],
  ["How do I track my donation?", "Use the Donation Portal and the donation reference issued after submission. The portal may ask you to verify your contact details using a one-time code."],
  ["Are the books free?", "Catalogue listings show their price and condition. Any applicable shipping charges and delivery information are displayed during checkout."],
  ["Where do sale proceeds go?", "Proceeds from sales support students in need. Necessary shipping and operating costs may also be met from those proceeds."],
  ["Can I donate money?", "No. The FreeBooks programme accepts books and learning materials, not monetary donations."],
  ["Do I receive a tax exemption certificate?", "The donation acknowledgement is not a tax exemption certificate or a valuation for tax purposes."],
  ["What if an order arrives damaged or incorrect?", "Please contact the team promptly using the official support details configured on the Contact page. The published returns policy explains the applicable process."]
] as const;
export default async function FaqPage(){const posts=await getPublicFaqs();const faqs=posts.length?posts.map(p=>({q:plainText(p.title?.rendered)||"Question",a:plainText(p.content?.rendered)||plainText(p.excerpt?.rendered)})):defaults.map(([q,a])=>({q,a}));return <><ContentHero eyebrow={<LocaleText en="Help centre" hi="सहायता केंद्र" />} title={<><LocaleText en="Good questions." hi="अच्छे प्रश्न।" /><br/><em><LocaleText en="Clear answers." hi="स्पष्ट उत्तर।" /></em></>} description={<LocaleText en="A quick guide to donating books, discovering listings and using FreeBooks." hi="किताबें दान करने, सूचीबद्ध किताबें खोजने और FreeBooks का उपयोग करने के लिए संक्षिप्त मार्गदर्शिका।" />}/><section className="section"><div className="container faq-layout"><div><span className="eyebrow">Frequently asked</span><h2>What you need to know.</h2><p>These answers cover the general process. Specific item, order and delivery details are shown on the relevant listing and checkout pages.</p><Link href="/contact" className="text-link">Still need help? Contact us</Link></div><div className="faq-list">{faqs.map((faq,i)=><details key={`${faq.q}-${i}`} className="faq-item"><summary>{faq.q}</summary><p>{faq.a}</p></details>)}</div></div></section></>}
