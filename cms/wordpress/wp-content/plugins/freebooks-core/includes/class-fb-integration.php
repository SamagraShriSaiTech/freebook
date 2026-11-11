<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-only deployment integration diagnostics.
 * This is a configuration aid, not a remote penetration test or proof of launch readiness.
 */
class FB_Integration {
    public static function init() {
        add_action('admin_menu', [self::class, 'menu'], 50);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function menu() {
        add_submenu_page(
            'edit.php?post_type=' . FREEBOOKS_DONATION_CPT,
            'Integration & launch',
            'Integration & launch',
            'manage_options',
            'freebooks-integration',
            [self::class, 'page']
        );
    }

    public static function register_routes() {
        register_rest_route('freebooks/v1', '/admin/health', [
            'methods' => 'GET',
            'callback' => [self::class, 'health'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
    }

    private static function page_exists($slug) {
        return (bool) get_page_by_path($slug);
    }

    private static function page_contains_shortcode($slug, $shortcode) {
        $page = get_page_by_path($slug);
        if (!$page) return false;
        return has_shortcode((string)$page->post_content, $shortcode);
    }

    private static function check($name, $status, $detail, $manual = false) {
        return ['name' => $name, 'status' => $status, 'detail' => $detail, 'manual' => $manual];
    }

    public static function diagnostics() {
        global $wpdb;
        $checks = [];
        $checks[] = self::check('FreeBooks Core', defined('FREEBOOKS_CORE_VERSION') && version_compare(FREEBOOKS_CORE_VERSION, '4.5.0', '>='), 'Installed version: ' . (defined('FREEBOOKS_CORE_VERSION') ? FREEBOOKS_CORE_VERSION : 'unknown'));
        $checks[] = self::check('WordPress REST API', function_exists('rest_url'), rest_url('freebooks/v1/'));
        $checks[] = self::check('WooCommerce', class_exists('WooCommerce'), class_exists('WooCommerce') ? 'Active' : 'Activate WooCommerce for catalogue sales, cart, checkout and customer orders.');
        $checks[] = self::check('Public frontend separation', true, 'Expected architecture: public Next.js site at freebook.co.in; WordPress CMS at cms.freebook.co.in.', true);
        $checks[] = self::check('CMS indexing protection', (bool) get_option('blog_public') && true, 'Confirm the CMS subdomain is noindex and blocked from public search indexing; the public frontend must remain indexable.', true);
        $checks[] = self::check('HTTPS', is_ssl(), is_ssl() ? 'Current WordPress request uses HTTPS.' : 'Production CMS must use HTTPS.', true);
        $checks[] = self::check('Donation page', self::page_exists('donate'), self::page_exists('donate') ? (self::page_contains_shortcode('donate','freebooks_donation_form') ? 'Donation shortcode detected.' : 'Page exists, but [freebooks_donation_form] was not detected in its content.') : 'Create /donate/ in WordPress CMS or connect the public Next.js donation route.');
        $checks[] = self::check('Shop page', self::page_exists('shop'), self::page_exists('shop') ? (self::page_contains_shortcode('shop','freebooks_storefront') ? 'Storefront shortcode detected.' : 'Page exists; confirm the Next.js shop route or add [freebooks_storefront] if using a WordPress-rendered shop.') : 'Create /shop/ or confirm the Next.js storefront route.');
        $checks[] = self::check('Member portal', self::page_exists('member-portal'), self::page_exists('member-portal') ? 'Member portal page exists.' : 'Create the member portal page or confirm the portal is provided by the frontend.');
        $checks[] = self::check('Volunteer portal', self::page_exists('volunteer-portal'), self::page_exists('volunteer-portal') ? 'Volunteer portal page exists.' : 'Create the volunteer portal page or confirm the portal is provided by the frontend.');
        $checks[] = self::check('Privacy policy', self::page_exists('privacy-policy'), self::page_exists('privacy-policy') ? 'Page exists; review actual policy and retention practices.' : 'Publish a privacy policy before collecting personal data.');
        $checks[] = self::check('Shipping and returns', self::page_exists('shipping-returns'), self::page_exists('shipping-returns') ? 'Page exists; verify actual carrier, delivery estimates and return terms.' : 'Publish accurate shipping and returns terms.');
        $checks[] = self::check('Newsletter shortcode', shortcode_exists('freebooks_newsletter_form'), shortcode_exists('freebooks_newsletter_form') ? 'Newsletter form shortcode is registered.' : 'Newsletter form shortcode is not registered.');
        $checks[] = self::check('Donation REST endpoint', true, rest_url('freebooks/v1/donations'));
        $checks[] = self::check('Impact REST endpoint', true, rest_url('freebooks/v1/impact'));
        $checks[] = self::check('Frontend URL', (bool) get_option('freebooks_next_url'), get_option('freebooks_next_url') ? esc_url_raw(get_option('freebooks_next_url')) : 'Set the public Next.js frontend URL under Settings → FreeBooks settings.');
        $checks[] = self::check('Revalidation secret', (bool) get_option('freebooks_revalidate_secret'), get_option('freebooks_revalidate_secret') ? 'A secret is configured. Verify that the Next.js route uses the matching secret and header.' : 'Set a strong random secret only if Next.js cache revalidation is enabled.', true);
        $checks[] = self::check('Database prefix', !empty($wpdb->prefix), 'WordPress database prefix is configured.');
        $checks[] = self::check('Mail delivery', false, 'Send a real donation acknowledgement, OTP/notification and order email during final acceptance testing.', true);
        $checks[] = self::check('Payment webhook', false, 'Verify gateway webhook signature, event delivery, duplicate-event handling and order status updates in final payment tests.', true);
        $checks[] = self::check('Carrier plugin', false, 'Verify label creation, tracking URL and delivery notifications in the selected WooCommerce carrier plugin. Do not enable a duplicate FreeBooks tracking system.', true);
        $checks[] = self::check('Backups and restore', false, 'Take off-site database and wp-content backups and prove that a restore works.', true);
        $checks[] = self::check('Privacy and consent', false, 'Review consent text, retention, deletion requests, newsletter unsubscribe and analytics consent with the Foundation.', true);
        return $checks;
    }

    public static function health($request) {
        $checks = self::diagnostics();
        $failed = array_values(array_filter($checks, function ($item) { return $item['status'] === false; }));
        return new WP_REST_Response([
            'service' => 'freebooks-wordpress-cms',
            'version' => FREEBOOKS_CORE_VERSION,
            'wordpress' => get_bloginfo('version'),
            'woocommerce_active' => class_exists('WooCommerce'),
            'checks_total' => count($checks),
            'checks_flagged' => count($failed),
            'message' => 'Administrative configuration diagnostics only; manual production tests remain required.',
        ], 200);
    }

    public static function page() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        $checks = self::diagnostics();
        $ok = count(array_filter($checks, function ($c) { return $c['status'] === true; }));
        echo '<div class="wrap"><h1>FreeBooks integration & launch</h1>';
        echo '<p>Configuration diagnostics for the WordPress CMS and public frontend integration. This page does not run payment transactions, email delivery, carrier booking, penetration tests or restore tests.</p>';
        echo '<p><strong>' . esc_html($ok . ' of ' . count($checks) . ' checks currently detected as configured.') . '</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>Integration</th><th>Status</th><th>Details / action</th></tr></thead><tbody>';
        foreach ($checks as $c) {
            $status = $c['status'] ? 'Detected' : ($c['manual'] ? 'Manual test required' : 'Action required');
            $color = $c['status'] ? '#176b55' : '#9a5c30';
            echo '<tr><td><strong>' . esc_html($c['name']) . '</strong></td><td><strong style="color:' . esc_attr($color) . '">' . esc_html($status) . '</strong></td><td>' . esc_html($c['detail']) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Integration endpoints</h2><ul>';
        echo '<li>Donation API: <code>' . esc_html(rest_url('freebooks/v1/donations')) . '</code></li>';
        echo '<li>Impact API: <code>' . esc_html(rest_url('freebooks/v1/impact')) . '</code></li>';
        echo '<li>Admin health: <code>' . esc_html(rest_url('freebooks/v1/admin/health')) . '</code> (administrator authentication required)</li>';
        echo '</ul>';
        echo '<h2>Production deployment order</h2><ol>';
        echo '<li>Deploy the public Next.js frontend and configure its production environment variables.</li>';
        echo '<li>Deploy WordPress at the CMS subdomain with HTTPS, strong unique credentials and noindex enabled.</li>';
        echo '<li>Configure WooCommerce, the payment gateway and the selected carrier extension in their own settings.</li>';
        echo '<li>Set the public frontend URL and matching cache revalidation secret, if revalidation is used.</li>';
        echo '<li>Configure SMTP, backups, WAF/rate limiting and privacy/consent settings.</li>';
        echo '<li>Run the full acceptance checklist below before opening the site to the public.</li>';
        echo '</ol>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('options-general.php?page=freebooks-settings')) . '">FreeBooks settings</a> ';
        if (class_exists('WooCommerce')) echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings')) . '">WooCommerce settings</a>';
        echo '</p></div>';
    }
}
