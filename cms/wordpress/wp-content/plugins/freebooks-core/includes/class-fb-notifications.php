<?php
if (!defined('ABSPATH')) exit;

/** Customer notification preferences and a privacy-minimised mail hand-off log. */
class FB_Notifications {
    const LOG_OPTION = 'freebooks_notification_log';
    const META_ORDER_UPDATES = '_fb_notify_order_updates';
    const META_DONATION_UPDATES = '_fb_notify_donation_updates';
    const META_COMMUNITY_UPDATES = '_fb_notify_community_updates';

    public static function init() {
        add_shortcode('freebooks_notification_preferences', [self::class, 'preferences_shortcode']);
        add_action('admin_post_fb_save_notification_preferences', [self::class, 'save_preferences']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('wp_mail_succeeded', [self::class, 'mail_succeeded']);
        add_action('wp_mail_failed', [self::class, 'mail_failed']);
        add_action('admin_menu', [self::class, 'admin_menu'], 70);
        add_filter('woocommerce_email_from_name', [self::class, 'email_from_name'], 20);
        add_filter('woocommerce_email_footer_text', [self::class, 'email_footer'], 20);
    }

    public static function defaults() {
        return ['order_updates'=>1, 'donation_updates'=>1, 'community_updates'=>0];
    }

    public static function preferences($user_id) {
        $d = self::defaults();
        return [
            'order_updates' => get_user_meta($user_id, self::META_ORDER_UPDATES, true) === '' ? $d['order_updates'] : (int)get_user_meta($user_id, self::META_ORDER_UPDATES, true),
            'donation_updates' => get_user_meta($user_id, self::META_DONATION_UPDATES, true) === '' ? $d['donation_updates'] : (int)get_user_meta($user_id, self::META_DONATION_UPDATES, true),
            'community_updates' => (int)get_user_meta($user_id, self::META_COMMUNITY_UPDATES, true),
        ];
    }

    public static function preferences_shortcode() {
        if (!is_user_logged_in()) {
            $url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(get_permalink());
            return '<section class="fb-core-panel"><h2>Notification preferences</h2><p>Sign in to manage optional FreeBooks email updates.</p><a class="fb-core-button" href="'.esc_url($url).'">Sign in</a></section>';
        }
        $p = self::preferences(get_current_user_id());
        ob_start(); ?>
        <section class="fb-core-panel fb-notification-preferences"><h2>Email preferences</h2>
        <?php if (isset($_GET['fb_notifications_saved'])): ?><p class="fb-core-success">Your preferences have been saved.</p><?php endif; ?>
        <p>Choose which additional FreeBooks email updates you would like to receive. Essential account, payment, security and legally required service messages may still be sent.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="fb_save_notification_preferences"><?php wp_nonce_field('fb_save_notification_preferences'); ?>
            <p><label><input type="checkbox" name="order_updates" value="1" <?php checked($p['order_updates'],1); ?>> Additional order and shipment status emails</label></p>
            <p><label><input type="checkbox" name="donation_updates" value="1" <?php checked($p['donation_updates'],1); ?>> Donation status emails</label></p>
            <p><label><input type="checkbox" name="community_updates" value="1" <?php checked($p['community_updates'],1); ?>> Optional community and programme updates</label></p>
            <p><button type="submit" class="fb-core-button">Save preferences</button></p>
        </form><p class="fb-core-muted">Community updates preference does not subscribe you to the newsletter. Use the newsletter's own subscribe/unsubscribe controls for that service.</p></section>
        <?php return ob_get_clean();
    }

    public static function save_preferences() {
        if (!is_user_logged_in()) wp_die('Please sign in.', 403);
        check_admin_referer('fb_save_notification_preferences');
        $uid = get_current_user_id();
        update_user_meta($uid, self::META_ORDER_UPDATES, empty($_POST['order_updates']) ? 0 : 1);
        update_user_meta($uid, self::META_DONATION_UPDATES, empty($_POST['donation_updates']) ? 0 : 1);
        update_user_meta($uid, self::META_COMMUNITY_UPDATES, empty($_POST['community_updates']) ? 0 : 1);
        wp_safe_redirect(add_query_arg('fb_notifications_saved','1',wp_get_referer() ?: home_url('/')));
        exit;
    }

    public static function register_routes() {
        register_rest_route('freebooks/v1', '/customer/notification-preferences', [
            ['methods'=>'GET','callback'=>[self::class,'rest_get_preferences'],'permission_callback'=>function(){return is_user_logged_in();}],
            ['methods'=>'POST','callback'=>[self::class,'rest_update_preferences'],'permission_callback'=>function(){return is_user_logged_in();}],
        ]);
    }

    public static function rest_get_preferences() { return rest_ensure_response(self::preferences(get_current_user_id())); }

    public static function rest_update_preferences($request) {
        $data = $request->get_json_params();
        if (!is_array($data)) return new WP_Error('invalid_preferences','A JSON object is required.',['status'=>400]);
        $uid = get_current_user_id();
        foreach (['order_updates'=>self::META_ORDER_UPDATES,'donation_updates'=>self::META_DONATION_UPDATES,'community_updates'=>self::META_COMMUNITY_UPDATES] as $key=>$meta) {
            if (array_key_exists($key,$data)) update_user_meta($uid,$meta,empty($data[$key])?0:1);
        }
        return rest_ensure_response(self::preferences($uid));
    }

    public static function mail_succeeded($mail) { self::record_mail($mail, 'handed_off'); }
    public static function mail_failed($error) {
        $data = is_wp_error($error) ? $error->get_error_data() : [];
        self::record_mail(is_array($data)?$data:[], 'failed');
    }
    private static function record_mail($mail, $result) {
        $to = $mail['to'] ?? [];
        if (!is_array($to)) $to = preg_split('/[,;]+/', (string)$to);
        $hashes = [];
        foreach ($to as $address) { $address = strtolower(trim((string)$address)); if (is_email($address)) $hashes[] = hash('sha256',$address); }
        $log = get_option(self::LOG_OPTION, []); if (!is_array($log)) $log=[];
        $log[] = ['at'=>current_time('mysql'),'result'=>$result,'subject'=>sanitize_text_field($mail['subject']??''),'recipients'=>count($hashes),'recipient_fingerprints'=>array_slice($hashes,0,10)];
        update_option(self::LOG_OPTION, array_slice($log,-150), false);
    }

    public static function email_from_name($name) { return 'FreeBooks — SamagraShriSai Foundation'; }
    public static function email_footer($text) { return 'FreeBooks | SamagraShriSai Foundation. Sales proceeds support students in need; necessary shipping and operating costs may be met from proceeds.'; }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT,'Notification centre','Notification centre','manage_options','freebooks-notifications',[self::class,'admin_page']);
    }
    public static function admin_page() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.',403);
        $log = array_reverse((array)get_option(self::LOG_OPTION,[]));
        echo '<div class="wrap"><h1>FreeBooks notification centre</h1><p>Recent WordPress email hand-offs. “Handed off” means WordPress accepted the message for sending; it does not confirm inbox delivery. Recipient addresses and message bodies are not stored in this log.</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('edit.php?post_type='.FREEBOOKS_DONATION_CPT.'&page=fb-donor-communications')).'">Open donor email history and resend controls</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Result</th><th>Subject</th><th>Recipients</th></tr></thead><tbody>';
        if (!$log) echo '<tr><td colspan="4">No email events recorded yet.</td></tr>';
        foreach (array_slice($log,0,100) as $row) echo '<tr><td>'.esc_html($row['at']??'').'</td><td>'.esc_html($row['result']??'').'</td><td>'.esc_html($row['subject']??'').'</td><td>'.absint($row['recipients']??0).'</td></tr>';
        echo '</tbody></table><p>For delivery diagnostics, configure a reputable SMTP or transactional email provider and verify SPF, DKIM and DMARC for the sending domain. This log does not replace provider delivery/bounce reports.</p></div>';
    }
}
