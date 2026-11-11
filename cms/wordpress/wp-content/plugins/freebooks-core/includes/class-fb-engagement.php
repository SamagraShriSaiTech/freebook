<?php
if (!defined('ABSPATH')) exit;
class FB_Engagement {
    public static function init() {
        add_action('init',[self::class,'register_types']);
        add_action('rest_api_init',[self::class,'register_routes']);
        add_shortcode('freebooks_newsletter_form',[self::class,'newsletter_shortcode']);
        add_shortcode('freebooks_faqs',[self::class,'faq_shortcode']);
        add_shortcode('freebooks_contact_links',[self::class,'contact_shortcode']);
        add_action('admin_post_nopriv_fbem_subscribe',[self::class,'subscribe']);
        add_action('admin_post_fbem_subscribe',[self::class,'subscribe']);
        add_action('admin_post_nopriv_fbem_unsubscribe',[self::class,'unsubscribe']);
        add_action('admin_post_fbem_unsubscribe',[self::class,'unsubscribe']);
        add_action('admin_post_fbem_export_subscribers',[self::class,'export_subscribers']);
        add_action('admin_menu',[self::class,'admin_menu']);
    }

    public static function register_routes() {
        register_rest_route('freebooks/v1','/newsletter/subscribe',[
            'methods'=>'POST','callback'=>[self::class,'rest_subscribe'],'permission_callback'=>'__return_true'
        ]);
    }

    /** Public signup endpoint. Stores consent and sends a signed unsubscribe link. */
    public static function rest_subscribe($request) {
        $email=sanitize_email($request->get_param('email'));
        $consent=$request->get_param('consent');
        $honeypot=sanitize_text_field($request->get_param('website'));
        if ($honeypot) return rest_ensure_response(['success'=>true,'message'=>'If the address can be subscribed, you will receive an email shortly.']);
        if (!is_email($email) || !$consent) return new WP_Error('fbem_invalid','Enter a valid email address and provide consent.',['status'=>400]);
        $email=strtolower(trim($email));
        $throttle='fbem_signup_'.hash('sha256',$email);
        if (get_transient($throttle)) return new WP_Error('fbem_rate','Please wait a little before trying again.',['status'=>429]);
        set_transient($throttle,1,90);
        $existing=get_posts(['post_type'=>'fbem_subscriber','post_status'=>['private','publish'],'numberposts'=>1,'meta_key'=>'_fbem_email','meta_value'=>$email,'fields'=>'ids']);
        if ($existing) return rest_ensure_response(['success'=>true,'message'=>'If the address can be subscribed, you will receive an email shortly.']);
        $token=wp_generate_password(40,false,false);
        $id=wp_insert_post(['post_type'=>'fbem_subscriber','post_status'=>'private','post_title'=>$email],true);
        if (is_wp_error($id) || !$id) return new WP_Error('fbem_save','Your subscription could not be saved. Please try again.',['status'=>500]);
        update_post_meta($id,'_fbem_email',$email);
        update_post_meta($id,'_fbem_token_hash',wp_hash($token));
        update_post_meta($id,'_fbem_consent','1');
        update_post_meta($id,'_fbem_consent_date',current_time('mysql'));
        update_post_meta($id,'_fbem_consent_source','public_frontend');
        update_post_meta($id,'_fbem_consent_version','1.0');
        $unsubscribe=add_query_arg(['action'=>'fbem_unsubscribe','id'=>$id,'token'=>$token],admin_url('admin-post.php'));
        $body="You are subscribed to FreeBooks email updates.\n\nWe will share occasional news about book reuse, new catalogue additions and community activities.\n\nYou can unsubscribe at any time using this secure link:\n".$unsubscribe."\n\nFreeBooks — SamagraShriSai Foundation";
        if (!wp_mail($email,'Welcome to FreeBooks updates',$body,['Content-Type: text/plain; charset=UTF-8'])) {
            wp_delete_post($id,true);
            return new WP_Error('fbem_mail','We could not send the subscription email. Please try again later.',['status'=>503]);
        }
        return rest_ensure_response(['success'=>true,'message'=>'Thank you. Your subscription has been recorded. Please check your email for your unsubscribe link.']);
    }

