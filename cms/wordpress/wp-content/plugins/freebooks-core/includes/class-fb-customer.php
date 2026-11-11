<?php
if (!defined('ABSPATH')) exit;

/** Customer account and payment/order experience. WooCommerce remains authoritative. */
class FB_Customer {
    public static function init() {
        add_shortcode('freebooks_account_dashboard', [self::class, 'account_dashboard']);
        add_shortcode('freebooks_order_lookup', [self::class, 'order_lookup']);
        add_action('admin_menu', [self::class, 'payments_admin_page'], 30);
        add_action('woocommerce_thankyou', [self::class, 'thankyou_note'], 20);
        add_action('woocommerce_email_after_order_table', [self::class, 'email_order_note'], 20, 4);
        add_action('add_meta_boxes', [self::class, 'add_order_metabox']);
    }

    public static function account_dashboard() {
        if (!function_exists('wc_get_orders')) return '<div class="fb-core-panel"><p>Customer accounts will be available when WooCommerce is active.</p></div>';
        if (!is_user_logged_in()) {
            $url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(get_permalink());
            return '<div class="fb-core-panel"><h2>My FreeBooks account</h2><p>Sign in to view your orders, addresses and account details.</p><p><a class="fb-core-button" href="' . esc_url($url) . '">Sign in / Create account</a></p></div>';
        }
        $user = wp_get_current_user();
        $orders = wc_get_orders(['customer_id'=>$user->ID,'limit'=>5,'orderby'=>'date','order'=>'DESC','return'=>'objects']);
        $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/');
        ob_start();
        echo '<section class="fb-core-customer-dashboard"><div class="fb-core-customer-welcome"><h2>' . esc_html(sprintf('Welcome, %s', $user->display_name ?: $user->user_login)) . '</h2><p>Manage your FreeBooks orders and account details.</p></div><div class="fb-core-portal-grid">';
        echo '<section class="fb-core-portal-panel"><h3>Recent orders</h3>';
        if ($orders) {
            echo '<div class="fb-core-order-list">';
            foreach ($orders as $order) {
                if (!$order instanceof WC_Order) continue;
                echo '<article class="fb-core-order-row"><div><strong>Order #' . esc_html($order->get_order_number()) . '</strong><br><span>' . esc_html($order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '') . '</span></div><div><strong>' . esc_html(wc_get_order_status_name($order->get_status())) . '</strong><br>' . wp_kses_post($order->get_formatted_order_total()) . '</div><a href="' . esc_url($order->get_view_order_url()) . '">View order</a></article>';
            }
            echo '</div>';
        } else {
            echo '<p>You have not placed an order yet.</p><p><a href="' . esc_url(wc_get_page_permalink('shop')) . '">Browse books</a></p>';
        }
        echo '</section><section class="fb-core-portal-panel"><h3>Account shortcuts</h3><ul class="fb-core-account-links">';
        echo '<li><a href="' . esc_url($account_url) . '">Orders and account dashboard</a></li><li><a href="' . esc_url(wc_get_endpoint_url('edit-address', '', $account_url)) . '">Manage addresses</a></li><li><a href="' . esc_url(wc_get_endpoint_url('edit-account', '', $account_url)) . '">Update account details</a></li><li><a href="' . esc_url(wc_get_endpoint_url('customer-logout', '', $account_url)) . '">Sign out</a></li></ul></section></div>';
        if (shortcode_exists('freebooks_order_support')) echo '<section class="fb-core-portal-panel"><h3>Need help with an order?</h3>' . do_shortcode('[freebooks_order_support]') . '</section>';
        echo '</section>';
        return ob_get_clean();
    }

