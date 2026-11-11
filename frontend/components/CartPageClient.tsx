"use client";
import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import Image from "next/image";
import { ArrowRight, BookOpen, Minus, Plus, ShoppingBag, Trash2 } from "lucide-react";

type CartItem = { key: string; id: number; name: string; quantity: number; quantity_limits?: { minimum?: number; maximum?: number; editable?: boolean }; prices?: { price?: string; currency_code?: string; currency_minor_unit?: number; currency_symbol?: string }; totals?: { line_total?: string }; images?: { src: string; alt?: string }[]; permalink?: string };
type CartData = { items?: CartItem[]; totals?: Record<string, string | number>; coupons?: { code: string; label?: string }[]; errors?: { message?: string }[] };
function money(raw: string | number | undefined, currency = "INR", minor = 2) { const n = Number(raw || 0) / Math.pow(10, Number(minor)); return new Intl.NumberFormat("en-IN", { style: "currency", currency }).format(Number.isFinite(n) ? n : 0); }

export function CartPageClient() {
  const [cart, setCart] = useState<CartData | null>(null);
  const [loading, setLoading] = useState(true);
  const [busyKey, setBusyKey] = useState("");
  const [coupon, setCoupon] = useState("");
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true); setError("");
    try { const r = await fetch("/api/store/cart", { cache: "no-store" }); const d = await r.json(); if (!r.ok) throw new Error(d.message || "Your cart could not be loaded."); setCart(d); }
    catch (e) { setError(e instanceof Error ? e.message : "Your cart could not be loaded."); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  async function mutate(endpoint: string, body: Record<string, unknown>, key = "cart") {
    setBusyKey(key); setNotice(""); setError("");
    try { const r = await fetch(`/api/store/${endpoint}`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }); const d = await r.json(); if (!r.ok) throw new Error(d.message || d.code || "The cart could not be updated."); setCart(d); return true; }
    catch (e) { setError(e instanceof Error ? e.message : "The cart could not be updated."); return false; }
    finally { setBusyKey(""); }
  }

  const totals = cart?.totals || {};
  const currency = String(totals.currency_code || "INR");
  const minor = Number(totals.currency_minor_unit ?? 2);
  const items = cart?.items || [];

  return <div className="commerce-page container">
    <div className="commerce-heading"><span className="eyebrow">Your reading list</span><h1>Shopping <em>cart</em></h1><p>Review your books and learning supplies before checkout.</p></div>
    {loading ? <div className="commerce-loading">Loading your cart…</div> : error && !cart ? <div className="commerce-alert error">{error}<button onClick={() => void load()}>Try again</button></div> : items.length === 0 ? <div className="empty-cart"><span><ShoppingBag size={30}/></span><h2>Your cart is waiting for a good read.</h2><p>Explore the catalogue and add a book to get started.</p><Link className="button button-primary" href="/shop">Explore books <ArrowRight size={16}/></Link></div> : <div className="cart-layout">
      <section className="cart-items-panel">
        <div className="cart-panel-head"><strong>{items.length} {items.length === 1 ? "item" : "items"}</strong><Link href="/shop">Continue shopping</Link></div>
        {items.map((item) => <article className="cart-line" key={item.key}>
          <Link className="cart-line-image" href={item.permalink || "/shop"}>{item.images?.[0]?.src ? <Image src={item.images[0].src} alt={item.images[0].alt || item.name} fill sizes="100px"/> : <BookOpen size={30}/>}</Link>
          <div className="cart-line-info"><Link href={item.permalink || "/shop"}><h3>{item.name}</h3></Link><span>{money(item.prices?.price, item.prices?.currency_code || currency, item.prices?.currency_minor_unit ?? minor)} each</span>
            <div className="cart-line-actions">
              <div className="quantity-control"><button aria-label="Decrease quantity" disabled={busyKey === item.key || item.quantity <= (item.quantity_limits?.minimum || 1)} onClick={() => void mutate("cart/update-item", { key: item.key, quantity: item.quantity - 1 }, item.key)}><Minus size={13}/></button><span>{item.quantity}</span><button aria-label="Increase quantity" disabled={busyKey === item.key || (item.quantity_limits?.maximum !== undefined && item.quantity >= item.quantity_limits.maximum)} onClick={() => void mutate("cart/update-item", { key: item.key, quantity: item.quantity + 1 }, item.key)}><Plus size={13}/></button></div>
              <button className="remove-cart-item" disabled={busyKey === item.key} onClick={() => void mutate("cart/remove-item", { key: item.key }, item.key)}><Trash2 size={14}/> Remove</button>
            </div>
          </div>
          <strong className="cart-line-total">{money(item.totals?.line_total, currency, minor)}</strong>
        </article>)}
        {error && <div className="commerce-alert error">{error}</div>}
        <form className="coupon-form" onSubmit={async (e) => { e.preventDefault(); if (!coupon.trim()) return; const ok = await mutate("cart/apply-coupon", { code: coupon.trim() }); if (ok) { setCoupon(""); setNotice("Coupon applied."); } }}>
          <label htmlFor="coupon-code">Have a coupon?</label><div><input id="coupon-code" value={coupon} onChange={(e) => setCoupon(e.target.value)} placeholder="Enter coupon code"/><button disabled={busyKey === "cart" || !coupon.trim()}>Apply</button></div>
        </form>
        {cart?.coupons?.length ? <div className="applied-coupons">{cart.coupons.map((c) => <span key={c.code}>Applied: {c.code} <button onClick={() => void mutate("cart/remove-coupon", { code: c.code })}>Remove</button></span>)}</div> : null}
        {notice && <p className="cart-feedback success">{notice}</p>}
      </section>
      <aside className="cart-summary"><h2>Order summary</h2><div><span>Subtotal</span><strong>{money(totals.total_items, currency, minor)}</strong></div><div><span>Discount</span><strong>{money(totals.total_discount, currency, minor)}</strong></div><div><span>Shipping</span><strong>{totals.total_shipping ? money(totals.total_shipping, currency, minor) : "Calculated at checkout"}</strong></div><div><span>Tax</span><strong>{money(totals.total_tax, currency, minor)}</strong></div><div className="cart-grand-total"><span>Total</span><strong>{money(totals.total_price, currency, minor)}</strong></div><p>Final shipping and taxes are calculated by WooCommerce using your delivery address.</p><Link className="button button-primary checkout-button" href="/checkout">Proceed to checkout <ArrowRight size={16}/></Link><div className="cart-secure-note">Secure checkout handled by the configured payment provider.</div></aside>
    </div>}
  </div>;
}
