<?php
if (!defined('ABSPATH')) exit;

/**
 * FreeBooks fulfilment visibility and staff-entered shipment updates.
 * Carrier booking, labels, tracking events and webhooks remain with the carrier plugin.
 */
class FB_Fulfilment {
    const META_PREFIX = '_fb_fulfilment_';
    const STATUSES = [
        'pending'=>'Preparing',
        'packed'=>'Packed',
        'dispatched'=>'Dispatched',
        'delivered'=>'Delivered',
        'exception'=>'Delivery exception',
        'returned'=>'Returned'
    ];

    public static function init() {
        add_action('admin_menu',[self::class,'menu'],60);
        add_action('add_meta_boxes',[self::class,'add_order_metabox']);
        add_action('admin_post_fb_save_fulfilment',[self::class,'save_fulfilment']);
        add_shortcode('freebooks_shipping_status',[self::class,'customer_shipping_shortcode']);
        add_filter('woocommerce_my_account_my_orders_actions',[self::class,'my_order_action'],10,2);
    }

    private static function meta($key) { return self::META_PREFIX.$key; }

    public static function add_order_metabox() {
        if (!class_exists('WooCommerce')) return;
        add_meta_box('fb_fulfilment','FreeBooks fulfilment & tracking',[self::class,'order_metabox'],'shop_order','side','high');
        add_meta_box('fb_fulfilment_hpos','FreeBooks fulfilment & tracking',[self::class,'order_metabox'],'woocommerce_page_wc-orders','side','high');
    }

    private static function get_order($object) {
        if ($object instanceof WC_Order) return $object;
        if (is_object($object) && isset($object->ID)) return wc_get_order($object->ID);
        return false;
    }

