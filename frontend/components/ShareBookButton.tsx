"use client";
import { useState } from "react";
import { Share2, Check } from "lucide-react";
export function ShareBookButton({ title }: { title: string }) {
  const [message,setMessage]=useState(""); const [copied,setCopied]=useState(false);
  async function share(){setMessage("");setCopied(false);const url=window.location.href;
    try{if(navigator.share){await navigator.share({title, text:`Explore ${title} on FreeBooks.`, url});setMessage("Share sheet opened.");return;}await navigator.clipboard.writeText(url);setCopied(true);setMessage("Book link copied.");}
    catch(e){if(e instanceof Error&&e.name==="AbortError")return;setMessage("Sharing is not available in this browser.");}
  }
  return <div className="share-book-control"><button type="button" className="button button-outline share-book-button" onClick={share}>{copied?<Check size={16}/>:<Share2 size={16}/>} Share book</button>{message&&<span className="share-feedback" role="status">{message}</span>}</div>;
}
