import { NextRequest, NextResponse } from "next/server";
export const runtime = "nodejs";
export async function POST(request: NextRequest) {
  const cms = (process.env.WORDPRESS_BASE_URL || "").replace(/\/$/, "");
  if (!cms) return NextResponse.json({ message: "Newsletter signup is not configured." }, { status: 503 });
  let body: Record<string, unknown>;
  try { body = await request.json() as Record<string, unknown>; }
  catch { return NextResponse.json({ message: "Please enter a valid email address." }, { status: 400 }); }
  const email = typeof body.email === "string" ? body.email.trim() : "";
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || body.consent !== true) {
    return NextResponse.json({ message: "Enter a valid email address and agree to receive updates." }, { status: 400 });
  }
  try {
    const response = await fetch(`${cms}/wp-json/freebooks/v1/newsletter/subscribe`, {
      method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify({ email, consent: true, website: typeof body.website === "string" ? body.website : "" }),
      cache: "no-store", signal: AbortSignal.timeout(15000)
    });
    const data = await response.json().catch(() => ({}));
    return NextResponse.json(data, { status: response.status, headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ message: "Newsletter signup could not be reached. Please try again later." }, { status: 502 });
  }
}
