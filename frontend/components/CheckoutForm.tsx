"use client";
import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import Link from "next/link";
import { ArrowLeft, ArrowRight, ShieldCheck } from "lucide-react";

type PaymentMethod = { id: string; title: string; description?: string };
type ShippingRate = { rate_id: string; name: string; price?: string; currency_code?: string; currency_minor_unit?: number; selected?: boolean; delivery_time?: string };
type ShippingPackage = { package_id: number; name?: string; shipping_rates?: ShippingRate[] };
type Cart = { items?: { key: string; name: string; quantity: number }[]; totals?: Record<string, string | number>; shipping_rates?: ShippingPackage[]; errors?: { message?: string }[] };
type Address = { first_name: string; last_name: string; address_1: string; address_2: string; city: string; state: string; postcode: string; country: string; email: string; phone: string };
const emptyAddress: Address = { first_name: "", last_name: "", address_1: "", address_2: "", city: "", state: "", postcode: "", country: "IN", email: "", phone: "" };
function money(raw: string | number | undefined, currency = "INR", minor = 2) { const n = Number(raw || 0) / Math.pow(10, Number(minor)); return new Intl.NumberFormat("en-IN", { style: "currency", currency }).format(Number.isFinite(n) ? n : 0); }

export function CheckoutForm() {
  const [address, setAddress] = useState<Address>(emptyAddress);
  const [methods, setMethods] = useState<PaymentMethod[]>([]);
  const [method, setMethod] = useState("");
  const [cart, setCart] = useState<Cart | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [shippingPackages, setShippingPackages] = useState<ShippingPackage[]>([]);
  const [selectedRates, setSelectedRates] = useState<Record<string, string>>({});

  useEffect(() => {
    let live = true;
    Promise.all([
      fetch("/api/store/cart", { cache: "no-store" }).then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.message || "Cart could not be loaded."); return d as Cart; }),
      fetch("/api/checkout/payment-methods", { cache: "no-store" }).then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.message || "Payment methods could not be loaded."); return (d.methods || []) as PaymentMethod[]; })
    ]).then(([c, m]) => { if (!live) return; setCart(c); setMethods(m); setMethod(m[0]?.id || ""); })
      .catch(e => { if (live) setError(e instanceof Error ? e.message : "Checkout could not be loaded."); })
      .finally(() => { if (live) setLoading(false); });
    return () => { live = false; };
  }, []);

  function update(field: keyof Address, value: string) { setAddress(old => ({ ...old, [field]: value })); }
  const totals = cart?.totals || {};
  const currency = String(totals.currency_code || "INR");
  const minor = Number(totals.currency_minor_unit ?? 2);

  async function placeOrder(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError("");
    try {
      if (!cart?.items?.length) throw new Error("Your cart is empty.");
      if (!method) throw new Error("No online payment method is currently available. Please contact the FreeBooks team.");
      const required = [address.first_name,address.last_name,address.address_1,address.city,address.state,address.postcode,address.email,address.phone];
      if (required.some(v => !v.trim())) throw new Error("Please complete all required delivery and contact fields.");
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address.email)) throw new Error("Enter a valid email address.");
      // First persist the address so WooCommerce can calculate destination-specific shipping.
      const customerResponse = await fetch("/api/store/cart/update-customer", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ billing_address: address, shipping_address: address })
      });
      const updatedCart = await customerResponse.json();
      if (!customerResponse.ok) throw new Error(updatedCart.message || updatedCart.code || "Delivery details could not be saved.");
      const packages = Array.isArray(updatedCart.shipping_rates) ? updatedCart.shipping_rates as ShippingPackage[] : [];
      setCart(updatedCart as Cart);
      setShippingPackages(packages);
      for (const pack of packages) {
        const rates = pack.shipping_rates || [];
        if (!rates.length) continue;
        const chosen = selectedRates[String(pack.package_id)] || rates.find(rate => rate.selected)?.rate_id || "";
        if (!chosen) throw new Error("Please select a delivery option below, then place your order again.");
        const shippingResponse = await fetch("/api/store/cart/select-shipping-rate", {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ package_id: pack.package_id, rate_id: chosen })
        });
        const shippingData = await shippingResponse.json();
        if (!shippingResponse.ok) throw new Error(shippingData.message || shippingData.code || "The selected delivery option could not be saved.");
        setCart(shippingData as Cart);
      }
      const body = {
        billing_address: address,
        shipping_address: address,
        customer_note: note,
        payment_method: method,
        payment_data: []
      };
      const response = await fetch("/api/store/checkout", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || data.code || "Your order could not be placed. Please review your delivery details and try again.");
      const redirect = data.payment_result?.redirect_url;
      if (redirect) { window.location.assign(redirect); return; }
      if (data.order_id) { window.location.assign(`/order-confirmation?order=${encodeURIComponent(String(data.order_id))}&status=${encodeURIComponent(String(data.status || "received"))}`); return; }
      throw new Error("The payment provider did not return an order confirmation. Please contact support before trying again.");
    } catch (e) { setError(e instanceof Error ? e.message : "Checkout failed. Please try again."); setBusy(false); }
  }

  if (loading) return <div className="commerce-page container"><div className="commerce-loading">Preparing secure checkout…</div></div>;
  if (error && !cart) return <div className="commerce-page container"><div className="commerce-alert error">{error}<p><Link href="/cart">Return to cart</Link></p></div></div>;
  if (!cart?.items?.length) return <div className="commerce-page container"><div className="empty-cart"><h2>Your cart is empty.</h2><p>Add a book before continuing to checkout.</p><Link className="button button-primary" href="/shop">Explore books</Link></div></div>;

  return <div className="commerce-page container">
    <div className="commerce-heading"><span className="eyebrow">Almost there</span><h1>Secure <em>checkout</em></h1><p>Enter your delivery details. WooCommerce will calculate shipping, taxes and the final payable amount.</p></div>
    <div className="checkout-layout">
      <form className="checkout-form" onSubmit={placeOrder}>
        <section className="checkout-section"><div className="checkout-section-title"><span>01</span><div><h2>Contact details</h2><p>Order updates and delivery coordination.</p></div></div>
          <div className="checkout-fields two"><label>Email address *<input type="email" autoComplete="email" required value={address.email} onChange={e=>update("email",e.target.value)}/></label><label>Mobile number *<input type="tel" autoComplete="tel" required value={address.phone} onChange={e=>update("phone",e.target.value)}/></label></div>
        </section>
        <section className="checkout-section"><div className="checkout-section-title"><span>02</span><div><h2>Delivery address</h2><p>Shipping is calculated from this address.</p></div></div>
          <div className="checkout-fields two"><label>First name *<input autoComplete="given-name" required value={address.first_name} onChange={e=>update("first_name",e.target.value)}/></label><label>Last name *<input autoComplete="family-name" required value={address.last_name} onChange={e=>update("last_name",e.target.value)}/></label><label className="full">Address line 1 *<input autoComplete="address-line1" required value={address.address_1} onChange={e=>update("address_1",e.target.value)}/></label><label className="full">Address line 2<input autoComplete="address-line2" value={address.address_2} onChange={e=>update("address_2",e.target.value)}/></label><label>City *<input autoComplete="address-level2" required value={address.city} onChange={e=>update("city",e.target.value)}/></label><label>State *<input autoComplete="address-level1" required value={address.state} onChange={e=>update("state",e.target.value)}/></label><label>PIN code *<input autoComplete="postal-code" inputMode="numeric" required value={address.postcode} onChange={e=>update("postcode",e.target.value)}/></label><label>Country<input value="India" disabled/></label></div>
        </section>
        {shippingPackages.some(pack => (pack.shipping_rates || []).length > 0) && <section className="checkout-section"><div className="checkout-section-title"><span>03</span><div><h2>Delivery option</h2><p>Choose a rate calculated by WooCommerce for your PIN code.</p></div></div>
          <div className="payment-method-list">{shippingPackages.flatMap(pack => (pack.shipping_rates || []).map(rate => <label className={`payment-method${(selectedRates[String(pack.package_id)] || (rate.selected ? rate.rate_id : ""))===rate.rate_id?" selected":""}`} key={`${pack.package_id}-${rate.rate_id}`}><input type="radio" name={`shipping-${pack.package_id}`} checked={(selectedRates[String(pack.package_id)] || (rate.selected ? rate.rate_id : ""))===rate.rate_id} onChange={()=>setSelectedRates(old=>({...old,[String(pack.package_id)]:rate.rate_id}))}/><span><strong>{rate.name} {rate.price !== undefined && <em className="shipping-rate-price">{money(rate.price, rate.currency_code || currency, rate.currency_minor_unit ?? minor)}</em>}</strong>{rate.delivery_time && <small>{rate.delivery_time}</small>}</span></label>))}</div>
        </section>}
        <section className="checkout-section"><div className="checkout-section-title"><span>04</span><div><h2>Payment method</h2><p>Available options are loaded from your enabled WooCommerce gateways.</p></div></div>
          {methods.length ? <div className="payment-method-list">{methods.map(m=><label className={`payment-method${method===m.id?" selected":""}`} key={m.id}><input type="radio" name="payment_method" value={m.id} checked={method===m.id} onChange={()=>setMethod(m.id)}/><span><strong>{m.title}</strong>{m.description && <small>{m.description}</small>}</span></label>)}</div> : <div className="commerce-alert warning">No payment gateways are currently available. Enable a payment provider in WooCommerce before accepting orders.</div>}
        </section>
        <section className="checkout-section"><label className="note-label">Order note (optional)<textarea rows={3} value={note} onChange={e=>setNote(e.target.value)} placeholder="Delivery instructions or anything we should know…"/></label></section>
        {error && <div className="commerce-alert error" role="alert">{error}</div>}
        <button className="button button-primary place-order-button" type="submit" disabled={busy || !methods.length}>{busy ? "Submitting order…" : "Place order and continue to payment"}<ArrowRight size={17}/></button>
        <p className="checkout-legal">By placing your order, you agree to our <Link href="/terms">Terms</Link> and <Link href="/shipping-returns">Shipping & Returns</Link> policy.</p>
      </form>
      <aside className="checkout-summary"><h2>Your order</h2>{cart.items.map((item,i)=><div className="checkout-summary-item" key={`${item.key}-${i}`}><span>{item.name} <b>× {item.quantity}</b></span></div>)}<div className="summary-rule"/><div><span>Subtotal</span><strong>{money(totals.total_items,currency,minor)}</strong></div><div><span>Shipping</span><strong>{totals.total_shipping ? money(totals.total_shipping,currency,minor) : "Address-based"}</strong></div><div><span>Tax</span><strong>{money(totals.total_tax,currency,minor)}</strong></div><div className="checkout-total"><span>Current total</span><strong>{money(totals.total_price,currency,minor)}</strong></div><p>Final totals may update after WooCommerce calculates delivery rates and applicable taxes.</p><div className="secure-checkout"><ShieldCheck size={17}/> Payment is processed by your selected WooCommerce payment provider.</div><Link className="back-to-cart" href="/cart"><ArrowLeft size={14}/> Back to cart</Link></aside>
    </div>
  </div>;
}
