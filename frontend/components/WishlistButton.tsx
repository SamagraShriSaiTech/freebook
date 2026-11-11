"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { Heart } from "lucide-react";
export function WishlistButton({ productId }: { productId: number }) {
  const [saved,setSaved]=useState(false); const [signedIn,setSignedIn]=useState<boolean|null>(null); const [busy,setBusy]=useState(false); const [message,setMessage]=useState("");
  useEffect(()=>{let active=true;fetch("/api/account/wishlist",{cache:"no-store"}).then(async r=>{if(!r.ok)throw new Error("signed-out");return r.json();}).then(d=>{if(active){setSignedIn(true);setSaved((d.product_ids||[]).includes(productId));}}).catch(()=>{if(active)setSignedIn(false);});return()=>{active=false;};},[productId]);
  async function toggle(){if(busy)return;if(signedIn===false){setMessage("Sign in to keep this book in your saved list.");return;}setBusy(true);setMessage("");try{const response=await fetch("/api/account/wishlist",{method:saved?"DELETE":"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({product_id:productId})});const data=await response.json().catch(()=>({}));if(response.status===401){setSignedIn(false);setMessage("Sign in to keep this book in your saved list.");return;}if(!response.ok)throw new Error(data.message||"Your saved list could not be updated.");setSignedIn(true);setSaved((data.product_ids||[]).includes(productId));setMessage(saved?"Removed from saved books.":"Saved to your books.");}catch(e){setMessage(e instanceof Error?e.message:"Please try again.");}finally{setBusy(false);}}
  return <div className="wishlist-control"><button type="button" className={`wishlist-button${saved?" saved":""}`} onClick={toggle} disabled={busy} aria-pressed={saved}><Heart size={17} fill={saved?"currentColor":"none"}/>{busy?"Saving…":saved?"Saved book":"Save book"}</button>{message&&<p className="wishlist-feedback" role="status">{message}{signedIn===false&&<> <Link href="/account">Sign in</Link></>}</p>}</div>;
}
