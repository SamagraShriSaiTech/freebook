 "use client";

import { useEffect, useId, useRef, useState } from "react";
import Script from "next/script";
import { Plus, Trash2, UploadCloud, CheckCircle2, AlertCircle, HeartHandshake, ShieldCheck, ArrowLeft, ArrowRight, Copy, ClipboardCheck } from "lucide-react";
import { useLanguage } from "@/components/LanguageProvider";

type Props = { turnstileSiteKey?: string };
type Item = { category: string; title: string; quantity: string; condition: string; author: string; isbn: string; details: string };

declare global {
  interface Window {
    turnstile?: {
      render: (container: string | HTMLElement, options: { sitekey: string; callback: (token: string) => void; "expired-callback"?: () => void; "error-callback"?: () => void }) => string;
      remove: (widgetId: string) => void;
    };
  }
}

const blankItem = (): Item => ({ category: "Books", title: "", quantity: "1", condition: "Good", author: "", isbn: "", details: "" });

export function DonationForm({ turnstileSiteKey }: Props) {
  const { tx } = useLanguage();
  const [items, setItems] = useState<Item[]>([blankItem()]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [reference, setReference] = useState("");
  const [turnstileToken, setTurnstileToken] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [widgetReady, setWidgetReady] = useState(false);
  const [step, setStep] = useState(0);
  const [copied, setCopied] = useState(false);
  const stepRefs = useRef<Array<HTMLDivElement | null>>([]);
  const widgetId = `freebooks-turnstile-${useId().replace(/:/g, "")}`;

  useEffect(() => {
    if (!turnstileSiteKey || !widgetReady || !window.turnstile || step !== 2) return;
    const node = document.getElementById(widgetId);
    if (!node) return;
    const id = window.turnstile.render(node, {
      sitekey: turnstileSiteKey,
      callback: setTurnstileToken,
      "expired-callback": () => setTurnstileToken(""),
      "error-callback": () => setTurnstileToken("")
    });
    return () => { try { window.turnstile?.remove(id); } catch { /* widget may already be removed */ } };
  }, [turnstileSiteKey, widgetReady, widgetId, step]);

  function updateItem(index: number, key: keyof Item, value: string) {
    setItems((current) => current.map((item, i) => i === index ? { ...item, [key]: value } : item));
  }

  function handleFiles(selected: FileList | null) {
    const chosen = Array.from(selected || []);
    if (chosen.length > 3) { setError("You can attach up to 3 photos."); return; }
    const invalid = chosen.find((file) => !["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > 5 * 1024 * 1024);
    if (invalid) { setError(`${invalid.name}: choose a JPG, PNG or WebP image up to 5 MB.`); return; }
    setError("");
    setFiles(chosen);
  }

  function goToStep(nextStep: number) {
    if (nextStep > step) {
      const current = stepRefs.current[step];
      const invalid = current?.querySelector<HTMLElement>("input:invalid, select:invalid, textarea:invalid");
      if (invalid && "reportValidity" in invalid) {
        (invalid as HTMLInputElement).reportValidity();
        invalid.focus();
        return;
      }
      if (step === 1 && items.some((item) => !item.title.trim())) {
        setError("Please add a title or short description for every item.");
        return;
      }
    }
    if (step === 2 && nextStep < 2) setTurnstileToken("");
    setError("");
    setStep(Math.max(0, Math.min(2, nextStep)));
  }

  async function copyReference() {
    try { await navigator.clipboard.writeText(reference); setCopied(true); } catch { setCopied(false); }
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    const form = event.currentTarget;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const data = new FormData(form);
    const validItems = items.map((item) => ({
      category: item.category.trim(),
      title: item.title.trim(),
      quantity: Math.max(1, Math.min(500, Number(item.quantity) || 1)),
      condition: item.condition,
      author: item.author.trim(),
      isbn: item.isbn.trim(),
      details: item.details.trim()
    }));
    if (validItems.some((item) => !item.category || !item.title)) {
      setError("Please add a category and title for every item.");
      return;
    }
    if (turnstileSiteKey && !turnstileToken) {
      setError("Please complete the security verification.");
      return;
    }
    const payload = {
      donor: {
        name: String(data.get("name") || "").trim(),
        email: String(data.get("email") || "").trim(),
        phone: String(data.get("phone") || "").trim(),
        city: String(data.get("city") || "").trim(),
        preferred_contact: String(data.get("preferred_contact") || "none"),
        marketing_opt_in: data.get("marketing_opt_in") === "on"
      },
      items: validItems,
      handover: String(data.get("handover") || ""),
      message: String(data.get("message") || "").trim(),
      consent: data.get("consent") === "on"
    };
    const outgoing = new FormData();
    outgoing.set("payload", JSON.stringify(payload));
    outgoing.set("fb_website", String(data.get("fb_website") || ""));
    outgoing.set("cf-turnstile-response", turnstileToken);
    files.forEach((file) => outgoing.append("fb_photos[]", file, file.name));

    setBusy(true);
    try {
      const response = await fetch("/api/donations", { method: "POST", body: outgoing });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Your donation could not be submitted.");
      setReference(String(result.donation_id || ""));
      form.reset();
      setItems([blankItem()]);
      setFiles([]);
      setTurnstileToken("");
      setStep(0);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong. Please try again.");
    } finally {
      setBusy(false);
    }
  }

  if (reference) {
    return <div className="donation-success">
      <span className="success-icon"><CheckCircle2 size={34} /></span>
      <span className="eyebrow">{tx("Submission received", "अनुरोध प्राप्त हुआ")}</span>
      <h2>{tx("Thank you for giving books another chapter.", "किताबों को नया अध्याय देने के लिए धन्यवाद।")}</h2>
      <p>{tx("Your donation request has been submitted for review. This is not an acceptance confirmation; our team will review the details and contact you.", "आपका पुस्तक दान अनुरोध समीक्षा के लिए भेज दिया गया है। यह स्वीकृति की पुष्टि नहीं है; हमारी टीम विवरण जाँचकर आपसे संपर्क करेगी।")}</p>
      <div className="reference-box"><small>{tx("Your donation reference", "आपका दान संदर्भ नंबर")}</small><strong>{reference}</strong><button type="button" className="reference-copy" onClick={copyReference}>{copied ? <ClipboardCheck size={15} /> : <Copy size={15} />}{copied ? "Copied" : "Copy reference"}</button></div>
      <p className="success-note">{tx("Please keep this reference safe. You will need it when checking your donation status.", "कृपया इस संदर्भ नंबर को सुरक्षित रखें। दान की स्थिति देखने के लिए इसकी आवश्यकता होगी।")}</p>
      <div className="donation-success-actions"><a href="/donation-portal" className="button button-primary">{tx("Track my donation", "मेरा दान ट्रैक करें")}</a><a href="/donate" className="button button-secondary">{tx("Submit another donation", "एक और दान अनुरोध भेजें")}</a></div>
    </div>;
  }

  return <>
    {turnstileSiteKey && <Script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" strategy="afterInteractive" onLoad={() => setWidgetReady(true)} />}
    <form className="donation-form" onSubmit={submit}>
      <div className="donation-stepper" aria-label="Donation form progress">
        {[{ label: tx("Your details", "आपका विवरण"), short: "01" }, { label: tx("Items", "वस्तुएँ"), short: "02" }, { label: tx("Handover", "सामग्री सौंपना"), short: "03" }].map((entry, index) => <button type="button" key={entry.short} className={`donation-step ${step === index ? "is-current" : ""} ${step > index ? "is-complete" : ""}`} aria-current={step === index ? "step" : undefined} onClick={() => index <= step ? setStep(index) : goToStep(index)}><span>{step > index ? <CheckCircle2 size={16} /> : entry.short}</span><strong>{entry.label}</strong></button>)}
      </div>
      <div className="donation-form-section" ref={(node) => { stepRefs.current[0] = node; }} hidden={step !== 0}>
        <div className="form-section-heading"><span>01</span><div><h2>{tx("Your details", "आपका विवरण")}</h2><p>{tx("How can our team reach you about this donation?", "इस दान के बारे में हमारी टीम आपसे कैसे संपर्क करे?")}</p></div></div>
        <div className="donation-fields two-col">
          <label>{tx("Full name", "पूरा नाम")} <em>*</em><input name="name" required maxLength={120} autoComplete="name" /></label>
          <label>{tx("Email address", "ईमेल पता")} <em>*</em><input name="email" type="email" required maxLength={190} autoComplete="email" /></label>
          <label>{tx("Phone number", "फ़ोन नंबर")} <em>*</em><input name="phone" type="tel" required maxLength={30} autoComplete="tel" /></label>
          <label>City <input name="city" maxLength={100} autoComplete="address-level2" /></label>
          <label>{tx("Preferred contact method", "संपर्क का पसंदीदा तरीका")} <select name="preferred_contact" defaultValue="whatsapp"><option value="whatsapp">WhatsApp</option><option value="phone">{tx("Phone call", "फ़ोन कॉल")}</option><option value="email">{tx("Email", "ईमेल")}</option><option value="none">{tx("No preference", "कोई प्राथमिकता नहीं")}</option></select></label>
        </div>
      </div>

      <div className="donation-form-section" ref={(node) => { stepRefs.current[1] = node; }} hidden={step !== 1}>
        <div className="form-section-heading"><span>02</span><div><h2>{tx("What would you like to donate?", "आप क्या दान करना चाहते हैं?")}</h2><p>{tx("Add each type of book or learning material separately. Up to 20 item groups.", "हर प्रकार की पुस्तक या अध्ययन सामग्री अलग जोड़ें। अधिकतम 20 समूह।")}</p></div></div>
        <div className="donation-items">
          {items.map((item, index) => <fieldset className="donation-item" key={index}>
            <div className="item-card-heading"><strong>{tx("Item", "वस्तु")} {index + 1}</strong>{items.length > 1 && <button type="button" className="remove-item" onClick={() => setItems((current) => current.filter((_, i) => i !== index))}><Trash2 size={15} /> {tx("Remove", "हटाएँ")}</button>}</div>
            <div className="donation-fields two-col">
              <label>{tx("Category", "श्रेणी")} <em>*</em><select value={item.category} onChange={(e) => updateItem(index, "category", e.target.value)}><option value="Books">{tx("Books", "किताबें")}</option><option value="Notes">{tx("Notes", "नोट्स")}</option><option value="Magazines">{tx("Magazines", "पत्रिकाएँ")}</option><option value="Journals">{tx("Journals", "जर्नल")}</option><option value="Stationery">{tx("Stationery", "स्टेशनरी")}</option></select></label>
              <label>{tx("Title / short description", "शीर्षक / संक्षिप्त विवरण")} <em>*</em><input required maxLength={180} value={item.title} onChange={(e) => updateItem(index, "title", e.target.value)} placeholder="e.g. Class 10 Science textbook" /></label>
              <label>Quantity <input type="number" min="1" max="500" value={item.quantity} onChange={(e) => updateItem(index, "quantity", e.target.value)} /></label>
              <label>{tx("Condition", "स्थिति")} <select value={item.condition} onChange={(e) => updateItem(index, "condition", e.target.value)}><option value="New">{tx("New", "नई")}</option><option value="Like new">{tx("Like new", "लगभग नई")}</option><option value="Good">{tx("Good", "अच्छी")}</option><option value="Fair">{tx("Fair", "सामान्य")}</option><option value="Used">{tx("Used", "इस्तेमाल की हुई")}</option></select></label>
              {item.category === "Books" && <>
                <label>Author (if known) <input maxLength={120} value={item.author} onChange={(e) => updateItem(index, "author", e.target.value)} /></label>
                <label>ISBN (if available) <input maxLength={30} value={item.isbn} onChange={(e) => updateItem(index, "isbn", e.target.value)} /></label>
              </>}
              <label className="field-full">Additional details <textarea rows={2} value={item.details} onChange={(e) => updateItem(index, "details", e.target.value)} placeholder="Edition, subject, class, publisher or other useful details" /></label>
            </div>
          </fieldset>)}
        </div>
        <button type="button" className="add-item-button" disabled={items.length >= 20} onClick={() => setItems((current) => [...current, blankItem()])}><Plus size={17} /> Add another item</button>
      </div>

      <div className="donation-form-section" ref={(node) => { stepRefs.current[2] = node; }} hidden={step !== 2}>
        <div className="form-section-heading"><span>03</span><div><h2>{tx("Photos & handover", "फ़ोटो और सामग्री सौंपना")}</h2><p>{tx("Photos help our team review the condition. You can add up to 3.", "फ़ोटो से हमारी टीम सामग्री की स्थिति समझ सकती है। आप अधिकतम 3 फ़ोटो जोड़ सकते हैं।")}</p></div></div>
        <label className="upload-zone"><UploadCloud size={25} /><strong>{tx("Add item photos", "वस्तुओं की फ़ोटो जोड़ें")}</strong><span>{tx("JPG, PNG or WebP · Up to 3 images · 5 MB each", "JPG, PNG या WebP · अधिकतम 3 फ़ोटो · प्रत्येक 5 MB तक")}</span><input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(e) => handleFiles(e.target.files)} /></label>
        {files.length > 0 && <div className="selected-files">{files.map((file) => <span key={`${file.name}-${file.size}`}>{file.name}</span>)}</div>}
        <p className="donation-photo-note">{tx("Please avoid including people, identity documents or personal information in photos. Clear photos of the items are most helpful.", "फ़ोटो में लोगों, पहचान-पत्रों या निजी जानकारी को शामिल न करें। वस्तुओं की साफ़ फ़ोटो सबसे उपयोगी होती हैं।")}</p>
        <div className="donation-fields two-col handover-fields">
          <label>{tx("How would you prefer to hand over the items?", "आप सामग्री कैसे सौंपना चाहेंगे?")} <em>*</em><select name="handover" required defaultValue=""><option value="" disabled>{tx("Select an option", "एक विकल्प चुनें")}</option><option value="drop_off">{tx("I can drop off the items", "मैं सामग्री स्वयं पहुँचा सकता/सकती हूँ")}</option><option value="pickup">{tx("Please contact me about pickup", "पिकअप के लिए मुझसे संपर्क करें")}</option><option value="discuss">{tx("Please discuss the options with me", "कृपया विकल्पों पर मुझसे चर्चा करें")}</option></select></label>
          <label>Message (optional) <textarea name="message" rows={3} maxLength={1500} placeholder="Anything else our team should know?" /></label>
        </div>
      </div>

      <div className="donation-consent" hidden={step !== 2}>
        <label className="check-row"><input type="checkbox" name="consent" required /><span>{tx("I agree that FreeBooks may contact me about this donation and use the submitted details to review it.", "मैं सहमत हूँ कि FreeBooks इस दान के बारे में मुझसे संपर्क कर सकता है और समीक्षा के लिए दी गई जानकारी का उपयोग कर सकता है।")} <em>*</em></span></label>
        <label className="check-row"><input type="checkbox" name="marketing_opt_in" /><span>{tx("I would like to receive occasional FreeBooks news and community updates. (Optional)", "मैं समय-समय पर FreeBooks की खबरें और समुदाय अपडेट पाना चाहता/चाहती हूँ। (वैकल्पिक)")}</span></label>
      </div>

      {step === 2 && <div className="donation-review-note"><ShieldCheck size={17} /><span>{tx("Submitting this form sends a request for review. It does not mean the items have already been accepted or that pickup has been booked.", "यह फ़ॉर्म जमा करने से समीक्षा अनुरोध भेजा जाता है। इसका अर्थ यह नहीं है कि सामग्री स्वीकार हो गई है या पिकअप तय हो गया है।")}</span></div>}
      <div className="honeypot" aria-hidden="true"><label>{tx("Leave this field empty", "इस फ़ील्ड को खाली छोड़ें")}<input name="fb_website" tabIndex={-1} autoComplete="off" /></label></div>
      {step === 2 && turnstileSiteKey && <div className="turnstile-wrap"><div id={widgetId} /></div>}
      {error && <div className="form-error" role="alert"><AlertCircle size={17} />{error}</div>}
      <div className="donation-step-actions">
        {step > 0 && <button type="button" className="button button-secondary" onClick={() => goToStep(step - 1)} disabled={busy}><ArrowLeft size={16} /> {tx("Back", "वापस")}</button>}
        {step < 2 ? <button type="button" className="button button-primary button-large donation-submit" onClick={() => goToStep(step + 1)}>{tx("Continue", "आगे बढ़ें")} <ArrowRight size={17} /></button> : <button type="submit" className="button button-primary button-large donation-submit" disabled={busy}>{busy ? tx("Submitting donation…", "दान अनुरोध भेजा जा रहा है…") : tx("Submit donation request", "दान अनुरोध जमा करें")} <HeartHandshake size={18} /></button>}
      </div>
      <p className="form-privacy"><ShieldCheck size={14} /> {tx("Your details are used to review and coordinate this donation. Marketing updates are optional and separate.", "आपकी जानकारी का उपयोग इस दान की समीक्षा और समन्वय के लिए किया जाएगा। मार्केटिंग अपडेट वैकल्पिक हैं।")}</p>
    </form>
  </>;
}
