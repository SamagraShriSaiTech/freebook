<?php
if (!defined('ABSPATH')) exit;
/** Public, non-sensitive checkout metadata for the headless FreeBooks storefront. */
class FB_Checkout {
    public static function init() {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes() {
        register_rest_route('freebooks/v1', '/checkout/payment-methods', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'payment_methods'],
            'permission_callback' => '__return_true'
        ]);
    }

    public static function payment_methods() {
        if (!function_exists('WC') || !WC()) {
            return new WP_Error('fb_woocommerce_unavailable', 'WooCommerce is not active.', ['status' => 503]);
        }
        $gateways = WC()->payment_gateways();
        if (!$gateways) return rest_ensure_response(['methods' => []]);
        $available = $gateways->get_available_payment_gateways();
        $methods = [];
        foreach ((array)$available as $id => $gateway) {
            if (!$gateway || empty($gateway->enabled) || !is_callable([$gateway, 'is_available']) || !$gateway->is_available()) continue;
            $methods[] = [
                'id' => sanitize_key($id),
                'title' => sanitize_text_field(wp_strip_all_tags((string)$gateway->get_title())),
                'description' => sanitize_text_field(wp_strip_all_tags((string)$gateway->get_description()))
            ];
        }
        return rest_ensure_response(['methods' => $methods]);
    }
}
