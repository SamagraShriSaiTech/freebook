/**
 * Keep Next Image's server-side optimizer limited to the FreeBooks media hosts.
 * Images hosted elsewhere are still rendered directly in the browser, without
 * asking the Next.js image optimizer to fetch an arbitrary remote URL.
 */
const OPTIMIZED_MEDIA_HOSTS = new Set([
  "cms.freebook.co.in",
  "freebook.co.in",
  "localhost",
  "127.0.0.1"
]);

export function canOptimizeMedia(src: string): boolean {
  if (src.startsWith("/") && !src.startsWith("//")) return true;

  try {
    const url = new URL(src);
    const localHttp = url.protocol === "http:" &&
      (url.hostname === "localhost" || url.hostname === "127.0.0.1");
    const productionHttps = url.protocol === "https:" &&
      OPTIMIZED_MEDIA_HOSTS.has(url.hostname);
    return localHttp || productionHttps;
  } catch {
    return false;
  }
}
