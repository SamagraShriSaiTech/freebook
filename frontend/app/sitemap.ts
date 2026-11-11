import type { MetadataRoute } from "next";
import { getSitemapBooks } from "@/lib/wordpress";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const site = (process.env.NEXT_PUBLIC_SITE_URL || "https://freebook.co.in").replace(/\/$/, "");
  const paths = ["", "/shop", "/donate", "/about", "/how-it-works", "/impact", "/transparency", "/faqs", "/volunteer", "/contact", "/community", "/privacy", "/terms", "/shipping-returns"];
  const staticEntries: MetadataRoute.Sitemap = paths.map(path => ({ url: `${site}${path}`, changeFrequency: path === "" || path === "/shop" ? "daily" : "monthly", priority: path === "" ? 1 : path === "/shop" || path === "/donate" ? 0.9 : 0.6 }));
  const books = await getSitemapBooks();
  const bookEntries: MetadataRoute.Sitemap = books.map(book => ({ url: `${site}/book/${book.slug}`, changeFrequency: "weekly", priority: 0.7 }));
  return [...staticEntries, ...bookEntries];
}
