<?php
if (!defined('ABSPATH')) exit;

/**
 * Configurable security controls for FreeBooks.
 * Uses WordPress authentication, nonces and transients; never stores raw IP addresses.
 */
class FB_Security {
    const OPTION = 'freebooks_security_settings';
    const EVENTS = 'freebooks_security_events';

    public static function defaults() {
        return [
            'login_protection' => 1,
            'max_login_attempts' => 5,
            'login_lockout_minutes' => 15,
            'public_form_protection' => 1,
            'public_form_limit' => 5,
            'public_form_window_minutes' => 60,
            'security_headers' => 1,
            'disable_xmlrpc' => 0,
        ];
    }

    public static function settings() {
        return wp_parse_args((array)get_option(self::OPTION, []), self::defaults());
    }

    public static function init() {
        add_filter('authenticate', [self::class, 'check_login_lockout'], 5, 3);
        add_action('wp_login_failed', [self::class, 'record_login_failure'], 10, 2);
        add_action('wp_login', [self::class, 'clear_login_failures'], 10, 2);
        add_action('admin_menu', [self::class, 'admin_menu'], 70);
        add_action('admin_post_fb_security_save', [self::class, 'save_settings']);
        add_action('admin_post_nopriv_fbem_subscribe', [self::class, 'guard_newsletter'], 0);
        add_action('admin_post_fbem_subscribe', [self::class, 'guard_newsletter'], 0);
        add_action('admin_post_nopriv_fbcm_apply', [self::class, 'guard_volunteer'], 0);
        add_action('admin_post_fbcm_apply', [self::class, 'guard_volunteer'], 0);
        add_filter('xmlrpc_enabled', [self::class, 'xmlrpc_enabled']);
        add_action('send_headers', [self::class, 'send_security_headers']);
        add_filter('the_generator', '__return_empty_string');
    }

