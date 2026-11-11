import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

const allowed = new Set([
  "cart",
  "checkout",
  "cart/add-item",
  "cart/update-item",
  "cart/remove-item",
  "cart/apply-coupon",
  "cart/remove-coupon",
  "cart/update-customer",
  "cart/select-shipping-rate"
]);

export async function GET(request: NextRequest, context: { params: Promise<{ path: string[] }> }) {
  return proxy(request, context, "GET");
}

export async function POST(request: NextRequest, context: { params: Promise<{ path: string[] }> }) {
  return proxy(request, context, "POST");
}

async function proxy(request: NextRequest, context: { params: Promise<{ path: string[] }> }, method: "GET" | "POST") {
  const { path } = await context.params;
  const endpoint = (path || []).join("/");
  if (!allowed.has(endpoint)) return NextResponse.json({ message: "Store endpoint not found." }, { status: 404 });

  const cms = (process.env.WORDPRESS_BASE_URL || "").replace(/\/$/, "");
  if (!cms) return NextResponse.json({ message: "The shop connection is not configured." }, { status: 503 });

  const cartToken = request.cookies.get("fb_wc_cart")?.value;
  let body: unknown;
  if (method === "POST") {
    try { body = await request.json(); }
    catch { return NextResponse.json({ message: "Please check the cart request and try again." }, { status: 400 }); }
  }

  try {
    const upstream = await fetch(`${cms}/wp-json/wc/store/v1/${endpoint}`, {
      method,
      headers: {
        Accept: "application/json",
        ...(cartToken ? { "Cart-Token": cartToken } : {}),
        ...(method === "POST" ? { "Content-Type": "application/json" } : {})
      },
      ...(method === "POST" ? { body: JSON.stringify(body) } : {}),
      cache: "no-store",
      signal: AbortSignal.timeout(20000)
    });
    const data = await upstream.json().catch(() => ({}));
    const response = NextResponse.json(data, { status: upstream.status, headers: { "Cache-Control": "no-store" } });
    const nextToken = upstream.headers.get("Cart-Token");
    if (nextToken) {
      response.cookies.set("fb_wc_cart", nextToken, {
        httpOnly: true,
        secure: process.env.NODE_ENV === "production",
        sameSite: "lax",
        path: "/",
        maxAge: 30 * 24 * 60 * 60
      });
    }
    return response;
  } catch {
    return NextResponse.json({ message: "The shop service could not be reached. Please try again." }, { status: 502 });
  }
}