    public static function order_metabox($object) {
        $order=self::get_order($object);
        if (!$order) { echo '<p>Order details unavailable.</p>'; return; }
        if (!current_user_can('edit_shop_order',$order->get_id())) { echo '<p>Permission denied.</p>'; return; }
        $id=$order->get_id();
        $status=(string)$order->get_meta(self::meta('status'));
        if (!$status || !isset(self::STATUSES[$status])) $status='pending';
        $carrier=(string)$order->get_meta(self::meta('carrier'));
        $tracking=(string)$order->get_meta(self::meta('tracking_number'));
        $url=(string)$order->get_meta(self::meta('tracking_url'));
        $dispatch=(string)$order->get_meta(self::meta('dispatch_date'));
        $eta=(string)$order->get_meta(self::meta('eta'));
        echo '<p>Book shipments and generate labels in your carrier plugin first. Enter the resulting tracking details here for customer visibility.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fb_save_fulfilment"><input type="hidden" name="order_id" value="'.absint($id).'">';
        wp_nonce_field('fb_save_fulfilment_'.$id);
        echo '<p><label><strong>Fulfilment status</strong><select name="fb_status" style="width:100%">';
        foreach(self::STATUSES as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($status,$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label></p>';
        echo '<p><label><strong>Carrier</strong><input type="text" name="fb_carrier" maxlength="100" class="widefat" value="'.esc_attr($carrier).'" placeholder="e.g. Delhivery"></label></p>';
        echo '<p><label><strong>Tracking / AWB number</strong><input type="text" name="fb_tracking_number" maxlength="120" class="widefat" value="'.esc_attr($tracking).'"></label></p>';
        echo '<p><label><strong>Carrier tracking URL</strong><input type="url" name="fb_tracking_url" maxlength="500" class="widefat" value="'.esc_attr($url).'" placeholder="https://..."></label></p>';
        echo '<p><label><strong>Dispatch date</strong><input type="date" name="fb_dispatch_date" class="widefat" value="'.esc_attr($dispatch).'"></label></p>';
        echo '<p><label><strong>Estimated delivery date</strong><input type="date" name="fb_eta" class="widefat" value="'.esc_attr($eta).'"></label></p>';
        echo '<p><label><input type="checkbox" name="fb_notify_customer" value="1"> Email customer if status changed</label></p>';
        submit_button('Save fulfilment details','primary','submit',false);
        echo '</form>';
        if ($url && wp_http_validate_url($url)) echo '<p><a href="'.esc_url($url).'" target="_blank" rel="noopener">Open carrier tracking page</a></p>';
    }

    public static function save_fulfilment() {
        $id=absint($_POST['order_id']??0);
        if (!$id || !function_exists('wc_get_order')) wp_die('Order not found.',404);
        check_admin_referer('fb_save_fulfilment_'.$id);
        if (!current_user_can('edit_shop_order',$id)) wp_die('Permission denied.',403);
        $order=wc_get_order($id);
        if (!$order) wp_die('Order not found.',404);
        $status=sanitize_key(wp_unslash($_POST['fb_status']??'pending'));
        if (!isset(self::STATUSES[$status])) wp_die('Invalid fulfilment status.',400);
        $old=(string)$order->get_meta(self::meta('status'));
        $carrier=sanitize_text_field(wp_unslash($_POST['fb_carrier']??''));
        $tracking=sanitize_text_field(wp_unslash($_POST['fb_tracking_number']??''));
        $url=esc_url_raw(wp_unslash($_POST['fb_tracking_url']??''));
        if ($url && !wp_http_validate_url($url)) wp_die('Please enter a valid tracking URL.',400);
        $dispatch=sanitize_text_field(wp_unslash($_POST['fb_dispatch_date']??''));
        $eta=sanitize_text_field(wp_unslash($_POST['fb_eta']??''));
        foreach(['dispatch_date'=>$dispatch,'eta'=>$eta] as $field=>$date) {
            if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) wp_die('Please use valid dates.',400);
        }
        $order->update_meta_data(self::meta('status'),$status);
        $order->update_meta_data(self::meta('carrier'),$carrier);
        $order->update_meta_data(self::meta('tracking_number'),$tracking);
        $order->update_meta_data(self::meta('tracking_url'),$url);
        $order->update_meta_data(self::meta('dispatch_date'),$dispatch);
        $order->update_meta_data(self::meta('eta'),$eta);
        $order->save();

        $changed=($old!==$status);
        $notify = !empty($_POST['fb_notify_customer']);
        $customer_id = (int)$order->get_customer_id();
        if ($notify && $customer_id && class_exists('FB_Notifications')) {
            $prefs = FB_Notifications::preferences($customer_id);
            if (empty($prefs['order_updates'])) $notify = false;
        }
        if ($changed) {
            $note='FreeBooks fulfilment updated: '.self::STATUSES[$status].'.';
            if ($tracking) $note.=' Carrier: '.$carrier.'; tracking: '.$tracking.'.';
            if ($eta) $note.=' Estimated delivery: '.$eta.'.';
            $order->add_order_note($note,$notify);
        }
        if ($notify && $changed) self::send_customer_update($order,$status,$carrier,$tracking,$url,$eta);
        if (class_exists('FB_Governance')) FB_Governance::log('fulfilment_updated',0,'Order #'.$order->get_order_number().' fulfilment changed to '.$status.'.');
        wp_safe_redirect(wp_get_referer()?:admin_url('admin.php?page=wc-orders'));
        exit;
    }

    private static function send_customer_update($order,$status,$carrier,$tracking,$url,$eta) {
        $email=sanitize_email($order->get_billing_email());
        if (!$email || !is_email($email)) return false;
        $label=self::STATUSES[$status]??$status;
        $body="Hello ".($order->get_billing_first_name()?:'there').",\n\nYour FreeBooks order #".$order->get_order_number()." has a fulfilment update: ".$label.".\n";
        if ($carrier) $body.="\nCarrier: ".$carrier;
        if ($tracking) $body.="\nTracking number: ".$tracking;
        if ($eta) $body.="\nEstimated delivery: ".$eta;
        if ($url) $body.="\nTrack your parcel: ".$url;
        $body.="\n\nFor payment or order questions, please contact FreeBooks support.\nFreeBooks — SamagraShriSai Foundation";
        $sent=wp_mail($email,'FreeBooks order #'.$order->get_order_number().' — '.$label,$body,['Content-Type: text/plain; charset=UTF-8']);
        if (class_exists('FB_Donations')) {
            // Keep shipment notifications in WooCommerce order notes; donation communication log is not applicable.
        }
        return (bool)$sent;
    }

    public static function customer_shipping_shortcode() {
        if (!function_exists('wc_get_orders')) return '<p>Shipping status is available when WooCommerce is active.</p>';
        if (!is_user_logged_in()) {
            $url=function_exists('wc_get_page_permalink')?wc_get_page_permalink('myaccount'):wp_login_url(get_permalink());
            return '<section class="fb-shipping-status"><h2>Delivery updates</h2><p>Sign in to view shipment updates for your orders.</p><a class="fb-core-button" href="'.esc_url($url).'">Sign in</a></section>';
        }
        $orders=wc_get_orders(['customer_id'=>get_current_user_id(),'limit'=>20,'orderby'=>'date','order'=>'DESC','return'=>'objects']);
        ob_start();
        echo '<section class="fb-shipping-status"><h2>Delivery updates</h2>';
        $count=0;
        foreach($orders as $order) {
            $status=(string)$order->get_meta(self::meta('status'));
            if (!$status || !isset(self::STATUSES[$status])) continue;
            $count++;
            $carrier=(string)$order->get_meta(self::meta('carrier'));
            $tracking=(string)$order->get_meta(self::meta('tracking_number'));
            $url=(string)$order->get_meta(self::meta('tracking_url'));
            $eta=(string)$order->get_meta(self::meta('eta'));
            echo '<article class="fb-shipping-card"><div><strong>Order #'.esc_html($order->get_order_number()).'</strong><span>'.esc_html(self::STATUSES[$status]).'</span></div>';
            if ($carrier) echo '<p>Carrier: '.esc_html($carrier).'</p>';
            if ($tracking) echo '<p>Tracking number: <strong>'.esc_html($tracking).'</strong></p>';
            if ($eta) echo '<p>Estimated delivery: '.esc_html($eta).'</p>';
            if ($url && wp_http_validate_url($url)) echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener">Track with carrier</a>';
            echo '</article>';
        }
        if (!$count) echo '<p>No shipment updates are available yet. Check your order page again after dispatch.</p>';
        echo '<p class="fb-shipping-note">Parcel scans and delivery events are provided by the shipping carrier. FreeBooks displays the tracking information supplied by the fulfilment team.</p></section>';
        return ob_get_clean();
    }

    public static function my_order_action($actions,$order) {
        $url=(string)$order->get_meta(self::meta('tracking_url'));
        if ($url && wp_http_validate_url($url)) $actions['fb_track_shipment']=['url'=>$url,'name'=>'Track shipment'];
        return $actions;
    }

    public static function admin_page() {
        if (!current_user_can('manage_woocommerce')) wp_die('Permission denied.',403);
        if (!function_exists('wc_get_orders')) { echo '<div class="wrap"><h1>Fulfilment</h1><p>Activate WooCommerce to manage order fulfilment.</p></div>'; return; }
        $orders=wc_get_orders(['limit'=>100,'orderby'=>'date','order'=>'DESC','return'=>'objects','status'=>array_keys(wc_get_order_statuses())]);
        echo '<div class="wrap"><h1>FreeBooks fulfilment overview</h1><p>Use your carrier plugin to book shipments, generate labels and receive tracking events. This screen monitors fulfilment details recorded by staff.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Customer</th><th>Order status</th><th>Fulfilment</th><th>Carrier / tracking</th><th>ETA</th><th>Manage</th></tr></thead><tbody>';
        $rows=0;
        foreach($orders as $order) {
            $status=(string)$order->get_meta(self::meta('status'));
            if (!$status) $status='pending';
            $carrier=(string)$order->get_meta(self::meta('carrier'));
            $tracking=(string)$order->get_meta(self::meta('tracking_number'));
            $eta=(string)$order->get_meta(self::meta('eta'));
            echo '<tr><td>#'.esc_html($order->get_order_number()).'<br>'.esc_html($order->get_date_created()?$order->get_date_created()->date_i18n('Y-m-d'):'').'</td><td>'.esc_html($order->get_formatted_billing_full_name()).'<br>'.esc_html($order->get_billing_email()).'</td><td>'.esc_html(wc_get_order_status_name($order->get_status())).'</td><td>'.esc_html(self::STATUSES[$status]??$status).'</td><td>'.esc_html(trim($carrier.' '.$tracking)).'</td><td>'.esc_html($eta).'</td><td><a href="'.esc_url($order->get_edit_order_url()).'">Edit order</a></td></tr>';
            $rows++;
        }
        if (!$rows) echo '<tr><td colspan="7">No WooCommerce orders found.</td></tr>';
        echo '</tbody></table><p>Only the latest 100 orders are shown. Carrier label generation, parcel tracking events and delivery proof remain in the carrier plugin.</p></div>';
    }

    public static function menu() {
        add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT,'Fulfilment overview','Fulfilment','manage_woocommerce','fb-fulfilment',[self::class,'admin_page']);
    }
}
