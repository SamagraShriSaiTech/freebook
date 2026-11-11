import type { FrontendConfig, ImpactMetrics, PublicCmsPost, StoreProduct } from "@/lib/types";

const base = (process.env.WORDPRESS_BASE_URL || "").replace(/\/$/, "");
const headers = { Accept: "application/json" };

export type StoreCategory = {
  id: number;
  name: string;
  slug: string;
  count?: number;
};

export type CatalogueResult = {
  products: StoreProduct[];
  categories: StoreCategory[];
  totalPages: number;
  totalProducts: number;
  currencyMinorUnit: number;
};

export type CatalogueFilters = {
  search?: string;
  category?: string;
  minPrice?: string;
  maxPrice?: string;
  sort?: string;
  page?: number;
};

async function storeFetch<T>(path: string): Promise<{ data: T | null; headers: Headers | null }> {
  if (!base) return { data: null, headers: null };
  try {
    const response = await fetch(`${base}/wp-json/wc/store/v1${path}`, {
      next: { revalidate: 180 },
      headers
    });
    if (!response.ok) return { data: null, headers: response.headers };
    return { data: (await response.json()) as T, headers: response.headers };
  } catch {
    return { data: null, headers: null };
  }
}

export async function getFeaturedBooks(): Promise<StoreProduct[]> {
  const { data } = await storeFetch<StoreProduct[]>("/products?per_page=4&orderby=date&order=desc");
  return Array.isArray(data) ? data : [];
}

export async function getFrontendConfig(): Promise<FrontendConfig> {
  if (!base) return {};
  try {
    const response = await fetch(`${base}/wp-json/freebooks/v1/frontend-config`, {
      next: { revalidate: 300 },
      headers
    });
    if (!response.ok) return {};
    return (await response.json()) as FrontendConfig;
  } catch {
    return {};
  }
}

export async function getCatalogue(filters: CatalogueFilters = {}): Promise<CatalogueResult> {
  const [categoryResult, sampleResult] = await Promise.all([
    storeFetch<StoreCategory[]>("/products/categories?per_page=100&hide_empty=true&orderby=name&order=asc"),
    storeFetch<StoreProduct[]>("/products?per_page=1")
  ]);
  const categories = Array.isArray(categoryResult.data) ? categoryResult.data : [];
  const minorUnit = sampleResult.data?.[0]?.prices?.currency_minor_unit ?? 2;

  const params = new URLSearchParams();
  params.set("per_page", "12");
  params.set("page", String(Math.max(1, filters.page || 1)));
  params.set("orderby", ["date", "title", "price", "popularity", "rating"].includes(filters.sort || "") ? filters.sort! : "date");
  params.set("order", filters.sort === "title" || filters.sort === "price" ? "asc" : "desc");
  if (filters.search?.trim()) params.set("search", filters.search.trim());
  if (filters.category) {
    const selected = categories.find((item) => item.slug === filters.category || String(item.id) === filters.category);
    if (selected) params.set("category", String(selected.id));
  }
  const scale = Math.pow(10, minorUnit);
  if (filters.minPrice && Number.isFinite(Number(filters.minPrice)) && Number(filters.minPrice) >= 0) {
    params.set("min_price", String(Math.round(Number(filters.minPrice) * scale)));
  }
  if (filters.maxPrice && Number.isFinite(Number(filters.maxPrice)) && Number(filters.maxPrice) >= 0) {
    params.set("max_price", String(Math.round(Number(filters.maxPrice) * scale)));
  }
  const { data, headers: responseHeaders } = await storeFetch<StoreProduct[]>(`/products?${params.toString()}`);
  const products = Array.isArray(data) ? data : [];
  return {
    products,
    categories,
    totalPages: Number(responseHeaders?.get("X-WP-TotalPages") || 1),
    totalProducts: Number(responseHeaders?.get("X-WP-Total") || products.length),
    currencyMinorUnit: minorUnit
  };
}

export async function getBookBySlug(slug: string): Promise<StoreProduct | null> {
  const params = new URLSearchParams({ slug, per_page: "1" });
  const { data } = await storeFetch<StoreProduct[]>(`/products?${params.toString()}`);
  return Array.isArray(data) && data.length ? data[0] : null;
}

export function formatPrice(product: StoreProduct): string | null {
  const raw = product.prices?.price;
  const minor = product.prices?.currency_minor_unit;
  const currency = product.prices?.currency_code || "INR";
  if (!raw || minor === undefined) return null;
  const amount = Number(raw) / Math.pow(10, minor);
  if (!Number.isFinite(amount)) return null;
  return new Intl.NumberFormat("en-IN", { style: "currency", currency }).format(amount);
}

export function plainText(html?: string): string {
  return (html || "").replace(/<[^>]*>/g, " ").replace(/&nbsp;/g, " ").replace(/&amp;/g, "&").replace(/\s+/g, " ").trim();
}


async function cmsFetch<T>(path: string): Promise<T | null> {
  if (!base) return null;
  try {
    const response = await fetch(`${base}/wp-json${path}`, {
      next: { revalidate: 300 },
      headers
    });
    if (!response.ok) return null;
    return (await response.json()) as T;
  } catch {
    return null;
  }
}

export async function getImpactMetrics(): Promise<ImpactMetrics | null> {
  return cmsFetch<ImpactMetrics>("/freebooks/v1/impact");
}

export async function getPublicFaqs(): Promise<PublicCmsPost[]> {
  const posts = await cmsFetch<PublicCmsPost[]>("/wp/v2/fbem_faq?per_page=50&orderby=menu_order&order=asc");
  return Array.isArray(posts) ? posts : [];
}

export async function getVolunteerOpportunities(): Promise<PublicCmsPost[]> {
  const posts = await cmsFetch<PublicCmsPost[]>("/wp/v2/fb_volunteer_opportunity?per_page=20&orderby=date&order=desc");
  return Array.isArray(posts) ? posts : [];
}


export type PublicImpactStory = { id: number; title: string; excerpt: string; date: string; image: string | null };

export async function getImpactStories(): Promise<PublicImpactStory[]> {
  const result = await cmsFetch<{ stories?: PublicImpactStory[] }>("/freebooks/v1/impact/stories?per_page=6");
  return Array.isArray(result?.stories) ? result.stories : [];
}

export async function getSitemapBooks(): Promise<StoreProduct[]> {
  const { data } = await storeFetch<StoreProduct[]>("/products?per_page=100&orderby=date&order=desc");
  return Array.isArray(data) ? data : [];
}
