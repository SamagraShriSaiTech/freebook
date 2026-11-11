# FreeBooks Public Website — Launch Checklist (v2.6.0)

This checklist separates code-level checks from configuration and live-service checks. A checked build does not mean the external services below have been tested.

## A. Build and release integrity
- [ ] Confirm `npm.cmd pkg get version` returns `2.6.0`.
- [ ] Run `npm.cmd install` from the release folder.
- [ ] Run `npm.cmd audit`; target: 0 vulnerabilities.
- [ ] Run `npm.cmd run build`; target: successful production build.
- [ ] Confirm `.env.local` is present and contains the correct production values; never commit or share it.
- [ ] Confirm production hosting uses a supported Node.js version and starts the Next.js app correctly.

## B. WordPress and WooCommerce integration
- [ ] `WORDPRESS_BASE_URL` points to the production CMS origin, not localhost.
- [ ] Public FreeBooks Core REST endpoints return expected data without exposing private records.
- [ ] WooCommerce Store API product, cart, coupon, shipping and checkout requests work on the production domain.
- [ ] WordPress CMS remains noindex; public SEO is served by the Next.js frontend.
- [ ] CORS, allowed origins, HTTPS and proxy routes are restricted to the intended frontend.
- [ ] Donation submissions, uploads, OTP tracking and donor portal are tested end-to-end.

## C. Payments, shipping and email
- [ ] Razorpay/Cashfree keys are configured in server-side environment variables only.
- [ ] Payment success, failure, cancellation, duplicate webhook and delayed webhook scenarios are tested.
- [ ] Webhook signatures are verified; order/payment state is not trusted from the browser redirect alone.
- [ ] Shipping carrier account, pickup settings, serviceability, rates, label generation and tracking are tested.
- [ ] Order, donation, OTP and newsletter emails are delivered and sender-domain authentication is configured.
- [ ] COD remains disabled unless explicitly approved and operationally supported.

## D. Customer journeys
- [ ] Browse, search, filter, book detail, sold-item state and unavailable/empty catalogue states.
- [ ] Add to cart, update quantity, remove item, apply coupon, calculate shipping and complete checkout.
- [ ] Account registration/login, profile update, order history, wishlist and logout.
- [ ] Donation form validation, upload restrictions, duplicate/rate-limit handling, acknowledgement and tracking.
- [ ] Volunteer application and newsletter consent/unsubscribe.
- [ ] English/Hindi selector, persistence after navigation/reload, and review of untranslated secondary/legal content.

## E. Responsive, accessibility and browser checks
- [ ] Test current Chrome, Edge, Firefox and Safari where available.
- [ ] Test mobile widths around 360px, 390px and 430px; tablet and desktop widths.
- [ ] Confirm no horizontal overflow, clipped navigation, overlapping buttons or unreadable form fields.
- [ ] Keyboard-only navigation, visible focus, skip link, labels, validation messages and meaningful image alt text.
- [ ] Check reduced-motion behavior and adequate text/background contrast.
- [ ] Verify optimized images load from the approved media host; check fallback behavior for other image hosts.

## F. SEO, privacy, legal and operations
- [ ] Verify canonical URLs, metadata, Open Graph, sitemap and robots directives on production.
- [ ] Ensure cart, checkout, account, membership and private tracking pages are not indexed.
- [ ] Validate Organization, WebSite, Product and Breadcrumb structured data where applicable.
- [ ] Foundation reviews Privacy, Terms, Shipping/Returns, contact/grievance details and consent/retention practices before publication.
- [ ] Do not publish unverified impact numbers, tax exemption claims, certifications or contact details.
- [ ] Configure backups for WordPress database, uploads and frontend deployment; test restore procedure.
- [ ] Configure uptime/error monitoring and review server logs after a controlled test order and donation.

## G. Go-live approval
- [ ] Production owner signs off payment, shipping, privacy/legal and customer-support readiness.
- [ ] Complete one controlled real-world donation and one controlled order, then reconcile records in WordPress/WooCommerce and payment/carrier dashboards.
- [ ] Remove test content and test accounts; confirm no secrets or debug endpoints are exposed.
- [ ] Record release version, deployment time, backup location and rollback procedure.
