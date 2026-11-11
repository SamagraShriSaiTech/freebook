import Image from "next/image";
import Link from "next/link";
import { BookOpen } from "lucide-react";
import type { StoreProduct } from "@/lib/types";
import { formatPrice } from "@/lib/wordpress";
import { AddToCartButton } from "@/components/AddToCartButton";

export function BookCard({ book }: { book: StoreProduct }) {
  const image = book.images?.[0];
  const price = formatPrice(book);
  return (
    <article className="book-card">
      <Link href={`/book/${book.slug}`} className="book-cover">
        {image?.src ? <Image src={image.src} alt={image.alt || book.name} fill sizes="(max-width: 640px) 45vw, 220px" /> : <div className="book-placeholder"><BookOpen size={42} /><span>FreeBooks</span></div>}
        {!book.is_in_stock && <span className="stock-label">Currently unavailable</span>}
      </Link>
      <div className="book-info">
        <Link href={`/book/${book.slug}`}><h3>{book.name}</h3></Link>
        <div className="book-meta"><span>{price || "See details"}</span><span className={book.is_in_stock ? "in-stock" : "out-stock"}>{book.is_in_stock ? "Available" : "Out of stock"}</span></div>
        {book.is_in_stock && <AddToCartButton productId={book.id} available={book.is_in_stock} compact />}
      </div>
    </article>
  );
}
