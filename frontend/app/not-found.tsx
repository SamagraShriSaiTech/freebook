import Link from "next/link";
import { BookOpen } from "lucide-react";

export default function NotFound() {
  return <section className="not-found container"><BookOpen size={42} /><span className="eyebrow">Page not found</span><h1>This page has turned a blank page.</h1><p>The book or page you are looking for may have moved or is no longer available.</p><div className="hero-actions"><Link href="/shop" className="button button-primary">Explore the catalogue</Link><Link href="/faqs" className="button button-outline">Visit help centre</Link></div></section>;
}