    private static function client_ip_hash() {
        // REMOTE_ADDR is deliberately used; do not trust spoofable X-Forwarded-For headers.
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    private static function username_hash($username) {
        return hash_hmac('sha256', strtolower(trim((string)$username)), wp_salt('auth'));
    }

    private static function login_keys($username) {
        $ip = self::client_ip_hash();
        $user = self::username_hash($username);
        return ['fb_login_ip_' . $ip, 'fb_login_user_' . $user];
    }

    public static function check_login_lockout($user, $username, $password) {
        $s = self::settings();
        if (empty($s['login_protection']) || $username === '') return $user;
        $keys = self::login_keys($username);
        foreach ($keys as $key) {
            if ((int)get_transient($key) >= (int)$s['max_login_attempts']) {
                self::event('login_blocked');
                return new WP_Error('fb_login_locked', sprintf(
                    'Too many failed sign-in attempts. Please try again in %d minutes.',
                    absint($s['login_lockout_minutes'])
                ));
            }
        }
        return $user;
    }

    public static function record_login_failure($username, $error = null) {
        $s = self::settings();
        if (empty($s['login_protection'])) return;
        $minutes = max(1, min(1440, absint($s['login_lockout_minutes'])));
        foreach (self::login_keys($username) as $key) {
            $count = (int)get_transient($key);
            set_transient($key, $count + 1, $minutes * MINUTE_IN_SECONDS);
        }
        self::event('login_failed');
    }

    public static function clear_login_failures($user_login, $user) {
        foreach (self::login_keys($user_login) as $key) delete_transient($key);
        self::event('login_success');
    }

    private static function public_form_guard($form) {
        $s = self::settings();
        if (empty($s['public_form_protection'])) return;
        $ip = self::client_ip_hash();
        $key = 'fb_public_' . sanitize_key($form) . '_' . $ip;
        $limit = max(1, min(100, absint($s['public_form_limit'])));
        $window = max(1, min(1440, absint($s['public_form_window_minutes'])));
        $count = (int)get_transient($key);
        if ($count >= $limit) {
            self::event('public_form_limited');
            wp_die('Too many submissions. Please wait and try again later.', 'FreeBooks', ['response' => 429]);
        }
        set_transient($key, $count + 1, $window * MINUTE_IN_SECONDS);
    }

    public static function guard_newsletter() { self::public_form_guard('newsletter'); }
    public static function guard_volunteer() { self::public_form_guard('volunteer'); }

    public static function xmlrpc_enabled($enabled) {
        $s = self::settings();
        return !empty($s['disable_xmlrpc']) ? false : $enabled;
    }

    public static function send_security_headers() {
        $s = self::settings();
        if (empty($s['security_headers']) || headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('X-Frame-Options: SAMEORIGIN');
        if (is_ssl()) header('Strict-Transport-Security: max-age=15552000');
        header('X-Permitted-Cross-Domain-Policies: none');
    }

    private static function event($event) {
        $rows = get_option(self::EVENTS, []);
        if (!is_array($rows)) $rows = [];
        $rows[] = [
            'time' => current_time('mysql', true),
            'event' => sanitize_key($event),
            'ip_hash' => substr(self::client_ip_hash(), 0, 12),
        ];
        if (count($rows) > 200) $rows = array_slice($rows, -200);
        update_option(self::EVENTS, $rows, false);
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . FREEBOOKS_DONATION_CPT,
            'Security controls',
            'Security controls',
            'manage_options',
            'freebooks-security',
            [self::class, 'settings_page']
        );
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.', 403);
        check_admin_referer('fb_security_save');
        $input = isset($_POST['fb_security']) ? (array)wp_unslash($_POST['fb_security']) : [];
        $defaults = self::defaults();
        $s = [
            'login_protection' => empty($input['login_protection']) ? 0 : 1,
            'max_login_attempts' => max(3, min(20, absint($input['max_login_attempts'] ?? $defaults['max_login_attempts']))),
            'login_lockout_minutes' => max(5, min(1440, absint($input['login_lockout_minutes'] ?? $defaults['login_lockout_minutes']))),
            'public_form_protection' => empty($input['public_form_protection']) ? 0 : 1,
            'public_form_limit' => max(2, min(50, absint($input['public_form_limit'] ?? $defaults['public_form_limit']))),
            'public_form_window_minutes' => max(5, min(1440, absint($input['public_form_window_minutes'] ?? $defaults['public_form_window_minutes']))),
            'security_headers' => empty($input['security_headers']) ? 0 : 1,
            'disable_xmlrpc' => empty($input['disable_xmlrpc']) ? 0 : 1,
        ];
        update_option(self::OPTION, $s, false);
        if (class_exists('FB_Governance')) FB_Governance::log('security_settings_updated', 0, 'FreeBooks security settings changed.');
        wp_safe_redirect(add_query_arg(['page'=>'freebooks-security','updated'=>'1'], admin_url('edit.php?post_type=' . FREEBOOKS_DONATION_CPT)));
        exit;
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        $events = array_reverse((array)get_option(self::EVENTS, []));
        echo '<div class="wrap"><h1>FreeBooks security controls</h1>';
        if (isset($_GET['updated'])) echo '<div class="notice notice-success is-dismissible"><p>Security settings saved.</p></div>';
        echo '<p>These controls add basic application-level protections. Keep WordPress, themes and plugins updated, use HTTPS, a WAF and off-site backups. Do not treat this screen as a substitute for hosting-level security.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="fb_security_save">';
        wp_nonce_field('fb_security_save');
        echo '<h2>Login protection</h2><table class="form-table"><tr><th>Enable login rate limiting</th><td><label><input type="checkbox" name="fb_security[login_protection]" value="1" ' . checked($s['login_protection'], 1, false) . '> Block repeated failed login attempts by IP and username</label></td></tr>';
        echo '<tr><th>Maximum failed attempts</th><td><input type="number" min="3" max="20" name="fb_security[max_login_attempts]" value="' . esc_attr($s['max_login_attempts']) . '"></td></tr>';
        echo '<tr><th>Lockout window (minutes)</th><td><input type="number" min="5" max="1440" name="fb_security[login_lockout_minutes]" value="' . esc_attr($s['login_lockout_minutes']) . '"></td></tr></table>';
        echo '<h2>Public form protection</h2><table class="form-table"><tr><th>Enable public form rate limiting</th><td><label><input type="checkbox" name="fb_security[public_form_protection]" value="1" ' . checked($s['public_form_protection'], 1, false) . '> Protect newsletter and volunteer forms</label></td></tr>';
        echo '<tr><th>Submissions per IP</th><td><input type="number" min="2" max="50" name="fb_security[public_form_limit]" value="' . esc_attr($s['public_form_limit']) . '"></td></tr>';
        echo '<tr><th>Rate-limit window (minutes)</th><td><input type="number" min="5" max="1440" name="fb_security[public_form_window_minutes]" value="' . esc_attr($s['public_form_window_minutes']) . '"></td></tr></table>';
        echo '<h2>Browser and legacy endpoint protection</h2><table class="form-table"><tr><th>Security response headers</th><td><label><input type="checkbox" name="fb_security[security_headers]" value="1" ' . checked($s['security_headers'], 1, false) . '> Add nosniff, referrer, permissions and frame headers</label></td></tr>';
        echo '<tr><th>Disable XML-RPC</th><td><label><input type="checkbox" name="fb_security[disable_xmlrpc]" value="1" ' . checked($s['disable_xmlrpc'], 1, false) . '> Disable XML-RPC authentication and requests</label><p class="description">Enable only if Jetpack, mobile apps or other integrations do not depend on XML-RPC.</p></td></tr></table>';
        submit_button('Save security settings');
        echo '</form><h2>Recent security events</h2><p>Latest 200 events. Only a shortened keyed IP hash is retained; raw IP addresses are not stored by this log.</p>';
        echo '<table class="widefat striped" style="max-width:800px"><thead><tr><th>Time (UTC)</th><th>Event</th><th>IP fingerprint</th></tr></thead><tbody>';
        if (!$events) echo '<tr><td colspan="3">No security events recorded yet.</td></tr>';
        foreach (array_slice($events, 0, 50) as $event) {
            echo '<tr><td>' . esc_html($event['time'] ?? '') . '</td><td>' . esc_html($event['event'] ?? '') . '</td><td><code>' . esc_html($event['ip_hash'] ?? '') . '</code></td></tr>';
        }
        echo '</tbody></table><h2>Additional production hardening</h2><ul><li>Use HTTPS on both public and CMS domains.</li><li>Keep administrator accounts protected by strong unique passwords and MFA.</li><li>Use hosting WAF/CDN protection and keep PHP, WordPress and extensions updated.</li><li>Disable dashboard file editing in wp-config.php using <code>define(\'DISALLOW_FILE_EDIT\', true);</code>.</li><li>Restrict database access and keep tested off-site backups.</li></ul></div>';
    }
}
