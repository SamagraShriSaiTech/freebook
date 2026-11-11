import { NextRequest, NextResponse } from "next/server";
export const runtime = "nodejs";
const allowed = ["me", "profile", "book-requests"];
export async function GET(request: NextRequest, context: { params: Promise<{ action: string }> }) { return proxy(request, context, "GET"); }
export async function POST(request: NextRequest, context: { params: Promise<{ action: string }> }) { return proxy(request, context, "POST"); }
async function proxy(request: NextRequest, context: { params: Promise<{ action: string }> }, method: string) {
  const { action } = await context.params;
  if (!allowed.includes(action)) return NextResponse.json({ message: "Not found." }, { status: 404 });
  const cms = (process.env.WORDPRESS_BASE_URL || "").replace(/\/$/, "");
  if (!cms) return NextResponse.json({ message: "Membership service is not configured." }, { status: 503 });
  const token = request.cookies.get("fb_account")?.value;
  let body: unknown;
  if (method !== "GET") { try { body = await request.json(); } catch { body = {}; } }
  try {
    const upstream = await fetch(`${cms}/wp-json/freebooks/v1/membership/${action}`, {
      method,
      headers: { Accept: "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(method !== "GET" ? { "Content-Type": "application/json" } : {}) },
      ...(method !== "GET" ? { body: JSON.stringify(body) } : {}), cache: "no-store", signal: AbortSignal.timeout(15000)
    });
    const data = await upstream.json().catch(() => ({}));
    return NextResponse.json(data, { status: upstream.status, headers: { "Cache-Control": "no-store" } });
  } catch { return NextResponse.json({ message: "Membership service could not be reached." }, { status: 502 }); }
}
