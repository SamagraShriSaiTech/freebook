"use client";

import { FormEvent, useState } from "react";
import { CheckCircle2, LoaderCircle } from "lucide-react";

type FormState = "idle" | "sending" | "sent" | "error";

export function VolunteerApplicationForm() {
  const [state, setState] = useState<FormState>("idle");
  const [message, setMessage] = useState("");
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setState("sending"); setMessage("");
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    const payload = {
      full_name: String(form.get("full_name") || ""),
      email: String(form.get("email") || ""),
      phone: String(form.get("phone") || ""),
      location: String(form.get("location") || ""),
      interest: String(form.get("interest") || ""),
      availability: String(form.get("availability") || ""),
      message: String(form.get("message") || ""),
      consent: form.get("consent") === "on",
      website_url: String(form.get("website_url") || "")
    };
    try {
      const response = await fetch("/api/volunteer/apply", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(typeof result.message === "string" ? result.message : "We could not submit your application. Please try again.");
      setState("sent"); setMessage(result.message || "Thank you. Your application has been received.");
      formElement.reset();
    } catch (error) {
      setState("error"); setMessage(error instanceof Error ? error.message : "The application service is unavailable. Please try again later.");
    }
  }
  if (state === "sent") return <div className="volunteer-success"><CheckCircle2 size={30} /><h3>Thank you for offering your time.</h3><p>{message}</p><button className="button button-outline" onClick={() => { setState("idle"); setMessage(""); }}>Send another application</button></div>;
  return (
    <form className="public-form" onSubmit={submit}>
      <div className="form-grid">
        <label>Full name <span>*</span><input name="full_name" required maxLength={120} autoComplete="name" /></label>
        <label>Email address <span>*</span><input name="email" type="email" required maxLength={190} autoComplete="email" /></label>
        <label>Phone number <small>Optional</small><input name="phone" type="tel" maxLength={30} autoComplete="tel" /></label>
        <label>City / district <small>Optional</small><input name="location" maxLength={120} /></label>
        <label className="form-wide">How would you like to help? <span>*</span><select name="interest" required defaultValue=""><option value="" disabled>Select an area</option><option>Book sorting and cataloguing</option><option>Packing and dispatch</option><option>Collection and logistics</option><option>Digital and website support</option><option>Community outreach</option><option>Other</option></select></label>
        <label className="form-wide">Availability <small>Optional</small><input name="availability" maxLength={120} placeholder="For example: weekends or 3 hours per week" /></label>
        <label className="form-wide">Anything else you would like us to know? <small>Optional</small><textarea name="message" rows={4} maxLength={1200} /></label>
      </div>
      <label className="honeypot-field" aria-hidden="true">Website<input name="website_url" tabIndex={-1} autoComplete="off" /></label>
      <label className="consent-row"><input type="checkbox" name="consent" required /><span>I agree that FreeBooks / SamagraShriSai Foundation may use these details to contact me about volunteering. I have read the <a href="/privacy">Privacy Policy</a>.</span></label>
      {state === "error" ? <p className="form-error" role="alert">{message}</p> : null}
      <button className="button button-primary button-large" type="submit" disabled={state === "sending"}>{state === "sending" ? <><LoaderCircle className="spin" size={17} /> Sending…</> : "Submit volunteer application"}</button>
      <p className="form-small-note">Submitting an application does not guarantee placement. The team will review it and contact you if there is a suitable opportunity.</p>
    </form>
  );
}
