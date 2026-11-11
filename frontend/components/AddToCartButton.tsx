"use client";
import { useState } from "react";
import { ShoppingBag, Check, LoaderCircle } from "lucide-react";
import Link from "next/link";

export function AddToCartButton({ productId, available = true, quantity = 1, compact = false }: { productId: number; available?: boolean; quantity?: number; compact?: boolean }) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [added, setAdded] = useState(false);

  async function add() {
    if (!available || busy) return;
    setBusy(true); setMessage(""); setAdded(false);
    try {
      const response = await fetch("/api/store/cart/add-item", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: productId, quantity: Math.max(1, quantity) })
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || data.code || "This item could not be added. Please refresh and try again.");
      setAdded(true); setMessage("Added to your cart.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Could not add this item.");
    } finally { setBusy(false); }
  }

  return <div className={`add-cart-wrap${compact ? " compact" : ""}`}>
    <button type="button" className="button button-primary add-cart-button" onClick={add} disabled={!available || busy}>
      {busy ? <LoaderCircle size={16} className="spin" /> : added ? <Check size={16} /> : <ShoppingBag size={16} />}
      {busy ? "Adding…" : added ? "Added to cart" : available ? "Add to cart" : "Out of stock"}
    </button>
    {message && <p className={added ? "cart-feedback success" : "cart-feedback error"} role="status">{message} {added && <Link href="/cart">View cart</Link>}</p>}
  </div>;
}
