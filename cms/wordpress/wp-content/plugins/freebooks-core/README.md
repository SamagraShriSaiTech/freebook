# FreeBooks Core v4.7.0 — Customer Accounts

Cumulative update to the unified FreeBooks Core plugin. Preserves existing records and settings; adds no database tables.

## Added REST endpoints
- `POST /wp-json/freebooks/v1/account/register` — name, email, password (minimum 10 characters), optional phone.
- `POST /wp-json/freebooks/v1/account/login` — email and password.
- `GET /wp-json/freebooks/v1/account/me` — bearer-token authenticated profile.
- `POST /wp-json/freebooks/v1/account/profile` — update display name, phone and city.
- `POST /wp-json/freebooks/v1/account/logout` — revoke current token.
- `GET|POST|DELETE /wp-json/freebooks/v1/account/wishlist` — customer-owned WooCommerce product IDs.
- `GET /wp-json/freebooks/v1/account/orders` — up to 20 orders belonging to the authenticated customer.

Sessions use random opaque 256-bit tokens; only SHA-256 hashes are stored in user meta. Tokens expire after 14 days and at most five sessions are retained per account. The Next.js proxy stores tokens in an HttpOnly, SameSite=Lax cookie; tokens are not returned to browser JavaScript. Account/order data is private and account pages are noindex.

## Notes
- WooCommerce is required for customer order history and product wishlist validation. Accounts can still be created without WooCommerce (subscriber role fallback).
- Donation tracking remains separate and continues to use its OTP verification flow.
- Configure HTTPS in production. Configure WordPress mail transport separately for any WooCommerce/account email workflows.
- Test registration, login, session persistence, logout, wishlist and customer-specific order access on staging before launch.

## v4.8.0 — Membership, Loyalty & Community
- Authenticated member dashboard data, reading-interest preferences and member book requests.
- Non-cash participation recognition points for completed WooCommerce orders and accepted volunteer applications.
- Reader, Active Reader and Community Champion recognition levels.
- Moderated Community Updates content type and public updates API.
- Admin Membership Overview; no new database tables or destructive migrations.
- See `docs/v4.8.0-membership-loyalty-community.md` for endpoint details and test guidance.


## v5.0.0 — Shopping Cart & Checkout
- Adds `GET /freebooks/v1/checkout/payment-methods` with enabled/available gateway IDs and public labels only.
- Does not expose gateway credentials or change existing order/payment configuration.
- WooCommerce Store API remains authoritative for carts, stock, shipping, taxes, orders and payment.
- No destructive database migration.


## v5.1.0 — Customer Orders & After-Sales Support
- Adds token-authenticated order detail and customer support request endpoints.
- Checks order ownership and guest billing-email match before exposing details or accepting a case.
- Displays public shipment metadata from existing fulfilment fields; carrier booking and actual refunds remain external WooCommerce/carrier responsibilities.
- No destructive database migration.


## v5.2.0 — Newsletter REST Signup
- Adds a consent-aware public REST signup endpoint for the existing private newsletter subscriber records.
- Records consent date and source; applies an email-hash throttle and honeypot; sends a secure unsubscribe link.
- Does not expose subscriber records or unsubscribe tokens through the API.
- No destructive database migration.
