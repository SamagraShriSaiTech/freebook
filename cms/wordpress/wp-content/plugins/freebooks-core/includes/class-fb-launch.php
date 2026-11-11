<?php
if (!defined('ABSPATH')) exit;

/**
 * FreeBooks engagement and launch-readiness utilities.
 * Does not process payments, store credentials, or duplicate carrier tracking.
 */
class FB_Launch {
    public static function init() {
        add_shortcode('freebooks_social_links', [self::class, 'social_links']);
        add_shortcode('freebooks_share_buttons', [self::class, 'share_buttons']);
        add_shortcode('freebooks_newsletter_cta', [self::class, 'newsletter_cta']);
        add_action('admin_menu', [self::class, 'admin_menu'], 40);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
    }

    public static function assets() {
        if (is_admin()) return;
        $css = '.fb-core-social-links,.fb-core-share{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:16px 0}.fb-core-social-links a,.fb-core-share a,.fb-core-share button{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border:1px solid #176b55;border-radius:9px;background:#fff;color:#176b55;text-decoration:none;font-weight:700;font-size:14px;cursor:pointer}.fb-core-social-links a:hover,.fb-core-share a:hover,.fb-core-share button:hover{background:#e8f3ed}.fb-core-newsletter-cta{padding:clamp(18px,3vw,28px);margin:22px 0;background:#fff9ef;border:1px solid #e8f3ed;border-radius:16px;color:#24332f}.fb-core-newsletter-cta h2{color:#176b55;margin-top:0}.fb-core-launch-table{max-width:1050px}.fb-core-launch-table td:first-child{font-weight:600}.fb-core-launch-ok{color:#176b55;font-weight:700}.fb-core-launch-warn{color:#9a5c30;font-weight:700}';
        wp_register_style('freebooks-launch-inline', false, [], FREEBOOKS_CORE_VERSION);
        wp_enqueue_style('freebooks-launch-inline');
        wp_add_inline_style('freebooks-launch-inline', $css);
        wp_register_script('freebooks-launch-inline', '', [], FREEBOOKS_CORE_VERSION, true);
        wp_enqueue_script('freebooks-launch-inline');
        wp_add_inline_script('freebooks-launch-inline', 'document.addEventListener("click",function(e){var b=e.target.closest(".fb-core-copy-link");if(!b)return;var u=b.getAttribute("data-copy-url");if(!u)return;if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(u).then(function(){var t=b.textContent;b.textContent="Copied";setTimeout(function(){b.textContent=t;},1800);}).catch(function(){window.prompt("Copy this link:",u);});}else{window.prompt("Copy this link:",u);}});');
    }

