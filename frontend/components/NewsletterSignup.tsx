"use client";
import { useState } from "react";
import type { FormEvent } from "react";
import { ArrowRight, CheckCircle2, Mail } from "lucide-react";
import Link from "next/link";
import { useLanguage } from "@/components/LanguageProvider";
export function NewsletterSignup() {
  const { tx } = useLanguage();
  const [email,setEmail]=useState(""); const [consent,setConsent]=useState(false); const [website,setWebsite]=useState("");
  const [busy,setBusy]=useState(false); const [message,setMessage]=useState(""); const [success,setSuccess]=useState(false);
  async function submit(e:FormEvent<HTMLFormElement>){e.preventDefault();setBusy(true);setMessage("");setSuccess(false);
    try{const r=await fetch("/api/newsletter/subscribe",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({email,consent,website})});const d=await r.json();if(!r.ok)throw new Error(d.message||"We could not process your subscription.");setSuccess(true);setMessage(d.message||"Thank you for subscribing.");setEmail("");setConsent(false);}
    catch(e){setMessage(e instanceof Error?e.message:"Please try again later.");}finally{setBusy(false);}
  }
  return <section className="newsletter-signup" aria-labelledby="newsletter-title"><div className="newsletter-copy"><span className="newsletter-icon"><Mail size={19}/></span><div><span className="eyebrow">{tx("The occasional good-news email", "कभी-कभार अच्छी खबरों वाला ईमेल")}</span><h2 id="newsletter-title">{tx("A little more reading in your inbox.", "आपके इनबॉक्स में पढ़ने के लिए कुछ और।")}</h2><p>{tx("Hear about new books, community activity and ways to help learning resources travel further.", "नई किताबों, समुदाय की गतिविधियों और अध्ययन सामग्री को आगे पहुँचाने के तरीकों की जानकारी पाएँ।")}</p></div></div>
    <form onSubmit={submit} className="newsletter-form"><label className="newsletter-email-label" htmlFor="newsletter-email">{tx("Email address", "ईमेल पता")}</label><div className="newsletter-input-row"><input id="newsletter-email" type="email" autoComplete="email" required maxLength={190} placeholder="you@example.com" value={email} onChange={e=>setEmail(e.target.value)}/><button type="submit" disabled={busy||!consent}>{busy?"Submitting…":"Subscribe"}<ArrowRight size={15}/></button></div>
      <div className="newsletter-consent"><input id="newsletter-consent" type="checkbox" checked={consent} onChange={e=>setConsent(e.target.checked)}/><label htmlFor="newsletter-consent">I agree to receive FreeBooks email updates. I can unsubscribe at any time. Read our <Link href="/privacy">{tx("Privacy Policy", "गोपनीयता नीति")}</Link>.</label></div>
      <div className="newsletter-honeypot" aria-hidden="true"><label>Leave this field empty<input tabIndex={-1} autoComplete="off" value={website} onChange={e=>setWebsite(e.target.value)}/></label></div>
      {message&&<p className={`newsletter-message${success?" success":" error"}`} role="status">{success&&<CheckCircle2 size={15}/>} {message}</p>}
    </form>
  </section>;
}
