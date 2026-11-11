import { NextResponse } from "next/server";
export const runtime = "nodejs";
export async function GET() {
  const cms = (process.env.WORDPRESS_BASE_URL || "").replace(/\/$/, "");
  if (!cms) return NextResponse.json({ methods: [], message: "The shop connection is not configured." }, { status: 503 });
  try {
    const response = await fetch(`${cms}/wp-json/freebooks/v1/checkout/payment-methods`, {
      headers: { Accept: "application/json" }, cache: "no-store", signal: AbortSignal.timeout(15000)
    });
    const data = await response.json().catch(() => ({}));
    return NextResponse.json(data, { status: response.status, headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ methods: [], message: "Payment options could not be loaded." }, { status: 502 });
  }
}
