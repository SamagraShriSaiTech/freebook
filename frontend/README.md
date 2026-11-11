# FreeBooks Frontend v2.6.0 — Public Website Launch Readiness

## v2.6.0 changes
- Replaced raw community and impact story image elements with `next/image` for responsive image sizing and supported AVIF/WebP optimization.
- Added a media-host allowlist: approved FreeBooks media hosts use the Next.js optimizer; other externally hosted media is rendered directly without making the server optimizer fetch arbitrary URLs.
- Updated the frontend package to Next.js 15.5.29 and matching `eslint-config-next` 15.5.29.
- Added scoped dependency overrides for PostCSS 8.5.23 and minimatch 11.0.1, matching the security configuration verified in the user's local installation.
- Preserves the v2.5.1 bilingual experience, donation, membership, account, catalogue and commerce journeys.
- No WordPress plugin update or database migration is required.

## Install v2.6.0
Back up the current frontend and preserve `.env.local`. Replace the frontend project files with this cumulative package, then run `npm.cmd install`, `npm.cmd audit` and `npm.cmd run build`. Do not copy `node_modules` or `.next` from the old release.

## Launch gate
This package is a frontend code update, not proof of live integration. Before production launch, complete the manual checks in `LAUNCH-CHECKLIST-v2.6.0.md`, especially payment sandbox/live configuration, WordPress API connectivity, email delivery, shipping provider configuration, privacy/legal review and mobile device testing.

---

# FreeBooks Frontend v2.5.1 — Module 16 Build Fix

## v2.5.1 changes
- Fixed the JSX apostrophe lint error in the Privacy Policy page.
- Replaced unsupported `align-items: end` values with `flex-end` to remove Autoprefixer warnings.
- Removed unused icon imports reported by ESLint.
- Stabilized account and membership data loaders with `useCallback` and corrected their effect dependencies.
- Preserved the Module 16 English–Hindi language switcher and translations.
- No WordPress plugin update or database migration is required.

## Upgrade
Replace the v2.5.0 frontend with this cumulative v2.5.1 package and restore the existing `.env.local`.
Run `npm.cmd install` and `npm.cmd run build`.


## v2.5.0 changes
- Added a persistent English / हिन्दी language selector in the shared header.
- Language preference is saved in localStorage and a first-party cookie.
- Updates the document language (`en-IN` / `hi-IN`) when the selection changes.
- Added Devanagari-friendly font fallbacks and Hindi typography rules.
- Added Hindi interface copy for the shared navigation and footer, homepage, catalogue controls, donation form, donation tracking, account, membership, privacy centre and newsletter.
- Added Hindi hero copy to key informational pages.
- Preserved existing API routes, WooCommerce integration, account profile editor, donation workflow and privacy-request workflow.
- No WordPress plugin update or database migration is required.

## Translation scope and SEO notes
- This release localizes the principal interface and key user journeys. Long-form legal/policy copy, CMS-authored FAQ/story/opportunity content, and some secondary checkout/order-detail messages may remain in English until approved Hindi translations are supplied.
- The switcher keeps the same page URL and changes the client-side language preference. It updates the document `lang` attribute, but does not create separate crawlable `/hi/` routes or Hindi-specific hreflang URLs. Those require a separate localized-routing/SEO implementation.
- Hindi content uses system font fallbacks (`Noto Sans Devanagari`, `Nirmala UI`, `Mangal`) and does not download a new font at build time.

## Install
Replace the existing frontend with this cumulative `freebooks-frontend` folder, restore your existing `.env.local`, then run:
```powershell
npm.cmd install
npm.cmd run build
```

# FreeBooks Frontend v1.5.1

Complete cumulative Next.js frontend release for FreeBooks Core v4.8.0.

This release also fixes a Next.js dynamic-route conflict in the donation portal. The donation detail endpoint is now `/api/donation-portal/record/[reference]`; OTP endpoints remain `/api/donation-portal/request-otp` and `/api/donation-portal/verify-otp`.

## Module 6
- `/membership` — authenticated membership dashboard, recognition level, non-cash participation points, reading interests, member book requests and participation history.
- `/community` — public community updates, membership entry point and volunteer entry point.
- Header navigation links to Membership and Community.
- `/api/membership/[action]` proxies authenticated requests through the HttpOnly `fb_account` cookie to WordPress; the account token is not exposed to browser JavaScript.

## Install
Back up the existing frontend folder, then replace its project files with the contents of this ZIP. Preserve your `.env.local` file and do not overwrite it with `.env.example`. Set `WORDPRESS_BASE_URL` to the WordPress CMS origin. Then run `npm.cmd install` and `npm.cmd run dev` in Windows PowerShell.

Requires FreeBooks Core v4.8.0. WordPress Community Updates are managed in the admin area. Membership features require the user to sign in at `/account`.


## Release 1.6.0 — Public Pages, Trust & Community

- Adds About, How it Works, Impact, Transparency, FAQs, Volunteer, Contact, Privacy, Terms, and Shipping & Returns routes.
- Adds a public volunteer application form backed by the FreeBooks Core REST endpoint.
- Reads operational impact metrics, consent-approved impact stories, public FAQ posts, volunteer opportunities and configured public contact/social details from WordPress.
- Adds the public XML sitemap and robots.txt; excludes private account, membership, donation-tracking and API routes from indexing.
- Contact and impact fields are only displayed when configured or returned by the backend; no contact details or impact totals are fabricated.
- Updates desktop and mobile navigation and the site footer so all public routes are discoverable.
- Requires FreeBooks Core 4.9.0 for the new volunteer application REST endpoint and expanded public frontend configuration.

