<?php
if (!defined('ABSPATH')) exit;

/**
 * Launch hardening checklist and environment diagnostics.
 * Manual controls are recorded as attestations; no data is deleted or modified automatically.
 */
class FB_Launch_Hardening {
    const OPTION = 'freebooks_launch_hardening_checklist';

    public static function init() {
        add_action('admin_menu', [self::class, 'menu'], 90);
        add_action('admin_post_fb_launch_hardening_save', [self::class, 'save']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function items() {
        return [
            'backup_restore' => ['Backup and restore', 'An off-site database and wp-content backup exists, and a restore test has been completed.'],
            'mfa' => ['Administrator MFA', 'All administrator accounts use unique credentials and multi-factor authentication.'],
            'https' => ['HTTPS and TLS', 'The public frontend and CMS both use valid HTTPS certificates and redirect HTTP to HTTPS.'],
            'updates' => ['Updates reviewed', 'WordPress core, themes, plugins and PHP are supported and patched; unused extensions are removed.'],
            'file_editor' => ['Dashboard file editor disabled', 'DISALLOW_FILE_EDIT is enabled in wp-config.php.'],
            'privacy_policy' => ['Privacy policy reviewed', 'The published policy accurately names purposes, providers, retention, contact route and data-rights process.'],
            'retention' => ['Retention schedule approved', 'The Foundation has approved retention periods and an authorised process for deletion/anonymisation.'],
            'consent' => ['Consent and opt-outs tested', 'Donation, volunteer, newsletter and analytics consent are clear, recorded where required, and withdrawal works.'],
            'accessibility_keyboard' => ['Keyboard accessibility', 'Menus, dialogs, forms, filters and checkout can be used without a mouse, with visible focus.'],
            'accessibility_content' => ['Accessible content and forms', 'Images have meaningful alt text, form fields have labels, errors are announced, and headings are logical.'],
            'accessibility_visual' => ['Visual accessibility', 'Text contrast, zoom/reflow, touch targets and reduced-motion behaviour have been reviewed.'],
            'payments' => ['Payment acceptance test', 'Test and live payment flows, webhook signatures, duplicate events, refunds and failed payments have been verified.'],
            'email' => ['Email delivery test', 'Transactional messages reach inboxes; SPF, DKIM, DMARC, bounce handling and sender identity have been checked.'],
            'shipping' => ['Shipping provider test', 'A test shipment, label, tracking link, exception and delivery update have been verified in the carrier plugin.'],
            'incident' => ['Incident response owner', 'A named responsible person and contact route exist for security incidents, outages and data requests.'],
        ];
    }

    public static function menu() {
        add_submenu_page(
            'edit.php?post_type=' . FREEBOOKS_DONATION_CPT,
            'Launch hardening',
            'Launch hardening',
            'manage_options',
            'freebooks-launch-hardening',
            [self::class, 'page']
        );
    }

    public static function environment_checks() {
        global $wp_version, $wpdb;
        $checks = [];
        $checks[] = ['name'=>'PHP support baseline','ok'=>version_compare(PHP_VERSION, '8.1', '>='),'detail'=>'PHP ' . PHP_VERSION . ' detected; FreeBooks requires PHP 8.1 or newer.'];
        $checks[] = ['name'=>'WordPress support baseline','ok'=>version_compare(get_bloginfo('version'), '6.3', '>='),'detail'=>'WordPress ' . get_bloginfo('version') . ' detected; keep core on a supported, patched release.'];
        $checks[] = ['name'=>'HTTPS request','ok'=>is_ssl(),'detail'=>is_ssl()?'Current CMS request is HTTPS.':'CMS request is not HTTPS. Production must use HTTPS.'];
        $checks[] = ['name'=>'Database connection','ok'=>(bool)$wpdb->get_var('SELECT 1'),'detail'=>'A lightweight database connection check.'];
        $checks[] = ['name'=>'Debug display','ok'=>!(defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY),'detail'=>(defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY)?'WP_DEBUG_DISPLAY is enabled; disable public error display in production.':'Public debug display is not enabled or is using the default.'];
        $checks[] = ['name'=>'Dashboard file editor','ok'=>defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,'detail'=>(defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT)?'DISALLOW_FILE_EDIT is enabled.':'Add define(\'DISALLOW_FILE_EDIT\', true); to wp-config.php.'];
        $checks[] = ['name'=>'Security headers feature','ok'=>!empty(FB_Security::settings()['security_headers']),'detail'=>!empty(FB_Security::settings()['security_headers'])?'FreeBooks security response headers are enabled. Verify response headers on the live domain.':'Enable security response headers in FreeBooks → Security controls.'];
        $checks[] = ['name'=>'Privacy policy page','ok'=>(bool)get_option('wp_page_for_privacy_policy'),'detail'=>get_option('wp_page_for_privacy_policy')?'A WordPress privacy policy page is assigned. Review its actual contents.':'Assign and publish a privacy policy in Settings → Privacy.'];
        $checks[] = ['name'=>'Public frontend URL','ok'=>(bool)wp_http_validate_url((string)get_option('freebooks_next_url','')),'detail'=>get_option('freebooks_next_url')?'A URL is configured; reachability and security are not tested here.':'Configure after the Next.js frontend is deployed.'];
        return $checks;
    }

    public static function register_routes() {
        register_rest_route('freebooks/v1', '/admin/launch-hardening', [
            'methods' => 'GET',
            'callback' => function() {
                return rest_ensure_response([
                    'version' => FREEBOOKS_CORE_VERSION,
                    'environment' => self::environment_checks(),
                    'checklist' => get_option(self::OPTION, []),
                    'note' => 'Manual checklist entries are administrator attestations, not automated verification.',
                ]);
            },
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ]);
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.', 403);
        check_admin_referer('fb_launch_hardening_save');
        $input = isset($_POST['fb_launch_check']) ? (array)wp_unslash($_POST['fb_launch_check']) : [];
        $saved = [];
        foreach (self::items() as $key=>$item) {
            $saved[$key] = !empty($input[$key]) ? 1 : 0;
        }
        update_option(self::OPTION, $saved, false);
        if (class_exists('FB_Governance')) FB_Governance::log('launch_checklist_updated', 0, 'Launch readiness checklist updated.');
        wp_safe_redirect(add_query_arg(['post_type'=>FREEBOOKS_DONATION_CPT,'page'=>'freebooks-launch-hardening','saved'=>'1'], admin_url('edit.php')));
        exit;
    }

    public static function page() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.', 403);
        $saved = (array)get_option(self::OPTION, []);
        $items = self::items();
        $done = count(array_filter($items, function($item,$key) use ($saved) { return !empty($saved[$key]); }, ARRAY_FILTER_USE_BOTH));
        $checks = self::environment_checks();
        $pass = count(array_filter($checks, function($c) { return $c['ok']; }));
        echo '<div class="wrap"><h1>FreeBooks security, privacy, accessibility & launch hardening</h1>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Launch checklist saved.</p></div>';
        echo '<p><strong>Manual checklist: '.absint($done).' / '.count($items).' marked complete.</strong> Mark an item only after a responsible person has completed and evidenced it.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fb_launch_hardening_save">';
        wp_nonce_field('fb_launch_hardening_save');
        echo '<h2>Production readiness checklist</h2><table class="widefat striped"><thead><tr><th style="width:35px">Done</th><th>Control</th><th>Acceptance evidence required</th></tr></thead><tbody>';
        foreach ($items as $key=>$item) {
            echo '<tr><td><input type="checkbox" name="fb_launch_check['.esc_attr($key).']" value="1" '.checked(!empty($saved[$key]),true,false).'></td><td><strong>'.esc_html($item[0]).'</strong></td><td>'.esc_html($item[1]).'</td></tr>';
        }
        echo '</tbody></table><p class="description">This checklist records your team completion declarations. It does not automatically configure MFA, backups, retention, payment gateways, shipping or accessibility fixes.</p>';
        submit_button('Save launch checklist');
        echo '</form><h2>Automated environment checks</h2><p><strong>'.absint($pass).' / '.count($checks).' checks passed.</strong> These are basic configuration checks, not a penetration test or full compliance audit.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        foreach ($checks as $c) echo '<tr><td><strong>'.esc_html($c['name']).'</strong></td><td>'.($c['ok']?'<span style="color:#176b55;font-weight:700">PASS</span>':'<span style="color:#9a5c30;font-weight:700">REVIEW</span>').'</td><td>'.esc_html($c['detail']).'</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Existing FreeBooks controls</h2><ul><li><a href="'.esc_url(admin_url('edit.php?post_type='.FREEBOOKS_DONATION_CPT.'&page=freebooks-security')).'">Security controls</a>: login/form rate limits, security headers and optional XML-RPC disablement.</li><li><a href="'.esc_url(admin_url('edit.php?post_type='.FREEBOOKS_DONATION_CPT.'&page=freebooks-privacy')).'">Privacy & data rights</a>: WordPress personal-data export and erasure integration.</li><li><a href="'.esc_url(admin_url('site-health.php')).'">WordPress Site Health</a>: server, database and extension diagnostics.</li><li><a href="'.esc_url(admin_url('edit.php?post_type='.FREEBOOKS_DONATION_CPT.'&page=freebooks-seo-monitor')).'">SEO & monitoring</a>: frontend ownership, CMS noindex and performance targets.</li></ul>';
        echo '<p><strong>Important:</strong> FreeBooks does not claim legal compliance or accessibility certification. The Foundation must approve its privacy notice, retention schedule, consent wording and operational procedures. Have the public Next.js frontend independently reviewed against WCAG 2.1 AA before launch.</p></div>';
    }
}
