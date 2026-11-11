import Link from "next/link";
import { Search, SlidersHorizontal, ChevronLeft, ChevronRight, BookOpen } from "lucide-react";
import { BookCard } from "@/components/BookCard";
import { getCatalogue } from "@/lib/wordpress";
import { LocaleText } from "@/components/LanguageProvider";

type SearchParams = Promise<Record<string, string | string[] | undefined>>;

function first(value: string | string[] | undefined): string {
  return Array.isArray(value) ? value[0] || "" : value || "";
}

export const metadata = {
  title: "Explore Books",
  description: "Search the FreeBooks catalogue for books and learning resources."
};

export default async function ShopPage({ searchParams }: { searchParams: SearchParams }) {
  const query = await searchParams;
  const search = first(query.search);
  const category = first(query.category);
  const minPrice = first(query.min_price);
  const maxPrice = first(query.max_price);
  const sort = first(query.sort) || "date";
  const page = Math.max(1, Number(first(query.page)) || 1);
  const result = await getCatalogue({ search, category, minPrice, maxPrice, sort, page });
  const hasFilters = Boolean(search || category || minPrice || maxPrice);

  function pageHref(nextPage: number) {
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (category) params.set("category", category);
    if (minPrice) params.set("min_price", minPrice);
    if (maxPrice) params.set("max_price", maxPrice);
    if (sort) params.set("sort", sort);
    params.set("page", String(nextPage));
    return `/shop?${params.toString()}`;
  }

  return (
    <div className="catalogue-page">
      <section className="catalogue-hero">
        <div className="container">
          <span className="eyebrow"><LocaleText en="The FreeBooks catalogue" hi="FreeBooks कैटलॉग" /> </span>
          <h1>Find a book worth <em><LocaleText en="passing on." hi="आगे बढ़ाया जा सके।" /> </em></h1>
          <p><LocaleText en="Explore books and learning resources listed by our team. Every available item has another reader waiting." hi="हमारी टीम द्वारा सूचीबद्ध किताबें और अध्ययन सामग्री देखें। हर उपलब्ध वस्तु किसी नए पाठक की प्रतीक्षा में है।" /> </p>
          <form className="catalogue-search" action="/shop">
            <Search size={19} />
            <input name="search" defaultValue={search} placeholder="Search by book title, author or keyword…" aria-label="Search books" />
            <button type="submit"><LocaleText en="Search books" hi="किताबें खोजें" /> </button>
          </form>
        </div>
      </section>

      <section className="container catalogue-layout">
        <aside className="catalogue-filters">
          <div className="filter-title"><SlidersHorizontal size={17} /><strong><LocaleText en="Refine results" hi="परिणाम छाँटें" /> </strong></div>
          <form action="/shop" className="filter-form">
            {search && <input type="hidden" name="search" value={search} />}
            <label htmlFor="category"><LocaleText en="Category" hi="श्रेणी" /> </label>
            <select id="category" name="category" defaultValue={category}>
              <option value=""><LocaleText en="All categories" hi="सभी श्रेणियाँ" /> </option>
              {result.categories.map((item) => <option value={item.slug} key={item.id}>{item.name}</option>)}
            </select>
            <label><LocaleText en="Price range" hi="कीमत की सीमा" /> </label>
            <div className="price-inputs">
              <input name="min_price" type="number" min="0" step="1" placeholder="Min ₹" defaultValue={minPrice} aria-label="Minimum price in rupees" />
              <input name="max_price" type="number" min="0" step="1" placeholder="Max ₹" defaultValue={maxPrice} aria-label="Maximum price in rupees" />
            </div>
            <label htmlFor="sort"><LocaleText en="Sort by" hi="क्रमबद्ध करें" /> </label>
            <select id="sort" name="sort" defaultValue={sort}>
              <option value="date"><LocaleText en="Recently added" hi="हाल ही में जोड़ी गई" /> </option>
              <option value="title"><LocaleText en="Title A–Z" hi="शीर्षक A–Z" /> </option>
              <option value="price"><LocaleText en="Price: low to high" hi="कम से अधिक कीमत" /> </option>
              <option value="popularity"><LocaleText en="Most popular" hi="सबसे लोकप्रिय" /> </option>
              <option value="rating"><LocaleText en="Top rated" hi="उच्च रेटिंग" /> </option>
            </select>
            <button type="submit" className="button button-primary filter-submit"><LocaleText en="Apply filters" hi="फ़िल्टर लागू करें" /> </button>
            {hasFilters && <Link href="/shop" className="clear-filters"><LocaleText en="Clear all filters" hi="सभी फ़िल्टर हटाएँ" /> </Link>}
          </form>
        </aside>

        <div className="catalogue-results">
          <div className="catalogue-results-heading">
            <div><h2><LocaleText en="Browse books" hi="किताबें देखें" /> </h2><p>{result.totalProducts ? <LocaleText en={`${result.totalProducts} item${result.totalProducts === 1 ? "" : "s"} found`} hi={`${result.totalProducts} वस्तुएँ मिलीं`} /> : <LocaleText en="No matching books found" hi="कोई मेल खाती किताब नहीं मिली" />}</p></div>
            <span className="catalogue-page-count">Page {page} of {result.totalPages}</span>
          </div>
          {result.products.length ? <div className="book-grid">{result.products.map((book) => <BookCard book={book} key={book.id} />)}</div> : <div className="catalogue-empty"><BookOpen size={32} /><h3>{hasFilters ? "No books match those filters" : "The catalogue is getting ready"}</h3><p>{hasFilters ? "Try a different title, category or price range." : "Approved and published books will appear here as they are added."}</p><Link href="/shop" className="button button-outline"><LocaleText en="View all books" hi="सभी किताबें देखें" /> </Link></div>}
          {result.totalPages > 1 && <nav className="pagination" aria-label="Catalogue pagination">
            {page > 1 ? <Link href={pageHref(page - 1)}><ChevronLeft size={17} /> Previous</Link> : <span className="pagination-disabled"><ChevronLeft size={17} /> Previous</span>}
            <span>{page} / {result.totalPages}</span>
            {page < result.totalPages ? <Link href={pageHref(page + 1)}>Next <ChevronRight size={17} /></Link> : <span className="pagination-disabled">Next <ChevronRight size={17} /></span>}
          </nav>}
        </div>
      </section>
    </div>
  );
}