    public static function register_types() {
        register_post_type('fbem_subscriber',['labels'=>['name'=>'Newsletter Subscribers','singular_name'=>'Newsletter Subscriber'],'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'supports'=>['title'],'capability_type'=>'post','map_meta_cap'=>true,'menu_icon'=>'dashicons-email']);
        register_post_type('fbem_faq',['labels'=>['name'=>'FreeBooks FAQs','singular_name'=>'FAQ','add_new_item'=>'Add FAQ'],'public'=>true,'show_in_rest'=>true,'has_archive'=>false,'rewrite'=>['slug'=>'freebooks-faq'],'supports'=>['title','editor','page-attributes'],'menu_icon'=>'dashicons-editor-help']);
    }
    public static function sanitize_settings($v) {
        $v=is_array($v)?$v:[];$keys=['contact_phone','contact_email','contact_address','whatsapp','facebook','instagram','youtube','linkedin','foundation_name','proceeds_copy'];$o=[];
        foreach($keys as $k){$raw=trim((string)($v[$k]??''));if(in_array($k,['facebook','instagram','youtube','linkedin','whatsapp'],true))$o[$k]=$raw?esc_url_raw($raw,['http','https']):'';elseif($k==='contact_email')$o[$k]=sanitize_email($raw);elseif($k==='contact_phone')$o[$k]=preg_replace('/[^0-9+(). -]/','',$raw);else $o[$k]=sanitize_textarea_field($raw);}
        return $o;
    }
    public static function newsletter_shortcode() {
        ob_start(); ?>
        <form class="fb-core-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="fbem_subscribe"><?php wp_nonce_field('fbem_subscribe','fbem_nonce'); ?>
          <div class="fb-core-honeypot" aria-hidden="true"><label>Leave empty<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>
          <label>Email for FreeBooks updates <input type="email" name="email" required maxlength="190" autocomplete="email"></label>
          <label class="fb-core-consent"><input type="checkbox" name="consent" value="1" required> I agree to receive FreeBooks updates by email. I can unsubscribe at any time.</label>
          <button type="submit">Subscribe</button>
        </form>
        <?php if(isset($_GET['fbem_newsletter'])): ?><p class="fb-core-success"><?php echo $_GET['fbem_newsletter']==='success'?'Thank you. Your subscription has been recorded.':($_GET['fbem_newsletter']==='exists'?'This email is already subscribed.':'We could not process your request.'); ?></p><?php endif;
        return ob_get_clean();
    }
    public static function subscribe() {
        if(!isset($_POST['fbem_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbem_nonce'])),'fbem_subscribe'))wp_die('Please refresh the page and try again.');
        if(!empty($_POST['company_website']))wp_die('Unable to process this request.');
        $email=sanitize_email(wp_unslash($_POST['email']??''));if(!is_email($email)||empty($_POST['consent']))wp_die('Please enter a valid email and agree to the subscription.');
        $existing=get_posts(['post_type'=>'fbem_subscriber','post_status'=>['private','publish'],'numberposts'=>1,'meta_key'=>'_fbem_email','meta_value'=>$email]);
        if($existing){wp_safe_redirect(add_query_arg('fbem_newsletter','exists',wp_get_referer()?:home_url('/')));exit;}
        $token=wp_generate_password(40,false,false);$id=wp_insert_post(['post_type'=>'fbem_subscriber','post_status'=>'private','post_title'=>$email],true);
        if(is_wp_error($id)||!$id)wp_die('Unable to save your subscription.');
        update_post_meta($id,'_fbem_email',$email);update_post_meta($id,'_fbem_token_hash',wp_hash($token));update_post_meta($id,'_fbem_consent','1');update_post_meta($id,'_fbem_consent_date',current_time('mysql'));
        $url=add_query_arg(['action'=>'fbem_unsubscribe','id'=>$id,'token'=>$token],admin_url('admin-post.php'));
        if(!wp_mail($email,'Welcome to FreeBooks updates',"You are subscribed to FreeBooks updates.\n\nUnsubscribe at any time:\n".$url)){wp_delete_post($id,true);wp_die('The confirmation email could not be sent. Please try again later.');}
        wp_safe_redirect(add_query_arg('fbem_newsletter','success',wp_get_referer()?:home_url('/')));exit;
    }
    public static function unsubscribe() {
        $id=absint($_GET['id']??0);$token=sanitize_text_field(wp_unslash($_GET['token']??''));$stored=get_post_meta($id,'_fbem_token_hash',true);
        if(!$id||!$stored||!hash_equals($stored,wp_hash($token)))wp_die('This unsubscribe link is invalid or has expired.');
        wp_delete_post($id,true);wp_die('You have been unsubscribed from FreeBooks email updates. You may close this page.');
    }
    public static function faq_shortcode($atts=[]) {
        $atts=shortcode_atts(['limit'=>30],$atts);$q=new WP_Query(['post_type'=>'fbem_faq','post_status'=>'publish','posts_per_page'=>min(50,max(1,absint($atts['limit']))),'orderby'=>['menu_order'=>'ASC','date'=>'DESC']]);
        ob_start();echo '<div class="fb-core-faqs">';
        while($q->have_posts()){$q->the_post();echo '<details class="fb-core-faq"><summary>'.esc_html(get_the_title()).'</summary><div>'.wp_kses_post(wpautop(get_the_content())).'</div></details>';}
        wp_reset_postdata();if(!$q->found_posts)echo '<p>Frequently asked questions will be published here soon.</p>';echo '</div>';return ob_get_clean();
    }
    public static function contact_shortcode() {
        $s=(array)get_option('freebooks_settings',[]);$items=[];
        if(!empty($s['contact_phone']))$items[]='<a href="tel:'.esc_attr(preg_replace('/[^0-9+]/','',$s['contact_phone'])).'">Call: '.esc_html($s['contact_phone']).'</a>';
        if(!empty($s['contact_email']))$items[]='<a href="mailto:'.esc_attr($s['contact_email']).'">'.esc_html($s['contact_email']).'</a>';
        foreach(['whatsapp'=>'WhatsApp','facebook'=>'Facebook','instagram'=>'Instagram','youtube'=>'YouTube','linkedin'=>'LinkedIn'] as $k=>$label)if(!empty($s[$k]))$items[]='<a href="'.esc_url($s[$k]).'" target="_blank" rel="noopener noreferrer">'.esc_html($label).'</a>';
        if(!$items)return '<p>Contact details will be published here soon.</p>';
        return '<nav class="fb-core-contact" aria-label="FreeBooks contact and social links">'.implode(' <span aria-hidden="true">·</span> ',$items).'</nav>';
    }
    public static function admin_menu() {
        add_submenu_page('edit.php?post_type=fbem_subscriber','Newsletter Export','Export Subscribers','manage_options','fbem-export',[self::class,'export_page']);
    }
    public static function export_page() {
        if(!current_user_can('manage_options'))return;
        echo '<div class="wrap"><h1>Newsletter Subscribers</h1><p>Subscriber email addresses are private information. Restrict exports.</p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=fbem_export_subscribers'),'fbem_export_subscribers')).'">Download CSV</a></div>';
    }
    public static function export_subscribers() {
        if(!current_user_can('manage_options'))wp_die('Not permitted.');check_admin_referer('fbem_export_subscribers');
        $q=new WP_Query(['post_type'=>'fbem_subscriber','post_status'=>['private','publish'],'posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC']);
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=freebooks-subscribers-'.gmdate('Y-m-d').'.csv');
        $out=fopen('php://output','w');fputcsv($out,['Subscriber ID','Email','Consent date']);
        foreach($q->posts as $p)fputcsv($out,[$p->ID,get_post_meta($p->ID,'_fbem_email',true),get_post_meta($p->ID,'_fbem_consent_date',true)]);
        fclose($out);exit;
    }
}