### Environment

Create `.env.local` in this project root and set `WORDPRESS_BASE_URL` to the WordPress CMS origin, for example `http://localhost/freebooks-cms` for the local XAMPP alias. Keep private credentials out of `NEXT_PUBLIC_*` variables.

### Install / run

```powershell
npm.cmd install
npm.cmd run dev
```

For production, build with `npm.cmd run build` and restart the configured Next.js application through the hosting control panel.


## Security dependency update

This release moves Next.js and React to the patched versions Next.js 15.5.29 and React 19.2.2 for the December 2025 React Server Components advisories. Reinstall dependencies from this package before running or deploying it. See the official Next.js security update: https://nextjs.org/blog/security-update-2025-12-11.


## v1.7.0 — Shopping Cart & Checkout

- WooCommerce Store API cart session via opaque Cart-Token stored in an HttpOnly, same-site cookie on the Next.js origin.
- Server-side allow-listed Store API proxy for cart, coupon, shipping-rate, and checkout operations.
- Add-to-cart controls on book cards and book detail pages.
- Cart page with quantity changes, removal, coupon application and WooCommerce-calculated totals.
- Checkout page with customer/shipping address fields, destination-based shipping-rate selection, and enabled payment methods returned by FreeBooks Core.
- WooCommerce remains the system of record for stock, shipping, taxes, payment processing and orders.

Requires WooCommerce and FreeBooks Core v5.0.0. Configure payment gateways in WooCommerce; only enabled/available gateways are exposed. Gateway-specific payment data may require the relevant gateway's Store API integration.


## v1.8.0 — Customer Orders & After-Sales Support

- Account order detail view with item breakdown, WooCommerce order status and payment method.
- Displays fulfilment status, carrier, tracking/AWB number, dispatch date, ETA and validated carrier tracking URL when staff have recorded them.
- Authenticated account customers can submit support, return, refund, wrong-item, damaged-item, missing-item and delivery requests against an order.
- Support request references and status are displayed in the account order view.
- Customer access is verified against the authenticated account token and order customer ID (or matching billing email for guest orders).
- Refunds are never issued automatically; all requests remain subject to staff review and actual refunds are handled in WooCommerce.


## v1.9.0 — Newsletter & Reader Engagement

- Adds a responsive newsletter signup section in the public footer.
- Requires explicit email consent and includes a honeypot field.
- Signup requests are proxied through Next.js to the unified FreeBooks Core REST endpoint.
- Existing private subscriber records and secure unsubscribe links are reused.
- Subscriber email addresses and unsubscribe tokens are never returned to the browser.


## v2.0.0 — SEO, Accessibility & Performance Foundations

- Adds site-wide Organization and WebSite JSON-LD, including catalogue SearchAction.
- Adds Product and BreadcrumbList JSON-LD on individual book pages, using live WooCommerce price and stock state only.
- Adds per-book canonical and richer Open Graph/Twitter metadata.
- Adds skip-to-content link, main landmark target, visible keyboard focus and reduced-motion support.
- Adds baseline security headers and production-only HSTS.
- Enables Next.js image optimization for the known local WordPress and production CMS upload hosts with AVIF/WebP formats.
- Keeps cart, checkout, account, membership, donation tracking and order confirmation out of indexing.

A full accessibility audit, Lighthouse measurement, structured-data validator check and production HTTPS/header verification remain launch acceptance tasks.


## v2.1.0 — Wishlist & Book Sharing

- Adds a save/unsave control on book detail pages, backed by the authenticated FreeBooks wishlist API.
- Logged-out visitors are directed to sign in before saving a book.
- Account portal supports removing saved books.
- Adds native device sharing with clipboard fallback for book links.
- Reuses existing account and wishlist endpoints; no WordPress plugin update or data migration required beyond Core v5.2.0 baseline.


## v2.2.0 — Customer Profile Settings
- Adds an account profile editor for display name, phone number and city.
- Uses the existing authenticated `POST /wp-json/freebooks/v1/account/profile` endpoint through the Next.js same-origin API proxy.
- Email is shown read-only; account email changes are not exposed by this release.
- Profile updates do not change order, donation or membership records.
- Requires the existing FreeBooks Core v5.2.0 account API; no plugin update or database migration is required.


## v2.3.0 — Module 14: Privacy Centre
- Added `/privacy-centre` for signed-in data access/export and account-deletion requests.
- Requests are authenticated through the existing account session and routed through the Next.js server proxy.
- Deletion is request-based and does not immediately erase account, order or donation records.
- Requires FreeBooks Core v5.3.0 for the matching REST endpoint and administrator request queue.

### Privacy Centre API compatibility
The v2.3.0 `/privacy-centre` page requires the FreeBooks Core v5.3.0 authenticated `account/privacy-request` endpoint. Keep the WordPress CMS private/noindex and retain the existing `WORDPRESS_BASE_URL` server environment setting.


## v2.4.0 — Module 15: Donation & Donor Experience
- Reworked the donation submission into a three-step, accessible guided flow: donor details, donated items, and handover/consent.
- Added step validation, clear progress state, and mobile-friendly navigation.
- Added privacy guidance for uploaded item photos and clearer explanation that submission is a review request, not acceptance or a pickup booking.
- Added copy-reference action and direct donation tracking link after successful submission.
- Preserved existing donation API contract, item fields, consent handling, Turnstile integration, and photo upload limits.
