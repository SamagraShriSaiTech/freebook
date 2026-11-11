"use client";
import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Download, ShieldCheck, Trash2 } from "lucide-react";
import { useLanguage } from "@/components/LanguageProvider";

type Account = { name: string; email: string };
export function PrivacyCentre() {
  const { tx } = useLanguage();
  const router = useRouter();
  const [account, setAccount] = useState<Account | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [kind, setKind] = useState<"access" | "deletion">("access");
  const [details, setDetails] = useState("");
  useEffect(() => {
    let active = true;
    fetch("/api/account/me", { cache: "no-store" }).then(async r => {
      const d = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(d.message || "Please sign in to use the privacy centre.");
      if (active) setAccount(d.account || null);
    }).catch(e => { if (active) setMessage(e.message || "Please sign in to continue."); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault(); setBusy(true); setMessage("");
    try {
      const r = await fetch("/api/account/privacy-request", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ request_type: kind, details }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(d.message || "Your request could not be submitted.");
      setMessage(d.message || "Your privacy request has been submitted for review."); setDetails("");
    } catch (e) { setMessage(e instanceof Error ? e.message : "Something went wrong. Please try again."); }
    finally { setBusy(false); }
  }
  if (loading) return <section className="section"><div className="container"><div className="content-card">{tx("Loading your privacy centre…", "आपका गोपनीयता केंद्र लोड हो रहा है…")}</div></div></section>;
  if (!account) return <section className="section"><div className="container privacy-centre-wrap"><div className="content-card"><h2>{tx("Sign in required", "साइन इन आवश्यक है")}</h2><p>{message || "Sign in to your FreeBooks account before submitting a personal data request."}</p><button className="button button-primary" onClick={() => router.push("/account")}>{tx("Go to account sign in", "खाते में साइन इन करें")}</button></div></div></section>;
  return <section className="section"><div className="container privacy-centre-wrap">
    <div className="privacy-centre-intro"><span className="eyebrow">{tx("YOUR DATA, YOUR CHOICES", "आपका डेटा, आपके विकल्प")}</span><h2>{tx("Manage your privacy requests", "अपने गोपनीयता अनुरोध प्रबंधित करें")}</h2><p>Signed in as <strong>{account.email}</strong>. You can ask the FreeBooks team for a copy of your personal information or request account deletion.</p></div>
    <div className="privacy-choice-grid"><article className="content-card"><Download/><h3>{tx("Access or export my data", "मेरे डेटा तक पहुँचें या निर्यात करें")}</h3><p>{tx("Request a review and export of personal information associated with your FreeBooks account. Some records may be managed by WordPress or WooCommerce privacy tools.", "अपने FreeBooks खाते से जुड़ी व्यक्तिगत जानकारी की समीक्षा और निर्यात का अनुरोध करें। कुछ रिकॉर्ड WordPress या WooCommerce गोपनीयता टूल द्वारा प्रबंधित हो सकते हैं।")}</p></article><article className="content-card"><Trash2/><h3>{tx("Request account deletion", "खाता हटाने का अनुरोध करें")}</h3><p>{tx("This starts a review; it does not immediately delete your account, orders or donation records. We may need to retain limited records for legitimate operational or legal reasons.", "इससे समीक्षा शुरू होती है; आपका खाता, ऑर्डर या दान रिकॉर्ड तुरंत नहीं हटाए जाते। वैध संचालन या कानूनी कारणों से कुछ सीमित रिकॉर्ड सुरक्षित रखने पड़ सकते हैं।")}</p></article></div>
    <form className="content-card privacy-request-form" onSubmit={submit}><label htmlFor="privacy-request-type">{tx("What would you like to request?", "आप कौन-सा अनुरोध करना चाहते हैं?")}</label><select id="privacy-request-type" value={kind} onChange={e => setKind(e.target.value as "access" | "deletion")}><option value="access">{tx("Access / export my personal data", "मेरे व्यक्तिगत डेटा तक पहुँच / निर्यात")}</option><option value="deletion">{tx("Request deletion of my account", "मेरा खाता हटाने का अनुरोध")}</option></select><label htmlFor="privacy-request-details">{tx("Additional details (optional)", "अतिरिक्त विवरण (वैकल्पिक)")}</label><textarea id="privacy-request-details" value={details} onChange={e => setDetails(e.target.value)} maxLength={2000} rows={4} placeholder="Tell us if your request relates to a particular record or email address…"/><p className="privacy-request-note"><ShieldCheck size={16}/> Your request is linked to your signed-in account and sent securely for administrator review.</p><button className="button button-primary" disabled={busy}>{busy ? tx("Submitting…", "जमा किया जा रहा है…") : tx("Submit privacy request", "गोपनीयता अनुरोध जमा करें")}</button>{message && <p role="status" className="privacy-request-message">{message}</p>}</form>
    <div className="privacy-centre-footnote"><strong>{tx("Need a formal data export?", "क्या आपको औपचारिक डेटा निर्यात चाहिए?")}</strong><p>{tx("The site administrator can process verified requests using WordPress Tools → Export Personal Data. Deletion is reviewed separately and may involve anonymising donation records rather than removing operational history.", "साइट व्यवस्थापक WordPress Tools → Export Personal Data का उपयोग करके सत्यापित अनुरोध संसाधित कर सकता है। हटाने के अनुरोध की अलग समीक्षा होती है और संचालन इतिहास मिटाने के बजाय दान रिकॉर्ड को अनाम किया जा सकता है।")}</p><a href="/privacy">{tx("Read the Privacy Policy", "गोपनीयता नीति पढ़ें")}</a></div>
  </div></section>;
}