    public static function social_links($atts = []) {
        $s = (array)get_option('freebooks_settings', []);
        $items = [
            'whatsapp' => ['WhatsApp', '💬'],
            'facebook' => ['Facebook', 'f'],
            'instagram' => ['Instagram', '◎'],
            'youtube' => ['YouTube', '▶'],
            'linkedin' => ['LinkedIn', 'in'],
        ];
        $links = [];
        foreach ($items as $key => $meta) {
            $url = trim((string)($s[$key] ?? ''));
            if (!$url || !wp_http_validate_url($url)) continue;
            $links[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr($meta[0]) . '">' . esc_html($meta[1] . ' ' . $meta[0]) . '</a>';
        }
        if (!$links) return current_user_can('manage_options') ? '<p class="fb-core-muted">Add social URLs under Settings → FreeBooks settings to display social links here.</p>' : '';
        return '<nav class="fb-core-social-links" aria-label="Follow FreeBooks">' . implode('', $links) . '</nav>';
    }

    public static function share_buttons($atts = []) {
        $atts = shortcode_atts(['title' => '', 'url' => ''], $atts, 'freebooks_share_buttons');
        $url = $atts['url'] ? esc_url_raw($atts['url']) : get_permalink();
        if (!$url || !wp_http_validate_url($url)) return '';
        $title = $atts['title'] ? sanitize_text_field($atts['title']) : wp_strip_all_tags(get_the_title());
        $encoded_url = rawurlencode($url);
        $encoded_title = rawurlencode($title);
        $links = [
            'WhatsApp' => 'https://wa.me/?text=' . $encoded_title . '%20' . $encoded_url,
            'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $encoded_url,
            'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encoded_url,
        ];
        $html = '<nav class="fb-core-share" aria-label="Share this page"><strong>Share:</strong>';
        foreach ($links as $label => $href) $html .= '<a href="' . esc_url($href) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>';
        $html .= '<button type="button" class="fb-core-copy-link" data-copy-url="' . esc_attr($url) . '">Copy link</button></nav>';
        return $html;
    }

    public static function newsletter_cta($atts = []) {
        $atts = shortcode_atts(['heading' => 'Stay connected with FreeBooks', 'text' => 'Get occasional updates about new books, learning resources and community activities.'], $atts, 'freebooks_newsletter_cta');
        return '<section class="fb-core-newsletter-cta"><h2>' . esc_html($atts['heading']) . '</h2><p>' . esc_html($atts['text']) . '</p>' . do_shortcode('[freebooks_newsletter_form]') . '</section>';
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type=fb_donation', 'Launch readiness', 'Launch readiness', 'manage_options', 'freebooks-launch-readiness', [self::class, 'readiness_page']);
    }

    private static function check($label, $ok, $detail) {
        return ['label' => $label, 'ok' => (bool)$ok, 'detail' => $detail];
    }

    private static function checks() {
        $s = (array)get_option('freebooks_settings', []);
        $checks = [];
        $checks[] = self::check('WooCommerce', class_exists('WooCommerce'), class_exists('WooCommerce') ? 'Active' : 'Install and activate WooCommerce if the shop will sell items online.');
        $checks[] = self::check('HTTPS', is_ssl(), is_ssl() ? 'The current admin request is using HTTPS.' : 'Use HTTPS on the production domain before accepting customer information or payments.');
        $checks[] = self::check('Permalinks', (string)get_option('permalink_structure') !== '', (string)get_option('permalink_structure') !== '' ? 'Pretty permalinks are enabled.' : 'Choose a readable permalink structure under Settings → Permalinks.');
        $checks[] = self::check('Site email', is_email(get_option('admin_email')), is_email(get_option('admin_email')) ? 'A valid WordPress administration email is configured. Test actual delivery before launch.' : 'Set a valid administration email under Settings → General.');
        $checks[] = self::check('Contact email', !empty($s['contact_email']) && is_email($s['contact_email']), !empty($s['contact_email']) ? 'Contact email is configured; verify that it is monitored.' : 'Add the Foundation contact email under Settings → FreeBooks settings.');
        $checks[] = self::check('Contact phone', !empty($s['contact_phone']), !empty($s['contact_phone']) ? 'Phone number is configured; verify click-to-call on mobile.' : 'Add an official contact phone number if available.');
        $checks[] = self::check('Social links', !empty($s['facebook']) || !empty($s['instagram']) || !empty($s['whatsapp']) || !empty($s['youtube']) || !empty($s['linkedin']), 'Add only official, verified social URLs under Settings → FreeBooks settings.');
        $checks[] = self::check('Donation page', (bool)get_page_by_path('donate'), get_page_by_path('donate') ? 'Page exists; confirm it contains [freebooks_donation_form].' : 'Create a Donate page and add [freebooks_donation_form].');
        $checks[] = self::check('Shop page', (bool)get_page_by_path('shop'), get_page_by_path('shop') ? 'Page exists; confirm it contains [freebooks_storefront].' : 'Create a Shop page and add [freebooks_storefront].');
        $checks[] = self::check('Privacy policy', (bool)get_page_by_path('privacy-policy'), get_page_by_path('privacy-policy') ? 'Page exists; have the final policy reviewed and publish it.' : 'Create and publish the privacy policy before collecting personal data.');
        $checks[] = self::check('Shipping and returns', (bool)get_page_by_path('shipping-returns'), get_page_by_path('shipping-returns') ? 'Page exists; confirm actual carrier, costs, delivery estimates and return terms.' : 'Publish accurate shipping and returns terms.');
        $checks[] = self::check('Payment gateway', self::has_enabled_gateway(), self::has_enabled_gateway() ? 'At least one WooCommerce gateway is enabled. Credentials, webhook delivery and settlement still require final testing.' : 'Configure a supported WooCommerce payment gateway. Do not enable live payments until end-to-end testing is complete.');
        $checks[] = self::check('Backup plan', false, 'Confirm scheduled off-site database and wp-content backups with a tested restore procedure.');
        $checks[] = self::check('Carrier integration', class_exists('WooCommerce'), 'Confirm the selected carrier WooCommerce extension is configured. Carrier plugin owns labels, tracking IDs and delivery notifications.');
        $checks[] = self::check('Analytics and Search Console', false, 'Connect consent-aware analytics and verify Google Search Console/Bing Webmaster Tools on the public frontend.');
        return $checks;
    }

    private static function has_enabled_gateway() {
        if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) return false;
        foreach (WC()->payment_gateways()->payment_gateways() as $gateway) if (($gateway->enabled ?? 'no') === 'yes') return true;
        return false;
    }

    public static function readiness_page() {
        if (!current_user_can('manage_options')) return;
        $checks = self::checks();
        $passed = count(array_filter($checks, function($c) { return $c['ok']; }));
        echo '<div class="wrap"><h1>FreeBooks launch readiness</h1>';
        echo '<p>This checklist is a configuration aid, not a certification or proof of production readiness. Complete final payment, email, security, privacy and shipping tests before launch.</p>';
        echo '<p><strong>' . esc_html($passed . ' of ' . count($checks) . ' checks currently detected as configured.') . '</strong> Items marked for manual review must be verified by your team.</p>';
        echo '<table class="widefat striped fb-core-launch-table"><thead><tr><th>Area</th><th>Detected status</th><th>Action / notes</th></tr></thead><tbody>';
        foreach ($checks as $check) {
            echo '<tr><td>' . esc_html($check['label']) . '</td><td class="' . ($check['ok'] ? 'fb-core-launch-ok' : 'fb-core-launch-warn') . '">' . ($check['ok'] ? 'Configured' : 'Review required') . '</td><td>' . esc_html($check['detail']) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a class="button" href="' . esc_url(admin_url('options-general.php?page=freebooks-settings')) . '">Open FreeBooks settings</a> ';
        if (class_exists('WooCommerce')) echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '">Payment settings</a>';
        echo '</p></div>';
    }
}
