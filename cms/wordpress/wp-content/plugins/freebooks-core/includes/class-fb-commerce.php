<?php
if (!defined('ABSPATH')) exit;
class FB_Commerce {
    public static function init() {
        if (!class_exists('WooCommerce')) return;
        add_action('transition_post_status', [self::class,'catalogue_published'], 20, 3);
        add_action('woocommerce_product_set_stock', [self::class,'stock_changed']);
        add_action('woocommerce_variation_set_stock', [self::class,'stock_changed']);
        add_action('woocommerce_order_status_processing', [self::class,'order_paid']);
        add_action('woocommerce_order_status_completed', [self::class,'order_paid']);
        add_action('woocommerce_product_options_inventory_product_data', [self::class,'product_inventory_field']);
        add_action('woocommerce_process_product_meta', [self::class,'save_product_inventory_field']);
    }

    public static function catalogue_published($new, $old, $post) {
        if ($post->post_type !== FREEBOOKS_ITEM_CPT || $new !== 'publish' || $old === 'publish') return;
        self::sync_catalogue_item($post->ID);
    }

    public static function sync_catalogue_item($post_id) {
        if (!class_exists('WC_Product_Simple')) return;
        $existing = (int)get_post_meta($post_id, '_fb_wc_product_id', true);
        $product = $existing ? wc_get_product($existing) : new WC_Product_Simple();
        if (!$product) $product = new WC_Product_Simple();
        $post = get_post($post_id);
        if (!$post) return;
        $price = (float)get_post_meta($post_id, '_fb_price', true);
        $stock = max(0, (int)get_post_meta($post_id, '_fb_stock', true));
        $product->set_name(get_the_title($post_id));
        $product->set_description(wp_strip_all_tags($post->post_content));
        $product->set_short_description(wp_strip_all_tags(get_the_excerpt($post_id)));
        $product->set_status('draft'); // Admin must review before making a product purchasable.
        $product->set_catalog_visibility('visible');
        $product->set_regular_price((string)$price);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock);
        $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        $product->set_sold_individually($stock === 1);
        $product->set_virtual(false);
        $product_id = $product->save();
        update_post_meta($post_id, '_fb_wc_product_id', $product_id);
        update_post_meta($post_id, '_fb_wc_sync_status', 'draft_created');
        return $product_id;
    }

    public static function stock_changed($product) {
        if (!$product || !is_a($product, 'WC_Product')) return;
        $product_id = $product->get_id();
        $items = get_posts([
            'post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>'any','numberposts'=>1,
            'meta_key'=>'_fb_wc_product_id','meta_value'=>$product_id,'fields'=>'ids'
        ]);
        if (!$items) return;
        $item_id = $items[0];
        $qty = (int)$product->get_stock_quantity();
        if (class_exists('FB_Inventory')) FB_Inventory::sync_from_woocommerce($item_id, $qty);
        else {
            update_post_meta($item_id, '_fb_stock', max(0,$qty));
            update_post_meta($item_id, '_fb_availability', $qty <= 0 ? 'out_of_stock' : 'available');
        }
    }

    public static function order_paid($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_fb_sale_processed')) return;
        foreach ($order->get_items() as $line) {
            $product_id = $line->get_product_id();
            if (!$product_id) continue;
            $items = get_posts([
                'post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>'any','numberposts'=>1,
                'meta_key'=>'_fb_wc_product_id','meta_value'=>$product_id,'fields'=>'ids'
            ]);
            if (!$items) continue;
            $item_id = $items[0];
            $qty = (int)get_post_meta($item_id,'_fb_stock',true);
            if ($qty <= 0) {
                update_post_meta($item_id,'_fb_availability','sold');
                $donation_ref = get_post_meta($item_id,'_fb_donation_reference',true);
                if ($donation_ref) self::mark_donation_sold($donation_ref);
            }
        }
        $order->update_meta_data('_fb_sale_processed', 'yes');
        $order->save();
    }

    private static function mark_donation_sold($reference) {
        $ids = get_posts([
            'post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>1,
            'meta_key'=>'_fb_reference','meta_value'=>$reference,'fields'=>'ids'
        ]);
        if ($ids) FB_Donations::set_status($ids[0], 'sold', 'Linked catalogue item sold through WooCommerce.');
    }

    public static function product_inventory_field() {
        woocommerce_wp_text_input([
            'id'=>'_fb_catalogue_item_id','label'=>'FreeBooks catalogue item ID',
            'desc_tip'=>true,'description'=>'Linked FreeBooks catalogue record.'
        ]);
    }
    public static function save_product_inventory_field($product_id) {
        if (isset($_POST['_fb_catalogue_item_id'])) {
            update_post_meta($product_id,'_fb_catalogue_item_id',absint(wp_unslash($_POST['_fb_catalogue_item_id'])));
        }
    }
}
