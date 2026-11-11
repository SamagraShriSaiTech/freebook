<?php
if (!defined('ABSPATH')) exit;

/**
 * Operational dashboard, date-scoped analytics and integration readiness checks.
 * Reports use existing WordPress/WooCommerce records; no duplicate data store.
 */
class FB_Analytics_Operations {
    public static function init() {
        add_action('admin_menu', [self::class, 'menu'], 80);
        add_action('admin_post_fb_ops_analytics_export', [self::class, 'export_csv']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function menu() {
        add_submenu_page(
            'edit.php?post_type=' . FREEBOOKS_DONATION_CPT,
            'Operations & analytics',
            'Operations & analytics',
            'manage_options',
            'freebooks-operations-analytics',
            [self::class, 'page']
        );
    }

    private static function date_range() {
        $to = sanitize_text_field(wp_unslash($_GET['to'] ?? current_time('Y-m-d')));
        $from = sanitize_text_field(wp_unslash($_GET['from'] ?? date('Y-m-d', strtotime('-29 days', current_time('timestamp')))));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-29 days', current_time('timestamp')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = current_time('Y-m-d');
        if ($from > $to) { $swap = $from; $from = $to; $to = $swap; }
        return [$from, $to];
    }

    private static function donations($from, $to) {
        return get_posts([
            'post_type' => FREEBOOKS_DONATION_CPT,
            'post_status' => 'private',
            'numberposts' => -1,
            'fields' => 'ids',
            'date_query' => [[
                'after' => $from . ' 00:00:00',
                'before' => $to . ' 23:59:59',
                'inclusive' => true,
            ]],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    public static function summary($from, $to) {
        $ids = self::donations($from, $to);
        $statuses = [];
        $items = 0;
        $daily = [];
        foreach ($ids as $id) {
            $status = sanitize_key(get_post_meta($id, '_fb_status', true) ?: 'submitted');
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            foreach ((array)get_post_meta($id, '_fb_items', true) as $item) {
                $items += max(1, absint($item['quantity'] ?? 1));
            }
            $day = get_post_time('Y-m-d', false, $id);
            $daily[$day] = ($daily[$day] ?? 0) + 1;
        }
        ksort($daily);

        $catalogue = wp_count_posts(FREEBOOKS_ITEM_CPT);
        $published = isset($catalogue->publish) ? (int)$catalogue->publish : 0;
        $draft = isset($catalogue->draft) ? (int)$catalogue->draft : 0;

        $orders = ['available' => function_exists('wc_get_orders'), 'count' => null, 'gross' => null];
        if ($orders['available']) {
            $order_ids = wc_get_orders([
                'limit' => -1,
                'return' => 'ids',
                'date_created' => $from . '...' . $to,
                'status' => array_keys(wc_get_order_statuses()),
            ]);
            $orders['count'] = count($order_ids);
            $total = 0.0;
            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);
                if ($order) $total += (float)$order->get_total();
            }
            $orders['gross_total'] = round($total, 2);
            $orders['currency'] = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        }

        return [
            'from' => $from, 'to' => $to,
            'donations' => count($ids),
            'items_submitted' => $items,
            'statuses' => $statuses,
            'daily' => $daily,
            'catalogue_published' => $published,
            'catalogue_drafts' => $draft,
            'orders' => $orders,
        ];
    }

    public static function integrations() {
        $checks = [];
        $checks[] = [
            'name' => 'WooCommerce',
            'ok' => class_exists('WooCommerce'),
            'detail' => class_exists('WooCommerce') ? 'Plugin is active.' : 'Not detected; shop checkout features require WooCommerce.',
        ];
        $settings = (array)get_option('freebooks_settings', []);
        $checks[] = [
            'name' => 'Contact details',
            'ok' => !empty($settings['contact_email']) || !empty($settings['contact_phone']),
            'detail' => (!empty($settings['contact_email']) || !empty($settings['contact_phone'])) ? 'At least one public contact method is configured.' : 'Add an official contact email or phone in FreeBooks settings.',
        ];
        $checks[] = [
            'name' => 'Public frontend URL',
            'ok' => (bool)wp_http_validate_url((string)get_option('freebooks_next_url', '')),
            'detail' => get_option('freebooks_next_url', '') ? 'A frontend URL is configured; reachability is not tested here.' : 'Configure the Next.js URL in FreeBooks settings.',
        ];
        $checks[] = [
            'name' => 'Frontend revalidation',
            'ok' => (bool)get_option('freebooks_revalidate_secret', ''),
            'detail' => get_option('freebooks_revalidate_secret', '') ? 'A secret is configured; live revalidation is not tested here.' : 'Optional: configure the revalidation secret after setting up the frontend route.',
        ];
        $checks[] = [
            'name' => 'Email transport',
            'ok' => (bool)has_action('phpmailer_init'),
            'detail' => has_action('phpmailer_init') ? 'A PHPMailer hook is registered; successful delivery still requires provider testing.' : 'No SMTP transport hook detected. Configure and test SMTP/transactional email.',
        ];
        $checks[] = [
            'name' => 'Scheduled tasks',
            'ok' => function_exists('wp_next_scheduled') && (bool)wp_next_scheduled('fb_launch_monitor_weekly'),
            'detail' => wp_next_scheduled('fb_launch_monitor_weekly') ? 'FreeBooks weekly monitor event is scheduled.' : 'Weekly monitor event not found; check WP-Cron configuration.',
        ];
        $checks[] = [
            'name' => 'Payment gateway',
            'ok' => class_exists('WooCommerce') && function_exists('WC') && !empty(WC()->payment_gateways()),
            'detail' => class_exists('WooCommerce') ? 'WooCommerce is active. Verify gateway enablement and test-mode/live credentials in WooCommerce settings.' : 'Requires WooCommerce; no gateway status can be checked.',
        ];
        return $checks;
    }

    public static function register_routes() {
        register_rest_route('freebooks/v1', '/admin/operations-summary', [
            'methods' => 'GET',
            'callback' => function($request) {
                $from = sanitize_text_field($request->get_param('from') ?: date('Y-m-d', strtotime('-29 days')));
                $to = sanitize_text_field($request->get_param('to') ?: current_time('Y-m-d'));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                    return new WP_Error('invalid_date_range', 'Use YYYY-MM-DD for from and to.', ['status' => 400]);
                }
                return rest_ensure_response(self::summary($from, $to));
            },
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ]);
    }

    public static function page() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.', 403);
        [$from, $to] = self::date_range();
        $data = self::summary($from, $to);
        $checks = self::integrations();
        $passed = count(array_filter($checks, function($c) { return $c['ok']; }));
        echo '<div class="wrap"><h1>FreeBooks operations & analytics</h1>';
        echo '<p>Operational reporting from existing donation, catalogue and WooCommerce records. These figures are not monetary donations or verified student-impact claims.</p>';
        echo '<form method="get"><input type="hidden" name="post_type" value="'.esc_attr(FREEBOOKS_DONATION_CPT).'"><input type="hidden" name="page" value="freebooks-operations-analytics">';
        echo '<label>From <input type="date" name="from" value="'.esc_attr($from).'"></label> &nbsp; <label>To <input type="date" name="to" value="'.esc_attr($to).'"></label> ';
        submit_button('Apply range','secondary','submit',false);
        echo '</form>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:18px 0">';
        $cards = [
            'Donations submitted' => number_format_i18n($data['donations']),
            'Items in submissions' => number_format_i18n($data['items_submitted']),
            'Published catalogue items' => number_format_i18n($data['catalogue_published']),
            'Draft catalogue items' => number_format_i18n($data['catalogue_drafts']),
        ];
        foreach ($cards as $label=>$value) echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px"><div style="color:#646970">'.esc_html($label).'</div><div style="font-size:26px;font-weight:700;color:#176b55">'.esc_html($value).'</div></div>';
        echo '</div>';
        echo '<p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=fb_ops_analytics_export&from='.rawurlencode($from).'&to='.rawurlencode($to)),'fb_ops_analytics_export')).'">Export donation analytics CSV</a></p>';
        echo '<h2>Donation status breakdown</h2><table class="widefat striped"><thead><tr><th>Status</th><th>Records</th></tr></thead><tbody>';
        if (!$data['statuses']) echo '<tr><td colspan="2">No donations in this date range.</td></tr>';
        foreach ($data['statuses'] as $status=>$count) echo '<tr><td>'.esc_html(ucwords(str_replace('_',' ',$status))).'</td><td>'.number_format_i18n($count).'</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Daily donation activity</h2><table class="widefat striped"><thead><tr><th>Date</th><th>Donation records</th></tr></thead><tbody>';
        if (!$data['daily']) echo '<tr><td colspan="2">No activity in this date range.</td></tr>';
        foreach ($data['daily'] as $day=>$count) echo '<tr><td>'.esc_html($day).'</td><td>'.number_format_i18n($count).'</td></tr>';
        echo '</tbody></table>';
        echo '<h2>WooCommerce order snapshot</h2>';
        if ($data['orders']['available']) echo '<p>Orders in range: <strong>'.number_format_i18n($data['orders']['count']).'</strong> &nbsp; Gross order total: <strong>'.wp_kses_post(wc_price($data['orders']['gross_total'])).'</strong></p><p class="description">Gross order totals are WooCommerce order totals and may include taxes/shipping/discounts depending on store configuration; they are not donation revenue or net proceeds.</p>';
        else echo '<p>WooCommerce is not active; order reporting is unavailable.</p>';
        echo '<h2>Integration readiness</h2><p><strong>'.absint($passed).' / '.count($checks).' readiness checks passed.</strong> A pass indicates configuration presence only where stated, not a live end-to-end transaction.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Integration</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        foreach ($checks as $c) echo '<tr><td><strong>'.esc_html($c['name']).'</strong></td><td>'.($c['ok']?'<span style="color:#176b55;font-weight:700">READY / DETECTED</span>':'<span style="color:#996515;font-weight:700">REVIEW</span>').'</td><td>'.esc_html($c['detail']).'</td></tr>';
        echo '</tbody></table>';
        echo '<p>For payment, SMTP, carrier, and Next.js integrations, complete real test transactions and verify the external provider dashboards. This page does not send payments, create shipments or contact customers.</p>';
        echo '</div>';
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.', 403);
        check_admin_referer('fb_ops_analytics_export');
        $from = sanitize_text_field(wp_unslash($_GET['from'] ?? ''));
        $to = sanitize_text_field(wp_unslash($_GET['to'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) wp_die('Invalid date range.', 400);
        $data = self::summary($from, $to);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=freebooks-operations-'.gmdate('Y-m-d').'.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Metric','Value']);
        fputcsv($out, ['From',$from]);
        fputcsv($out, ['To',$to]);
        fputcsv($out, ['Donations submitted',$data['donations']]);
        fputcsv($out, ['Items in submissions',$data['items_submitted']]);
        fputcsv($out, ['Published catalogue items',$data['catalogue_published']]);
        fputcsv($out, ['Draft catalogue items',$data['catalogue_drafts']]);
        foreach ($data['statuses'] as $status=>$count) fputcsv($out, ['Status: '.$status,$count]);
        foreach ($data['daily'] as $day=>$count) fputcsv($out, ['Daily: '.$day,$count]);
        fclose($out);
        exit;
    }
}
