import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArrowLeft, BookOpen, CheckCircle2, Heart, ShieldCheck } from "lucide-react";
import { getBookBySlug, formatPrice, plainText } from "@/lib/wordpress";
import { AddToCartButton } from "@/components/AddToCartButton";
import { WishlistButton } from "@/components/WishlistButton";
import { ShareBookButton } from "@/components/ShareBookButton";

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const book = await getBookBySlug(slug);
  if (!book) return { title: "Book not found" };
  const site = (process.env.NEXT_PUBLIC_SITE_URL || "https://freebook.co.in").replace(/\/$/, "");
  return {
    title: book.name,
    description: plainText(book.short_description || book.description).slice(0, 155) || `Explore ${book.name} at FreeBooks.`,
    alternates: { canonical: `${site}/book/${book.slug}` },
    openGraph: { title: book.name, description: plainText(book.short_description || book.description).slice(0, 155), url: `${site}/book/${book.slug}`, images: book.images?.[0]?.src ? [book.images[0].src] : [] },
    twitter: { card: "summary_large_image", title: book.name, images: book.images?.[0]?.src ? [book.images[0].src] : [] }
  };
}

export default async function BookDetailPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const book = await getBookBySlug(slug);
  if (!book) notFound();
  const image = book.images?.[0];
  const price = formatPrice(book);
  const description = plainText(book.description || book.short_description);
  const site = (process.env.NEXT_PUBLIC_SITE_URL || "https://freebook.co.in").replace(/\/$/, "");
  const minor = book.prices?.currency_minor_unit ?? 2;
  const rawPrice = book.prices?.price;
  const amount = rawPrice !== undefined ? Number(rawPrice) / Math.pow(10, minor) : null;
  const productSchema = {
    "@context": "https://schema.org", "@type": "Product", name: book.name,
    description: description || `Explore ${book.name} at FreeBooks.`,
    image: image?.src ? [image.src] : undefined,
    url: `${site}/book/${book.slug}`,
    offers: amount !== null && Number.isFinite(amount) ? {
      "@type": "Offer", priceCurrency: book.prices?.currency_code || "INR", price: amount.toFixed(minor),
      availability: book.is_in_stock ? "https://schema.org/InStock" : "https://schema.org/OutOfStock",
      url: `${site}/book/${book.slug}`, seller: { "@type": "Organization", name: "FreeBooks" }
    } : undefined
  };
  const breadcrumbSchema = {
    "@context": "https://schema.org", "@type": "BreadcrumbList", itemListElement: [
      { "@type": "ListItem", position: 1, name: "Home", item: site },
      { "@type": "ListItem", position: 2, name: "Explore books", item: `${site}/shop` },
      { "@type": "ListItem", position: 3, name: book.name, item: `${site}/book/${book.slug}` }
    ]
  };
  const jsonLd = JSON.stringify([productSchema, breadcrumbSchema]).replace(/</g, "\\u003c");
  return (
    <div className="book-detail-page">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd }} />
      <div className="container">
        <Link href="/shop" className="back-link"><ArrowLeft size={16} /> Back to catalogue</Link>
        <div className="book-detail-grid">
          <div className="detail-image">
            {image?.src ? <Image src={image.src} alt={image.alt || book.name} fill sizes="(max-width: 700px) 90vw, 480px" /> : <div className="book-placeholder"><BookOpen size={60} /><span>FreeBooks</span></div>}
          </div>
          <div className="detail-content">
            <span className="eyebrow">A book with another chapter</span>
            <h1>{book.name}</h1>
            <div className="detail-price">{price || "Price available at checkout"}</div>
            <div className={book.is_in_stock ? "availability available" : "availability unavailable"}>
              <CheckCircle2 size={16} /> {book.is_in_stock ? "Available to order" : "Currently unavailable"}
            </div>
            <p className="detail-description">{description || "More information about this book will be added shortly."}</p>
            <div className="detail-actions">
              {book.is_in_stock ? <AddToCartButton productId={book.id} available={book.is_in_stock} /> : <Link className="button button-outline button-large" href="/shop">Browse other books</Link>}
              <WishlistButton productId={book.id} />
              <ShareBookButton title={book.name} />
            </div>
            <div className="detail-trust"><span><ShieldCheck size={17} /> Reviewed listing</span><span><Heart size={17} /> Reuse with purpose</span></div>
          </div>
        </div>
        <div className="detail-note"><BookOpen size={19} /><p><strong>Give knowledge another life.</strong> FreeBooks helps useful books find new readers. Item condition, shipping options and final purchase details are shown during the order process.</p></div>
      </div>
    </div>
  );
}
