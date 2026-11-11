<?php
if (!defined('ABSPATH')) exit;
class FB_Growth {
    public static function init() {
        add_action('init',[self::class,'register_request_type']);
        add_shortcode('freebooks_catalogue_search',[self::class,'search_shortcode']);
        add_shortcode('freebooks_wishlist',[self::class,'wishlist_shortcode']);
        add_shortcode('freebooks_book_request',[self::class,'request_shortcode']);
        add_shortcode('freebooks_back_in_stock',[self::class,'stock_form_shortcode']);
        add_action('admin_post_nopriv_fbgm_wishlist',[self::class,'handle_wishlist']);
        add_action('admin_post_fbgm_wishlist',[self::class,'handle_wishlist']);
        add_action('admin_post_nopriv_fbgm_book_request',[self::class,'handle_request']);
        add_action('admin_post_fbgm_book_request',[self::class,'handle_request']);
        add_action('admin_post_nopriv_fbgm_stock_alert',[self::class,'handle_stock_alert']);
        add_action('admin_post_fbgm_stock_alert',[self::class,'handle_stock_alert']);
        add_action('woocommerce_product_set_stock',[self::class,'notify_stock']);
        add_action('woocommerce_variation_set_stock',[self::class,'notify_stock']);
        add_action('add_meta_boxes',[self::class,'request_metabox']);
        add_action('save_post_fb_book_request',[self::class,'save_request_status']);
        add_filter('manage_fb_book_request_posts_columns',[self::class,'request_columns']);
        add_action('manage_fb_book_request_posts_custom_column',[self::class,'request_column'],10,2);
        add_action('admin_menu',[self::class,'admin_menu']);
        add_action('admin_post_fbgm_export_requests',[self::class,'export_requests']);
        add_action('init',[self::class,'account_endpoint']);
        add_filter('query_vars',[self::class,'query_vars']);
        add_filter('woocommerce_account_menu_items',[self::class,'account_menu']);
        add_action('woocommerce_account_freebooks-wishlist_endpoint',[self::class,'account_wishlist']);
    }
    public static function register_request_type() {
        register_post_type('fb_book_request',[
            'labels'=>['name'=>'Book Requests','singular_name'=>'Book Request'],
            'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'supports'=>['title','editor'],
            'capability_type'=>'post','map_meta_cap'=>true,'menu_icon'=>'dashicons-book-alt'
        ]);
        foreach(['fb-request-new'=>'New','fb-request-review'=>'Under review','fb-request-found'=>'Found','fb-request-unavailable'=>'Unavailable','fb-request-fulfilled'=>'Fulfilled'] as $slug=>$label) {
            register_post_status($slug,['label'=>$label,'public'=>false,'internal'=>false,'show_in_admin_all_list'=>true,'show_in_admin_status_list'=>true,'label_count'=>_n_noop($label.' <span class="count">(%s)</span>',$label.' <span class="count">(%s)</span>')]);
        }
    }
    public static function search_shortcode($atts=[]) {
        $atts=shortcode_atts(['per_page'=>12],$atts);
        $term=sanitize_text_field(wp_unslash($_GET['fbq']??''));
        $lang=sanitize_text_field(wp_unslash($_GET['fblang']??''));
        $condition=sanitize_text_field(wp_unslash($_GET['fbcondition']??''));
        $available=!empty($_GET['fbavailable']);
        $cat=absint($_GET['fbcat']??0);
        $args=['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>'publish','posts_per_page'=>min(48,max(1,absint($atts['per_page']))),'s'=>$term];
        if($cat) $args['tax_query']=[['taxonomy'=>'fb_item_category','field'=>'term_id','terms'=>$cat]];
        $meta=[];
        if($lang) $meta[]=['key'=>'_fb_language','value'=>$lang,'compare'=>'LIKE'];
        if($condition) $meta[]=['key'=>'_fb_condition','value'=>$condition,'compare'=>'='];
        if($available) $meta[]=['key'=>'_fb_stock','value'=>1,'compare'=>'>=','type'=>'NUMERIC'];
        if($meta) $args['meta_query']=$meta;
        $q=new WP_Query($args);
        ob_start(); ?>
        <form class="fb-core-search" method="get">
          <label>Search books <input name="fbq" value="<?php echo esc_attr($term); ?>" placeholder="Title, author or keyword"></label>
          <label>Language <input name="fblang" value="<?php echo esc_attr($lang); ?>" placeholder="e.g. Hindi"></label>
          <label>Condition <select name="fbcondition"><option value="">Any condition</option><?php foreach(['New','Like New','Good','Fair','Used'] as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($condition,$v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
          <label>Category <?php wp_dropdown_categories(['taxonomy'=>'fb_item_category','name'=>'fbcat','show_option_all'=>'All categories','hide_empty'=>false,'selected'=>$cat,'value_field'=>'term_id']); ?></label>
          <label class="fb-core-check"><input type="checkbox" name="fbavailable" value="1" <?php checked($available); ?>> In stock only</label>
          <button type="submit">Search catalogue</button>
        </form>
        <div class="fb-core-grid">
        <?php if($q->have_posts()): while($q->have_posts()): $q->the_post(); $id=get_the_ID(); ?>
          <article class="fb-core-card">
            <?php if(has_post_thumbnail()) echo get_the_post_thumbnail($id,'medium'); ?>
            <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
            <p><?php echo esc_html(wp_trim_words(get_the_excerpt(),18)); ?></p>
            <p><?php echo esc_html(get_post_meta($id,'_fb_condition',true)); ?><?php $price=(float)get_post_meta($id,'_fb_price',true); if($price>0) echo ' · '.esc_html(function_exists('wc_price')?wp_strip_all_tags(wc_price($price)):'₹'.number_format_i18n($price,2)); ?></p>
            <?php echo self::wishlist_button($id); ?>
          </article>
        <?php endwhile; else: ?><p>No catalogue items matched your search.</p><?php endif; wp_reset_postdata(); ?>
        </div>
        <?php return ob_get_clean();
    }
    private static function wishlist_ids() {
        if(is_user_logged_in()) return array_values(array_unique(array_map('absint',(array)get_user_meta(get_current_user_id(),'_fbgm_wishlist',true))));
        $raw=sanitize_text_field(wp_unslash($_COOKIE['fbgm_wishlist']??''));
        return array_values(array_unique(array_filter(array_map('absint',explode(',',$raw)))));
    }
    private static function wishlist_button($id) {
        $active=in_array((int)$id,self::wishlist_ids(),true);
        $return=get_permalink($id);
        return '<form class="fb-core-inline" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fbgm_wishlist"><input type="hidden" name="book_id" value="'.absint($id).'"><input type="hidden" name="return" value="'.esc_url($return).'">'.wp_nonce_field('fbgm_wishlist_'.$id,'_wpnonce',true,false).'<button type="submit">'.($active?'♥ Saved':'♡ Add to wishlist').'</button></form>';
    }
    public static function handle_wishlist() {
        $id=absint($_POST['book_id']??0); check_admin_referer('fbgm_wishlist_'.$id);
        if(get_post_type($id)!==FREEBOOKS_ITEM_CPT) wp_die('Invalid catalogue item.');
        $ids=self::wishlist_ids();
        if(in_array($id,$ids,true)) $ids=array_values(array_diff($ids,[$id])); else $ids[]=$id;
        $ids=array_slice(array_values(array_unique($ids)),-100);
        if(is_user_logged_in()) update_user_meta(get_current_user_id(),'_fbgm_wishlist',$ids);
        else setcookie('fbgm_wishlist',implode(',',$ids),['expires'=>time()+MONTH_IN_SECONDS,'path'=>COOKIEPATH?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
        $return=esc_url_raw(wp_unslash($_POST['return']??home_url('/'))); wp_safe_redirect($return); exit;
    }
    public static function wishlist_shortcode() {
        $ids=self::wishlist_ids(); if(!$ids) return '<p>Your wishlist is empty.</p>';
        $q=new WP_Query(['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>'publish','post__in'=>$ids,'orderby'=>'post__in','posts_per_page'=>100]);
        ob_start(); echo '<div class="fb-core-grid">';
        while($q->have_posts()){ $q->the_post(); echo '<article class="fb-core-card"><h3><a href="'.esc_url(get_permalink()).'">'.esc_html(get_the_title()).'</a></h3>'.self::wishlist_button(get_the_ID()).'</article>'; }
        wp_reset_postdata(); echo '</div>'; return ob_get_clean();
    }
    public static function account_endpoint(){ if(function_exists('add_rewrite_endpoint')) add_rewrite_endpoint('freebooks-wishlist',EP_ROOT|EP_PAGES); }
    public static function query_vars($vars){$vars[]='freebooks-wishlist';return $vars;}
    public static function account_menu($items){
        if(isset($items['customer-logout'])){$logout=$items['customer-logout'];unset($items['customer-logout']);$items['freebooks-wishlist']='My Wishlist';$items['customer-logout']=$logout;}
        else $items['freebooks-wishlist']='My Wishlist';
        return $items;
    }
    public static function account_wishlist(){ echo do_shortcode('[freebooks_wishlist]'); }
    public static function request_shortcode() {
        ob_start(); ?>
        <form class="fb-core-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="fbgm_book_request"><?php wp_nonce_field('fbgm_book_request'); ?>
          <label>Book title <input name="book_title" required maxlength="180"></label>
          <label>Author (optional) <input name="author" maxlength="180"></label>
          <label>Your name <input name="name" required maxlength="120"></label>
          <label>Email <input type="email" name="email" required maxlength="190"></label>
          <label>Notes <textarea name="notes" maxlength="1500"></textarea></label>
          <button type="submit">Submit book request</button>
        </form>
        <?php if(isset($_GET['fb_request'])&&$_GET['fb_request']==='sent') echo '<p class="fb-core-success">Your request has been submitted.</p>'; return ob_get_clean();
    }
    public static function handle_request() {
        check_admin_referer('fbgm_book_request');
        $title=sanitize_text_field(wp_unslash($_POST['book_title']??''));$name=sanitize_text_field(wp_unslash($_POST['name']??''));$email=sanitize_email(wp_unslash($_POST['email']??''));
        if(!$title||!$name||!is_email($email)) wp_die('Please provide a valid title, name and email.');
        $id=wp_insert_post(['post_type'=>'fb_book_request','post_status'=>'private','post_title'=>$title,'post_content'=>sanitize_textarea_field(wp_unslash($_POST['notes']??''))],true);
        if(is_wp_error($id)) wp_die('Could not save request.');
        update_post_meta($id,'_fbgm_author',sanitize_text_field(wp_unslash($_POST['author']??'')));
        update_post_meta($id,'_fbgm_name',$name);update_post_meta($id,'_fbgm_email',$email);
        wp_mail(get_option('admin_email'),'New FreeBooks book request',"Title: $title\nRequester: $name\nEmail: $email");
        wp_safe_redirect(add_query_arg('fb_request','sent',wp_get_referer()?:home_url('/')));exit;
    }
    public static function stock_form_shortcode($atts=[]) {
        if(!function_exists('wc_get_product')) return '';
        $atts=shortcode_atts(['product_id'=>0],$atts);$id=absint($atts['product_id']);
        if(!$id&&is_singular('product'))$id=get_the_ID();
        $product=$id?wc_get_product($id):false;
        if(!$product||$product->is_in_stock())return '';
        ob_start(); ?>
        <form class="fb-core-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="fbgm_stock_alert"><input type="hidden" name="product_id" value="<?php echo $id; ?>"><?php wp_nonce_field('fbgm_stock_'.$id); ?>
          <label>Email me when available <input type="email" name="email" required maxlength="190"></label><button type="submit">Notify me</button>
        </form>
        <?php if(isset($_GET['fb_stock'])&&$_GET['fb_stock']==='saved')echo '<p class="fb-core-success">Your alert has been registered.</p>';return ob_get_clean();
    }
    public static function handle_stock_alert() {
        $id=absint($_POST['product_id']??0);check_admin_referer('fbgm_stock_'.$id);$email=sanitize_email(wp_unslash($_POST['email']??''));
        if(get_post_type($id)!=='product'||!is_email($email))wp_die('Invalid product or email.');
        $list=(array)get_post_meta($id,'_fbgm_stock_emails',true);if(!in_array($email,$list,true)){$list[]=$email;update_post_meta($id,'_fbgm_stock_emails',array_slice($list,-500));}
        wp_safe_redirect(add_query_arg('fb_stock','saved',wp_get_referer()?:get_permalink($id)));exit;
    }
    public static function notify_stock($product) {
        if(!is_object($product)||!method_exists($product,'get_id')||!$product->is_in_stock())return;
        $id=$product->get_id();$emails=(array)get_post_meta($id,'_fbgm_stock_emails',true);if(!$emails)return;
        $url=get_permalink($id);$sent=[];
        foreach($emails as $email) if(is_email($email)&&wp_mail($email,'A FreeBooks item is back in stock',"Good news — this item is available again: $url"))$sent[]=$email;
        if($sent){$remaining=array_values(array_diff($emails,$sent));if($remaining)update_post_meta($id,'_fbgm_stock_emails',$remaining);else delete_post_meta($id,'_fbgm_stock_emails');}
    }

    public static function request_metabox() {
        add_meta_box('fbgm_request_status','Request handling',[self::class,'request_box'],'fb_book_request','side','high');
    }
    public static function request_box($post) {
        wp_nonce_field('fbgm_save_request','fbgm_nonce');
        $status=get_post_status($post);
        $opts=['fb-request-new'=>'New','fb-request-review'=>'Under review','fb-request-found'=>'Found','fb-request-unavailable'=>'Unavailable','fb-request-fulfilled'=>'Fulfilled'];
        echo '<p><label for="fbgm_status">Status</label></p><select name="fbgm_status" id="fbgm_status" style="width:100%">';
        foreach($opts as $v=>$label)echo '<option value="'.esc_attr($v).'" '.selected($status,$v,false).'>'.esc_html($label).'</option>';
        echo '</select><p>Requester: '.esc_html(get_post_meta($post->ID,'_fbgm_name',true)).'</p><p>Email: '.esc_html(get_post_meta($post->ID,'_fbgm_email',true)).'</p><p>Author: '.esc_html(get_post_meta($post->ID,'_fbgm_author',true)).'</p>';
    }
    public static function save_request_status($post_id) {
        if(!isset($_POST['fbgm_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbgm_nonce'])),'fbgm_save_request'))return;
        if((defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||!current_user_can('edit_post',$post_id))return;
        $status=sanitize_key(wp_unslash($_POST['fbgm_status']??'fb-request-new'));
        $allowed=['fb-request-new','fb-request-review','fb-request-found','fb-request-unavailable','fb-request-fulfilled'];
        if(in_array($status,$allowed,true)&&get_post_status($post_id)!==$status){
            remove_action('save_post_fb_book_request',[self::class,'save_request_status']);
            wp_update_post(['ID'=>$post_id,'post_status'=>$status]);
            add_action('save_post_fb_book_request',[self::class,'save_request_status']);
        }
    }
    public static function request_columns($cols) {
        $out=[];foreach($cols as $k=>$v){$out[$k]=$v;if($k==='title'){$out['fbgm_email']='Requester email';$out['fbgm_status']='Request status';}}return $out;
    }
    public static function request_column($col,$id) {
        if($col==='fbgm_email')echo esc_html(get_post_meta($id,'_fbgm_email',true));
        if($col==='fbgm_status'){$s=get_post_status($id);$labels=['fb-request-new'=>'New','fb-request-review'=>'Under review','fb-request-found'=>'Found','fb-request-unavailable'=>'Unavailable','fb-request-fulfilled'=>'Fulfilled'];echo esc_html($labels[$s]??$s);}
    }
    public static function admin_menu() {
        add_submenu_page('edit.php?post_type=fb_book_request','Book Request Reports','Request Reports','manage_options','fbgm-reports',[self::class,'reports_page']);
    }
    public static function reports_page() {
        if(!current_user_can('manage_options'))return;
        $counts=wp_count_posts('fb_book_request');
        $labels=['fb-request-new'=>'New','fb-request-review'=>'Under review','fb-request-found'=>'Found','fb-request-unavailable'=>'Unavailable','fb-request-fulfilled'=>'Fulfilled'];
        echo '<div class="wrap"><h1>Book Request Reports</h1><table class="widefat striped" style="max-width:620px"><thead><tr><th>Status</th><th>Requests</th></tr></thead><tbody>';
        foreach($labels as $s=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)($counts->$s??0)).'</td></tr>';
        echo '</tbody></table><p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=fbgm_export_requests'),'fbgm_export_requests')).'">Export book requests CSV</a></p></div>';
    }
    public static function export_requests() {
        if(!current_user_can('manage_options'))wp_die('Not permitted.');check_admin_referer('fbgm_export_requests');
        $q=new WP_Query(['post_type'=>'fb_book_request','post_status'=>array_keys(get_post_stati()),'posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC']);
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=freebooks-book-requests-'.gmdate('Y-m-d').'.csv');
        $out=fopen('php://output','w');fputcsv($out,['Request ID','Date','Book title','Author','Requester','Email','Status','Notes']);
        foreach($q->posts as $p)fputcsv($out,[$p->ID,$p->post_date,$p->post_title,get_post_meta($p->ID,'_fbgm_author',true),get_post_meta($p->ID,'_fbgm_name',true),get_post_meta($p->ID,'_fbgm_email',true),$p->post_status,$p->post_content]);
        fclose($out);exit;
    }
}
