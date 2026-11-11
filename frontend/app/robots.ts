import type { MetadataRoute } from "next";

export default function robots(): MetadataRoute.Robots {
  const site = (process.env.NEXT_PUBLIC_SITE_URL || "https://freebook.co.in").replace(/\/$/, "");
  return {
    rules: [{ userAgent: "*", allow: "/", disallow: ["/api/", "/account", "/membership", "/donation-portal", "/cart", "/checkout", "/order-confirmation", "/*?*"] }],
    sitemap: `${site}/sitemap.xml`,
    host: site
  };
}