    public static function order_lookup() {
        if (!function_exists('wc_get_order')) return '<p>Order lookup is available when WooCommerce is active.</p>';
        $result = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fb_customer_lookup_submit'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['fb_customer_lookup_nonce'] ?? ''));
            if (!wp_verify_nonce($nonce, 'fb_customer_order_lookup')) $result = '<p class="fb-core-error">Your session expired. Please refresh and try again.</p>';
            else {
                $number = sanitize_text_field(wp_unslash($_POST['order_number'] ?? ''));
                $email = sanitize_email(wp_unslash($_POST['billing_email'] ?? ''));
                $order = self::find_order($number, $email);
                $result = $order ? self::render_order_summary($order) : '<p class="fb-core-error">We could not match that order number and billing email. Please check the details.</p>';
            }
        }
        ob_start();
        echo '<section class="fb-core-order-lookup"><h2>Track your order status</h2><p>Enter your order number and the billing email used at checkout.</p><form class="fb-core-form" method="post">';
        wp_nonce_field('fb_customer_order_lookup', 'fb_customer_lookup_nonce');
        echo '<label>Order number<input type="text" name="order_number" required maxlength="40" autocomplete="off"></label><label>Billing email<input type="email" name="billing_email" required maxlength="190" autocomplete="email"></label><button type="submit" name="fb_customer_lookup_submit" value="1">Find order</button></form>' . $result;
        echo '<p class="fb-core-muted">For parcel location and delivery updates, use the tracking link supplied by the shipping carrier or your customer account.</p></section>';
        return ob_get_clean();
    }

    private static function find_order($number, $email) {
        $number = trim((string)$number); $email = strtolower(trim((string)$email));
        if ($number === '' || !is_email($email)) return false;
        $order = ctype_digit($number) ? wc_get_order(absint($number)) : false;
        if (!$order) {
            $orders = wc_get_orders(['limit'=>100,'orderby'=>'date','order'=>'DESC','billing_email'=>$email,'return'=>'objects']);
            foreach ($orders as $candidate) if ((string)$candidate->get_order_number() === $number) { $order = $candidate; break; }
        }
        if (!$order) return false;
        $stored = strtolower(trim((string)$order->get_billing_email()));
        return $stored && hash_equals($stored, $email) ? $order : false;
    }

    private static function render_order_summary($order) {
        ob_start();
        echo '<div class="fb-core-order-result"><h3>Order #' . esc_html($order->get_order_number()) . '</h3><p><strong>Status:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p><p><strong>Order date:</strong> ' . esc_html($order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '') . '</p><p><strong>Total:</strong> ' . wp_kses_post($order->get_formatted_order_total()) . '</p><h4>Items</h4><ul>';
        foreach ($order->get_items() as $item) echo '<li>' . esc_html($item->get_name()) . ' × ' . esc_html((string)$item->get_quantity()) . '</li>';
        echo '</ul><p>For delivery tracking, please use the shipping carrier tracking link sent with your order or sign in to your account.</p></div>';
        return ob_get_clean();
    }

    public static function thankyou_note($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_fb_customer_note_shown')) return;
        echo '<section class="fb-core-thankyou-note"><h3>Thank you for supporting FreeBooks</h3><p>Your order helps make learning resources more accessible. Necessary shipping and operating costs may be met from sales proceeds.</p>';
        if ($order->get_customer_id() && function_exists('wc_get_page_permalink')) echo '<p><a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">View your account and order</a></p>';
        echo '</section>';
        $order->update_meta_data('_fb_customer_note_shown', 'yes'); $order->save();
    }

    public static function email_order_note($order, $sent_to_admin, $plain_text, $email) {
        if ($sent_to_admin || !$order instanceof WC_Order) return;
        $message = 'Thank you for choosing FreeBooks. Your purchase supports access to learning resources. Shipping and operating costs may be met from sales proceeds.';
        if ($plain_text) echo "\n\n" . $message . "\n"; else echo '<p class="fb-core-email-note">' . esc_html($message) . '</p>';
    }

    public static function add_order_metabox() {
        add_meta_box('fb_order_summary', 'FreeBooks order information', [self::class, 'order_metabox'], 'shop_order', 'side', 'default');
        add_meta_box('fb_order_summary_hpos', 'FreeBooks order information', [self::class, 'order_metabox'], 'woocommerce_page_wc-orders', 'side', 'default');
    }

    public static function order_metabox($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        if (!$order) { echo '<p>Order details unavailable.</p>'; return; }
        echo '<p><strong>Payment method:</strong> ' . esc_html($order->get_payment_method_title() ?: 'Not selected') . '</p><p><strong>Order status:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p><p><strong>Transaction ID:</strong> ' . esc_html($order->get_transaction_id() ?: 'Not supplied by gateway') . '</p><p><strong>Customer:</strong> ' . esc_html($order->get_billing_email()) . '</p><p><em>Payment confirmation and order status are managed by WooCommerce and the selected payment gateway.</em></p>';
    }

    public static function payments_admin_page() {
        add_submenu_page('woocommerce', 'FreeBooks payment readiness', 'FreeBooks payments', 'manage_woocommerce', 'freebooks-payment-readiness', [self::class, 'payments_page']);
    }

    public static function payments_page() {
        if (!current_user_can('manage_woocommerce')) return;
        echo '<div class="wrap"><h1>FreeBooks payment readiness</h1>';
        if (!class_exists('WooCommerce')) { echo '<div class="notice notice-warning"><p>WooCommerce is not active. Configure WooCommerce before enabling checkout.</p></div></div>'; return; }
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        echo '<p><strong>Selected setup:</strong> Razorpay primary + Cashfree backup. Both providers should be installed and configured as separate WooCommerce payment methods. Customers can then select an available method at checkout. FreeBooks Core does not process payments or store payment credentials.</p>';
        $provider_rows = [
            'razorpay' => ['label' => 'Razorpay', 'match' => ['razorpay']],
            'cashfree' => ['label' => 'Cashfree', 'match' => ['cashfree']],
        ];
        echo '<h2>Required payment providers</h2><table class="widefat striped" style="max-width:1000px"><thead><tr><th>Provider</th><th>Plugin detection</th><th>Checkout status</th><th>Gateway ID</th><th>Settings</th></tr></thead><tbody>';
        $found = ['razorpay' => false, 'cashfree' => false];
        foreach ($gateways as $gateway) {
            $haystack = strtolower((string)$gateway->id . ' ' . (string)$gateway->get_title() . ' ' . get_class($gateway));
            foreach ($provider_rows as $key => $provider) {
                foreach ($provider['match'] as $needle) {
                    if (strpos($haystack, $needle) !== false) {
                        $found[$key] = true;
                        $enabled = isset($gateway->enabled) && $gateway->enabled === 'yes';
                        $settings_url = method_exists($gateway, 'get_settings_url') ? $gateway->get_settings_url() : admin_url('admin.php?page=wc-settings&tab=checkout&section=' . rawurlencode($gateway->id));
                        echo '<tr><td><strong>' . esc_html($provider['label']) . '</strong></td><td style="color:green">Detected</td><td>' . ($enabled ? '<span style="color:green;font-weight:700">Enabled</span>' : '<span style="color:#9a5c30">Disabled</span>') . '</td><td><code>' . esc_html($gateway->id) . '</code></td><td><a class="button" href="' . esc_url($settings_url) . '">Configure</a></td></tr>';
                    }
                }
            }
        }
        foreach ($provider_rows as $key => $provider) {
            if (!$found[$key]) {
                echo '<tr><td><strong>' . esc_html($provider['label']) . '</strong></td><td style="color:#9a5c30">Not detected</td><td>Unavailable</td><td>—</td><td>Install and activate the official WooCommerce gateway plugin.</td></tr>';
            }
        }
        echo '</tbody></table>';
        echo '<h2>All registered WooCommerce gateways</h2><table class="widefat striped" style="max-width:1000px"><thead><tr><th>Payment method</th><th>Status</th><th>Gateway ID</th></tr></thead><tbody>';
        if (!$gateways) echo '<tr><td colspan="3">No payment gateways are registered.</td></tr>';
        foreach ($gateways as $gateway) {
            $enabled = isset($gateway->enabled) && $gateway->enabled === 'yes';
            echo '<tr><td>' . esc_html($gateway->get_title()) . '</td><td>' . ($enabled ? '<span style="color:green">Enabled</span>' : 'Disabled') . '</td><td><code>' . esc_html($gateway->id) . '</code></td></tr>';
        }
        echo '</tbody></table><h2>Configuration sequence</h2><ol><li>Install and activate the official Razorpay for WooCommerce plugin and the official Cashfree WooCommerce payment plugin from their verified publisher sources.</li><li>Open each provider’s Configure link above. Add that provider’s test credentials first; do not paste credentials into FreeBooks Core.</li><li>Enable both payment methods in WooCommerce so customers can choose either at checkout. Keep provider test mode enabled until final acceptance testing.</li><li>Configure each provider’s webhook URL and signing secret exactly as documented by its own plugin/dashboard. Do not reuse webhook secrets between providers.</li><li>Confirm both methods appear on checkout, then test successful, failed, cancelled and duplicate webhook events during the final test cycle.</li><li>Before launch, replace test credentials with live credentials securely and verify settlement and refund procedures with both providers.</li></ol><p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '">Open WooCommerce payment settings</a></p><p><strong>Important:</strong> Plugin detection and enabled status do not prove credentials, webhook signatures, bank settlement or refunds are working. Complete final end-to-end tests before accepting live payments.</p></div>';
    }
}
