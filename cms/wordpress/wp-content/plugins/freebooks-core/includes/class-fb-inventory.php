<?php
if (!defined('ABSPATH')) exit;
class FB_Inventory {
    const DEFAULT_THRESHOLD = 3;
    public static function menu() {
        add_submenu_page('edit.php?post_type='.FREEBOOKS_ITEM_CPT,'Inventory tools','Inventory tools','manage_options','fb-inventory-tools',[self::class,'page']);
        add_submenu_page('edit.php?post_type='.FREEBOOKS_ITEM_CPT,'Operations dashboard','Operations dashboard','manage_options','fb-operations',[self::class,'dashboard']);
    }
    private static function allowed() {
        return current_user_can('manage_options') || current_user_can('manage_fb_inventory');
    }
    private static function threshold() {
        return max(0, absint(get_option('fb_inventory_low_stock_threshold', self::DEFAULT_THRESHOLD)));
    }
    private static function ledger($item_id) {
        $ledger = get_post_meta($item_id, '_fb_stock_ledger', true);
        return is_array($ledger) ? $ledger : [];
    }
    private static function record_movement($item_id, $before, $after, $reason, $source='manual') {
        $ledger = self::ledger($item_id);
        array_unshift($ledger, [
            'date'=>current_time('mysql'),
            'user'=>get_current_user_id(),
            'before'=>(int)$before,
            'after'=>(int)$after,
            'change'=>(int)$after-(int)$before,
            'reason'=>sanitize_text_field($reason),
            'source'=>sanitize_key($source)
        ]);
        update_post_meta($item_id, '_fb_stock_ledger', array_slice($ledger, 0, 100));
    }
    public static function sync_from_woocommerce($item_id, $quantity) {
        $before = max(0, (int)get_post_meta($item_id, '_fb_stock', true));
        $after = max(0, (int)$quantity);
        if ($before === $after) return;
        update_post_meta($item_id, '_fb_stock', $after);
        update_post_meta($item_id, '_fb_availability', $after > 0 ? 'available' : 'out_of_stock');
        self::record_movement($item_id, $before, $after, 'WooCommerce stock synchronisation', 'woocommerce');
    }
    private static function set_stock($item_id, $quantity, $reason, $source='manual') {
        $before = max(0, (int)get_post_meta($item_id, '_fb_stock', true));
        $after = max(0, absint($quantity));
        update_post_meta($item_id, '_fb_stock', $after);
        update_post_meta($item_id, '_fb_availability', $after > 0 ? 'available' : 'out_of_stock');
        self::record_movement($item_id, $before, $after, $reason, $source);

        // Keep a linked WooCommerce product authoritative and in sync.
        $product_id = absint(get_post_meta($item_id, '_fb_wc_product_id', true));
        if ($product_id && function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($after);
                $product->set_stock_status($after > 0 ? 'instock' : 'outofstock');
                $product->set_sold_individually($after === 1);
                $product->save();
            }
        }
        return [$before, $after];
    }
    public static function page() {
        if (!self::allowed()) wp_die('Permission denied.');
        if (isset($_GET['fb_stock_saved'])) echo '<div class="notice notice-success"><p>Stock quantity updated and movement recorded.</p></div>';
        if (isset($_GET['fb_imported'])) {
            echo '<div class="notice notice-success"><p>Imported '.absint($_GET['fb_imported']).' row(s); skipped '.absint($_GET['fb_errors']??0).' row(s).</p></div>';
        }
        $threshold = self::threshold();
        $items = get_posts(['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>['publish','draft','pending','private'],'numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
        $low_items = [];
        foreach ($items as $item) {
            $qty = max(0,(int)get_post_meta($item->ID,'_fb_stock',true));
            if ($qty <= $threshold) $low_items[] = [$item,$qty];
        }
        echo '<div class="wrap"><h1>FreeBooks Inventory & Catalogue Operations</h1>';
        echo '<p>Manage physical catalogue quantities, review stock movement history and export inventory. Linked WooCommerce stock is synchronised when an authorised adjustment is saved.</p>';
        echo '<h2>Low-stock threshold</h2><form method="post" action="'.esc_url(admin_url('options.php')).'">';
        settings_fields('fb_inventory_settings'); echo '<label>Alert when quantity is at or below <input type="number" min="0" name="fb_inventory_low_stock_threshold" value="'.esc_attr($threshold).'" class="small-text"></label> ';
        submit_button('Save threshold','secondary','submit',false); echo '</form>';
        echo '<h2>Stock adjustment</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="background:#fff;padding:16px;border:1px solid #dcdcde;max-width:760px">';
        echo '<input type="hidden" name="action" value="fb_inventory_adjust">'; wp_nonce_field('fb_inventory_adjust');
        echo '<p><label>Catalogue item<br><select name="item_id" required style="min-width:320px"><option value="">Select an item</option>';
        foreach($items as $item) echo '<option value="'.esc_attr($item->ID).'">'.esc_html(get_the_title($item)).' (#'.esc_html($item->ID).') — current: '.esc_html(max(0,(int)get_post_meta($item->ID,'_fb_stock',true))).'</option>';
        echo '</select></label></p><p><label>New stock quantity <input type="number" name="quantity" min="0" step="1" required class="small-text"></label></p>';
        echo '<p><label>Reason <input type="text" name="reason" required maxlength="180" class="regular-text" placeholder="e.g. received 4 donated copies"></label></p>';
        submit_button('Save stock adjustment','primary','submit',false); echo '</form>';
        echo '<h2>Low-stock / out-of-stock items ('.count($low_items).')</h2><table class="widefat striped"><thead><tr><th>Item</th><th>SKU / ISBN</th><th>Stock</th><th>Condition</th><th>Price</th><th>Storage</th></tr></thead><tbody>';
        if (!$low_items) echo '<tr><td colspan="6">No items are at or below the configured threshold.</td></tr>';
        foreach($low_items as [$item,$qty]) {
            echo '<tr><td><a href="'.esc_url(get_edit_post_link($item->ID)).'">'.esc_html(get_the_title($item)).'</a></td><td>'.esc_html(get_post_meta($item->ID,'_fb_isbn',true)).'</td><td><strong>'.esc_html($qty).'</strong></td><td>'.esc_html(get_post_meta($item->ID,'_fb_condition',true)).'</td><td>'.esc_html(get_post_meta($item->ID,'_fb_price',true)).'</td><td>'.esc_html(get_post_meta($item->ID,'_fb_shelf_code',true)).'</td></tr>';
        }
        echo '</tbody></table>';
        $movements = [];
        foreach ($items as $item) {
            foreach (self::ledger($item->ID) as $entry) {
                $entry['item_id'] = $item->ID;
                $entry['item_title'] = get_the_title($item);
                $movements[] = $entry;
            }
        }
        usort($movements, function($a,$b){ return strcmp((string)($b['date']??''),(string)($a['date']??'')); });
        $movements = array_slice($movements,0,50);
        echo '<h2>Recent stock movement history</h2><table class="widefat striped"><thead><tr><th>Date</th><th>Catalogue item</th><th>Before</th><th>Change</th><th>After</th><th>Source</th><th>Reason</th><th>By</th></tr></thead><tbody>';
        if (!$movements) echo '<tr><td colspan="8">No stock movements recorded yet. Manual adjustments, CSV stock imports and WooCommerce stock changes will appear here.</td></tr>';
        foreach ($movements as $move) {
            $user = !empty($move['user']) ? get_user_by('id',(int)$move['user']) : false;
            echo '<tr><td>'.esc_html($move['date']??'').'</td><td><a href="'.esc_url(get_edit_post_link((int)$move['item_id'])).'">'.esc_html($move['item_title']).'</a></td><td>'.esc_html($move['before']??0).'</td><td>'.esc_html(($move['change']??0)>0?'+'.(int)$move['change']:(int)($move['change']??0)).'</td><td>'.esc_html($move['after']??0).'</td><td>'.esc_html(ucfirst($move['source']??'')).'</td><td>'.esc_html($move['reason']??'').'</td><td>'.esc_html($user?$user->display_name:'System').'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<hr><h2>Export inventory</h2><p>Download catalogue items, price, stock, condition, ISBN and storage references as CSV.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fb_inventory_export">';
        wp_nonce_field('fb_inventory_export');submit_button('Download inventory CSV','primary','submit',false);echo '</form>';
        echo '<hr><h2>Import / update inventory</h2><p>CSV columns: <code>post_id,title,price,stock,condition,isbn,author,publisher,language,shelf_code</code>. Use post_id to update an existing item. Leave post_id blank to create a draft catalogue item.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fb_inventory_import">';
        wp_nonce_field('fb_inventory_import');echo '<input required type="file" name="inventory_csv" accept=".csv,text/csv"> ';submit_button('Import CSV','secondary','submit',false);echo '</form>';
        echo '<p><strong>Safety:</strong> imports create or update catalogue drafts; they do not publish products or change WooCommerce order/payment settings.</p></div>';
    }
    public static function adjust_stock() {
        if (!self::allowed()) wp_die('Permission denied.');
        check_admin_referer('fb_inventory_adjust');
        $item_id = absint($_POST['item_id']??0);
        if (!$item_id || get_post_type($item_id)!==FREEBOOKS_ITEM_CPT) wp_die('Invalid catalogue item.');
        $reason = sanitize_text_field(wp_unslash($_POST['reason']??''));
        if ($reason==='') wp_die('A reason is required for every stock adjustment.');
        $quantity = max(0,absint($_POST['quantity']??0));
        self::set_stock($item_id,$quantity,$reason,'manual');
        if (class_exists('FB_Governance') && method_exists('FB_Governance','audit')) {
            FB_Governance::audit('inventory_stock_adjusted','catalogue_item',$item_id,['quantity'=>$quantity,'reason'=>$reason]);
        }
        wp_safe_redirect(add_query_arg(['page'=>'fb-inventory-tools','fb_stock_saved'=>1],admin_url('edit.php?post_type='.FREEBOOKS_ITEM_CPT))); exit;
    }
    public static function export_csv() {
        if (!self::allowed()) wp_die('Permission denied.');
        check_admin_referer('fb_inventory_export');
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=freebooks-inventory-'.gmdate('Ymd-His').'.csv');
        $out=fopen('php://output','w');
        fputcsv($out,['post_id','title','price','stock','condition','isbn','author','publisher','language','shelf_code','donation_reference','woocommerce_product_id']);
        $items=get_posts(['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>['publish','draft','pending','private'],'numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
        foreach($items as $p) fputcsv($out,[$p->ID,get_the_title($p),get_post_meta($p->ID,'_fb_price',true),get_post_meta($p->ID,'_fb_stock',true),get_post_meta($p->ID,'_fb_condition',true),get_post_meta($p->ID,'_fb_isbn',true),get_post_meta($p->ID,'_fb_author',true),get_post_meta($p->ID,'_fb_publisher',true),get_post_meta($p->ID,'_fb_language',true),get_post_meta($p->ID,'_fb_shelf_code',true),get_post_meta($p->ID,'_fb_donation_reference',true),get_post_meta($p->ID,'_fb_wc_product_id',true)]);
        fclose($out); exit;
    }
    public static function import_csv() {
        if (!self::allowed()) wp_die('Permission denied.');
        check_admin_referer('fb_inventory_import');
        if (empty($_FILES['inventory_csv']['tmp_name'])) wp_die('Choose a CSV file.');
        if ((int)$_FILES['inventory_csv']['size'] > 2*1024*1024) wp_die('CSV must be smaller than 2 MB.');
        $fh=fopen($_FILES['inventory_csv']['tmp_name'],'r'); if (!$fh) wp_die('Could not read CSV.');
        $headers=fgetcsv($fh); $allowed=['post_id','title','price','stock','condition','isbn','author','publisher','language','shelf_code'];
        $headers=array_map(function($v){return sanitize_key($v);},(array)$headers);
        if (!$headers || count($headers)!==count(array_unique($headers)) || array_diff($headers,$allowed)) { fclose($fh); wp_die('CSV has unsupported or duplicate columns. Use the documented template.'); }
        $count=0;$errors=0;
        while(($data=fgetcsv($fh))!==false) {
            if(count($data)!==count($headers)){$errors++;continue;}
            $r=array_combine($headers,$data); $id=absint($r['post_id']??0);
            if($id && get_post_type($id)!==FREEBOOKS_ITEM_CPT){$errors++;continue;}
            $title=sanitize_text_field($r['title']??''); if(!$title){$errors++;continue;}
            $postarr=['post_type'=>FREEBOOKS_ITEM_CPT,'post_title'=>$title,'post_status'=>'draft']; if($id)$postarr['ID']=$id;
            $saved=wp_insert_post($postarr,true); if(is_wp_error($saved)){$errors++;continue;}
            foreach(['price','stock','condition','isbn','author','publisher','language','shelf_code'] as $k) {
                if(!array_key_exists($k,$r))continue; $v=$r[$k];
                if($k==='price')$v=max(0,(float)$v);
                elseif($k==='stock')$v=max(0,absint($v));
                else $v=sanitize_text_field($v);
                if ($k==='stock') self::set_stock($saved,$v,'CSV inventory import','import');
                else update_post_meta($saved,'_fb_'.$k,$v);
            }
            $count++;
        }
        fclose($fh);
        wp_safe_redirect(add_query_arg(['page'=>'fb-inventory-tools','fb_imported'=>$count,'fb_errors'=>$errors],admin_url('edit.php?post_type='.FREEBOOKS_ITEM_CPT)));exit;
    }
    public static function dashboard() {
        if(!self::allowed())wp_die('Permission denied.');
        $donations=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>-1,'fields'=>'ids']);
        $items=get_posts(['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>['publish','draft','pending','private'],'numberposts'=>-1,'fields'=>'ids']);
        $counts=['submitted'=>0,'approved'=>0,'received'=>0,'listed'=>0,'sold'=>0]; foreach($donations as $id){$s=get_post_meta($id,'_fb_status',true);if(isset($counts[$s]))$counts[$s]++;}
        $stock=0;$value=0;$low=0;$threshold=self::threshold(); foreach($items as $id){$q=max(0,(int)get_post_meta($id,'_fb_stock',true));$stock+=$q;$value+=$q*(float)get_post_meta($id,'_fb_price',true);if($q<=$threshold)$low++;}
        echo '<div class="wrap"><h1>FreeBooks Operations Dashboard</h1><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">';
        $cards=['Donations submitted'=>$counts['submitted'],'Approved'=>$counts['approved'],'Received'=>$counts['received'],'Listed'=>$counts['listed'],'Sold'=>$counts['sold'],'Catalogue records'=>count($items),'Units in stock'=>$stock,'Low / out of stock'=>$low];
        foreach($cards as $label=>$number)echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px"><div style="color:#646970">'.esc_html($label).'</div><strong style="font-size:28px">'.esc_html(number_format_i18n($number)).'</strong></div>';
        echo '</div><p>Indicative catalogue retail value: <strong>'.esc_html(function_exists('wc_price') ? wp_strip_all_tags(wc_price($value)) : number_format_i18n($value,2)).'</strong></p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('edit.php?post_type='.FREEBOOKS_ITEM_CPT.'&page=fb-inventory-tools')).'">Inventory management</a></p></div>';
    }
}
add_action('admin_init', function(){ register_setting('fb_inventory_settings','fb_inventory_low_stock_threshold',['type'=>'integer','sanitize_callback'=>function($v){return max(0,absint($v));},'default'=>FB_Inventory::DEFAULT_THRESHOLD]); });
