import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";

const MAX_FILE_BYTES = 5 * 1024 * 1024;
const ALLOWED_TYPES = new Set(["image/jpeg", "image/png", "image/webp"]);

export async function POST(request: NextRequest) {
  const cms = (process.env.WORDPRESS_BASE_URL || "").replace(/\/$/, "");
  if (!cms) return NextResponse.json({ message: "Donation service is not configured yet. Please contact the FreeBooks team." }, { status: 503 });

  let incoming: FormData;
  try {
    incoming = await request.formData();
  } catch {
    return NextResponse.json({ message: "The form could not be read. Please try again." }, { status: 400 });
  }

  const payloadText = incoming.get("payload");
  if (typeof payloadText !== "string") return NextResponse.json({ message: "Please complete the donation form." }, { status: 400 });

  let payload: Record<string, unknown>;
  try {
    payload = JSON.parse(payloadText) as Record<string, unknown>;
  } catch {
    return NextResponse.json({ message: "Some donation details are invalid. Please review the form." }, { status: 400 });
  }

  const donor = payload.donor as Record<string, unknown> | undefined;
  const items = payload.items;
  if (!donor || typeof donor.name !== "string" || !donor.name.trim() ||
      typeof donor.email !== "string" || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(donor.email) ||
      typeof donor.phone !== "string" || !donor.phone.trim() ||
      !Array.isArray(items) || items.length < 1 || items.length > 20 || payload.consent !== true) {
    return NextResponse.json({ message: "Please provide your name, valid email, phone, at least one item and consent." }, { status: 400 });
  }

  const upstream = new FormData();
  upstream.set("payload", JSON.stringify(payload));
  upstream.set("fb_website", String(incoming.get("fb_website") || ""));
  upstream.set("cf-turnstile-response", String(incoming.get("cf-turnstile-response") || ""));

  const photos = incoming.getAll("fb_photos[]").filter((entry): entry is File => entry instanceof File && entry.size > 0);
  if (photos.length > 3) return NextResponse.json({ message: "You can attach up to 3 photos." }, { status: 400 });
  for (const photo of photos) {
    if (photo.size > MAX_FILE_BYTES) return NextResponse.json({ message: "Each photo must be 5 MB or smaller." }, { status: 400 });
    if (!ALLOWED_TYPES.has(photo.type)) return NextResponse.json({ message: "Photos must be JPG, PNG or WebP." }, { status: 400 });
    upstream.append("fb_photos[]", photo, photo.name);
  }

  try {
    const response = await fetch(`${cms}/wp-json/freebooks/v1/donations/submit`, {
      method: "POST",
      body: upstream,
      cache: "no-store",
      headers: { Accept: "application/json" },
      signal: AbortSignal.timeout(30000)
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      const message = typeof result.message === "string" ? result.message : "We could not submit your donation. Please try again.";
      return NextResponse.json({ message }, { status: response.status });
    }
    return NextResponse.json({
      donation_id: result.donation_id,
      status: result.status || "submitted"
    }, { status: 201 });
  } catch {
    return NextResponse.json({ message: "The donation service could not be reached. Your form has not been submitted; please try again shortly." }, { status: 502 });
  }
}
