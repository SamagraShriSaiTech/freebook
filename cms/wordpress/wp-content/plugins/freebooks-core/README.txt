FreeBooks Core 4.9.0

Release notes
- Adds a rate-limited public REST endpoint for volunteer applications at /wp-json/freebooks/v1/volunteer/apply.
- Extends the public frontend-config endpoint with configured contact/social channels and impact metrics.
- Adds a public impact-stories endpoint that returns only published stories with publication permission confirmed.
- Exposes the existing operational and Foundation-entered impact figures through the public impact endpoint.
- No database schema migration or destructive data change is introduced.
- Existing donation, catalogue, account, membership, order and community records are preserved.

Compatibility
- WordPress 6.3+
- PHP 8.1+
- WooCommerce required for commerce features.
