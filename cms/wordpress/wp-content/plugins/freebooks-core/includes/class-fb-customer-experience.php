<?php
if (!defined('ABSPATH')) exit;

/**
 * Enhanced customer order history and authenticated customer REST endpoints.
 * WooCommerce remains authoritative for order/payment/refund state.
 */
class FB_Customer_Experience {
    public static function init() {
        add_shortcode('freebooks_order_history', [self::class, 'order_history_shortcode']);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('template_redirect', [self::class, 'handle_reorder']);
    }


    public static function handle_reorder() {
        if (empty($_GET['fb_reorder']) || !function_exists('WC') || !WC()->cart) return;
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(wc_get_cart_url()));
            exit;
        }
        $ids=array_slice(array_unique(array_filter(array_map('absint',explode(',',sanitize_text_field(wp_unslash($_GET['fb_reorder'])))))),0,30);
        if (!$ids) return;
        foreach($ids as $product_id) {
            $product=wc_get_product($product_id);
            if ($product && $product->is_type('simple') && $product->is_purchasable() && $product->is_in_stock()) WC()->cart->add_to_cart($product_id,1);
        }
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    public static function register_routes() {
        register_rest_route('freebooks/v1', '/customer/orders', [
            'methods'=>'GET',
            'callback'=>[self::class,'rest_orders'],
            'permission_callback'=>[self::class,'is_customer']
        ]);
        register_rest_route('freebooks/v1', '/customer/orders/(?P<id>\d+)', [
            'methods'=>'GET',
            'callback'=>[self::class,'rest_order_detail'],
            'permission_callback'=>[self::class,'can_access_order']
        ]);
    }

    public static function is_customer() {
        return is_user_logged_in();
    }

    public static function can_access_order($request) {
        if (!is_user_logged_in() || !function_exists('wc_get_order')) return false;
        $order=wc_get_order(absint($request['id']));
        if (!$order) return false;
        return (int)$order->get_customer_id() === get_current_user_id();
    }

    private static function order_payload($order, $include_items=false) {
        $created=$order->get_date_created();
        $data=[
            'id'=>$order->get_id(),
            'number'=>(string)$order->get_order_number(),
            'status'=>$order->get_status(),
            'status_label'=>wc_get_order_status_name($order->get_status()),
            'date_created'=>$created ? $created->date(DATE_ATOM) : null,
            'currency'=>$order->get_currency(),
            'total'=>wc_format_decimal($order->get_total(),2),
            'total_formatted'=>wp_strip_all_tags($order->get_formatted_order_total()),
            'payment_method'=>$order->get_payment_method_title(),
            'view_url'=>$order->get_view_order_url(),
            'fulfilment'=>[
                'status'=>(string)$order->get_meta('_fb_fulfilment_status'),
                'status_label'=>FB_Fulfilment::STATUSES[(string)$order->get_meta('_fb_fulfilment_status')] ?? '',
                'carrier'=>(string)$order->get_meta('_fb_fulfilment_carrier'),
                'tracking_number'=>(string)$order->get_meta('_fb_fulfilment_tracking_number'),
                'tracking_url'=>(string)$order->get_meta('_fb_fulfilment_tracking_url'),
                'dispatch_date'=>(string)$order->get_meta('_fb_fulfilment_dispatch_date'),
                'estimated_delivery'=>(string)$order->get_meta('_fb_fulfilment_eta')
            ]
        ];
        if ($include_items) {
            $data['items']=[];
            foreach ($order->get_items() as $item) {
                $product=$item->get_product();
                $data['items'][]=[
                    'name'=>$item->get_name(),
                    'quantity'=>(int)$item->get_quantity(),
                    'product_id'=>(int)$item->get_product_id(),
                    'purchasable'=>(bool)($product && $product->is_purchasable() && $product->is_in_stock())
                ];
            }
            $data['customer_notes']=[];
            foreach ($order->get_customer_order_notes() as $note) {
                $data['customer_notes'][]=['date'=>$note->comment_date_gmt,'note'=>wp_strip_all_tags($note->comment_content)];
            }
        }
        return $data;
    }

    public static function rest_orders($request) {
        if (!function_exists('wc_get_orders')) return new WP_Error('woocommerce_unavailable','WooCommerce is not active.',['status'=>503]);
        $page=max(1,absint($request->get_param('page')?:1));
        $limit=min(50,max(1,absint($request->get_param('per_page')?:10)));
        $status=sanitize_key((string)$request->get_param('status'));
        $args=['customer_id'=>get_current_user_id(),'limit'=>$limit,'page'=>$page,'paginate'=>true,'orderby'=>'date','order'=>'DESC','return'=>'objects'];
        if ($status && array_key_exists('wc-'.$status,wc_get_order_statuses())) $args['status']=[$status];
        $result=wc_get_orders($args);
        $orders=[];
        foreach ($result->orders as $order) $orders[]=self::order_payload($order,false);
        return rest_ensure_response([
            'orders'=>$orders,
            'pagination'=>[
                'page'=>$page,'per_page'=>$limit,
                'total'=>(int)$result->total,
                'total_pages'=>(int)$result->max_num_pages
            ]
        ]);
    }

    public static function rest_order_detail($request) {
        if (!function_exists('wc_get_order')) return new WP_Error('woocommerce_unavailable','WooCommerce is not active.',['status'=>503]);
        $order=wc_get_order(absint($request['id']));
        if (!$order || (int)$order->get_customer_id()!==get_current_user_id()) return new WP_Error('not_found','Order not found.',['status'=>404]);
        return rest_ensure_response(self::order_payload($order,true));
    }

    public static function order_history_shortcode($atts=[]) {
        if (!function_exists('wc_get_orders')) return '<p>Order history is available when WooCommerce is active.</p>';
        if (!is_user_logged_in()) {
            $url=function_exists('wc_get_page_permalink')?wc_get_page_permalink('myaccount'):wp_login_url(get_permalink());
            return '<section class="fb-order-history"><h2>Your orders</h2><p>Please sign in to view your order history.</p><a class="fb-core-button" href="'.esc_url($url).'">Sign in</a></section>';
        }
        $atts=shortcode_atts(['per_page'=>10],$atts,'freebooks_order_history');
        $page=max(1,absint($_GET['fb_order_page']??1));
        $status=sanitize_key(wp_unslash($_GET['fb_order_status']??''));
        $limit=min(30,max(1,absint($atts['per_page'])));
        $args=['customer_id'=>get_current_user_id(),'limit'=>$limit,'page'=>$page,'paginate'=>true,'orderby'=>'date','order'=>'DESC','return'=>'objects'];
        if ($status && array_key_exists('wc-'.$status,wc_get_order_statuses())) $args['status']=[$status];
        $result=wc_get_orders($args);
        ob_start();
        echo '<section class="fb-order-history"><div class="fb-order-history-heading"><h2>Your orders</h2><form method="get">';
        foreach($_GET as $key=>$value) if($key!=='fb_order_status'&&$key!=='fb_order_page'&&!is_array($value)) echo '<input type="hidden" name="'.esc_attr(sanitize_key($key)).'" value="'.esc_attr(sanitize_text_field(wp_unslash($value))).'">';
        echo '<label for="fb-order-status">Filter by status</label><select id="fb-order-status" name="fb_order_status"><option value="">All orders</option>';
        foreach(wc_get_order_statuses() as $key=>$label) { $slug=preg_replace('/^wc-/','',$key); echo '<option value="'.esc_attr($slug).'" '.selected($status,$slug,false).'>'.esc_html($label).'</option>'; }
        echo '</select><button type="submit">Filter</button></form></div>';
        if (!$result->orders) echo '<p>You have no orders matching this filter.</p>';
        foreach($result->orders as $order) {
            $created=$order->get_date_created();
            echo '<article class="fb-order-history-card"><div class="fb-order-history-top"><div><strong>Order #'.esc_html($order->get_order_number()).'</strong><span>'.esc_html($created?wc_format_datetime($created):'').'</span></div><span class="fb-order-status">'.esc_html(wc_get_order_status_name($order->get_status())).'</span></div><ul class="fb-order-items">';
            foreach($order->get_items() as $item) echo '<li>'.esc_html($item->get_name()).' <span>× '.absint($item->get_quantity()).'</span></li>';
            echo '</ul><div class="fb-order-history-bottom"><strong>'.wp_kses_post($order->get_formatted_order_total()).'</strong><a href="'.esc_url($order->get_view_order_url()).'">View order details</a></div>';
            $reorder=[];
            foreach($order->get_items() as $item) {
                $product=$item->get_product();
                if($product && $product->is_purchasable() && $product->is_in_stock() && $product->is_type('simple')) $reorder[]=$product->get_id();
            }
            if($reorder) {
                $url=add_query_arg(['fb_reorder'=>implode(',',array_map('absint',$reorder))],wc_get_cart_url());
                echo '<p class="fb-order-reorder"><a href="'.esc_url($url).'">Add available items to cart again</a></p>';
            }
            echo '</article>';
        }
        if ((int)$result->max_num_pages>1) {
            echo '<nav class="fb-order-pagination" aria-label="Order history pages">';
            for($i=1;$i<=(int)$result->max_num_pages;$i++) echo '<a '.($i===$page?'aria-current="page"':'').' href="'.esc_url(add_query_arg('fb_order_page',$i)).'">'.absint($i).'</a>';
            echo '</nav>';
        }
        echo '<p class="fb-order-history-note">Payment, refund and parcel tracking status are provided by WooCommerce, the payment gateway and the configured shipping carrier.</p></section>';
        return ob_get_clean();
    }
}
