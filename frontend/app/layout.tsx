import type { Metadata } from "next";
import { Manrope, Inter } from "next/font/google";
import "./globals.css";
import { SiteHeader } from "@/components/SiteHeader";
import { SiteFooter } from "@/components/SiteFooter";
import { LanguageProvider } from "@/components/LanguageProvider";

const manrope = Manrope({ subsets: ["latin"], variable: "--font-heading", display: "swap" });
const inter = Inter({ subsets: ["latin"], variable: "--font-body", display: "swap" });
const siteUrl = (process.env.NEXT_PUBLIC_SITE_URL || "https://freebook.co.in").replace(/\/$/, "");

export const metadata: Metadata = {
  metadataBase: new URL(siteUrl),
  title: { default: "FreeBooks — Read. Reuse. Rise.", template: "%s | FreeBooks" },
  description: "Donate books, discover affordable reads and help learning resources reach more students.",
  applicationName: "FreeBooks",
  category: "education",
  alternates: { languages: { "en-IN": "/", "x-default": "/" } },
  openGraph: { type: "website", siteName: "FreeBooks", title: "FreeBooks — Read. Reuse. Rise.", description: "Give books another chapter.", url: siteUrl, locale: "en_IN" },
  twitter: { card: "summary_large_image", title: "FreeBooks — Read. Reuse. Rise.", description: "Give books another chapter." },
  robots: { index: true, follow: true }
};

const organizationSchema = {
  "@context": "https://schema.org",
  "@type": "Organization",
  name: "SamagraShriSai Foundation",
  url: siteUrl,
  brand: { "@type": "Brand", name: "FreeBooks" }
};
const websiteSchema = {
  "@context": "https://schema.org",
  "@type": "WebSite",
  name: "FreeBooks",
  url: siteUrl,
  potentialAction: {
    "@type": "SearchAction",
    target: `${siteUrl}/shop?search={search_term_string}`,
    "query-input": "required name=search_term_string"
  }
};
const jsonLd = JSON.stringify([organizationSchema, websiteSchema]).replace(/</g, "\\u003c");

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en-IN">
      <body className={`${manrope.variable} ${inter.variable}`}>
        <LanguageProvider>
          <a className="skip-link" href="#main-content">Skip to main content</a>
          <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd }} />
          <SiteHeader />
          <main id="main-content" tabIndex={-1}>{children}</main>
          <SiteFooter />
        </LanguageProvider>
      </body>
    </html>
  );
}
