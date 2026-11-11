import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

export async function POST(request: NextRequest) {
  const cms = (process.env.WORDPRESS_BASE_URL || "").replace(/\/$/, "");
  if (!cms) return NextResponse.json({ message: "The volunteer application service is not configured yet." }, { status: 503 });
  let payload: Record<string, unknown>;
  try { payload = await request.json() as Record<string, unknown>; }
  catch { return NextResponse.json({ message: "Please submit a valid application." }, { status: 400 }); }
  const name = typeof payload.full_name === "string" ? payload.full_name.trim() : "";
  const email = typeof payload.email === "string" ? payload.email.trim() : "";
  const interest = typeof payload.interest === "string" ? payload.interest.trim() : "";
  if (!name || name.length > 120 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 190 || !interest || payload.consent !== true) {
    return NextResponse.json({ message: "Please complete your name, valid email, area of interest and consent." }, { status: 400 });
  }
  try {
    const upstream = await fetch(`${cms}/wp-json/freebooks/v1/volunteer/apply`, {
      method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify(payload), cache: "no-store", signal: AbortSignal.timeout(15000)
    });
    const result = await upstream.json().catch(() => ({}));
    if (!upstream.ok) return NextResponse.json({ message: typeof result.message === "string" ? result.message : "We could not submit your application." }, { status: upstream.status });
    return NextResponse.json({ success: true, message: result.message || "Thank you. Your application has been received." }, { status: 201 });
  } catch {
    return NextResponse.json({ message: "The volunteer service could not be reached. Your application has not been submitted." }, { status: 502 });
  }
}
