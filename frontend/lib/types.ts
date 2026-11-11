export type StoreProduct = {
  id: number;
  name: string;
  slug: string;
  permalink: string;
  description?: string;
  short_description?: string;
  images?: { src: string; alt?: string }[];
  prices?: { price?: string; currency_code?: string; currency_minor_unit?: number };
  is_in_stock?: boolean;
  on_sale?: boolean;
};

export type ImpactMetrics = {
  books_listed?: number | null;
  donations_received?: number | null;
  donations_listed?: number | null;
  donations_sold?: number | null;
  students_supported?: number | null;
  volunteers?: number | null;
  community_partners?: number | null;
  operating_cost_note?: string;
  verified?: boolean;
  note?: string;
};

export type PublicContact = {
  foundationName?: string;
  phone?: string;
  email?: string;
  address?: string;
  whatsapp?: string;
  facebook?: string;
  instagram?: string;
  youtube?: string;
  linkedin?: string;
};

export type FrontendConfig = {
  turnstileSiteKey?: string;
  frontendUrl?: string;
  sitemapUrl?: string;
  contact?: PublicContact;
  impact?: ImpactMetrics;
};

export type PublicCmsPost = {
  id: number;
  date?: string;
  link?: string;
  title?: { rendered?: string };
  content?: { rendered?: string };
  excerpt?: { rendered?: string };
};
