<?php
if (!defined('ABSPATH')) exit;
class FB_Community {
    public static function init() {
        add_action('init',[self::class,'register_types']);
        add_action('rest_api_init',[self::class,'register_rest_routes']);
        add_shortcode('freebooks_volunteer_form',[self::class,'form_shortcode']);
        add_shortcode('freebooks_volunteer_opportunities',[self::class,'opportunities_shortcode']);
        add_action('admin_post_nopriv_fbcm_apply',[self::class,'handle_application']);
        add_action('admin_post_fbcm_apply',[self::class,'handle_application']);
        add_action('add_meta_boxes',[self::class,'add_status_box']);
        add_action('save_post_fb_volunteer_application',[self::class,'save_status']);
        add_filter('manage_fb_volunteer_application_posts_columns',[self::class,'columns']);
        add_action('manage_fb_volunteer_application_posts_custom_column',[self::class,'column_value'],10,2);
        add_action('admin_menu',[self::class,'admin_menu']);
        add_action('admin_post_fbcm_export',[self::class,'export_csv']);
    }
    public static function register_rest_routes() {
        register_rest_route('freebooks/v1','/volunteer/apply',[
            'methods'=>'POST',
            'callback'=>[self::class,'rest_apply'],
            'permission_callback'=>'__return_true',
        ]);
    }
    public static function rest_apply($request) {
        $p=$request->get_json_params();
        if(!is_array($p)) return new WP_Error('fbcm_invalid','Please submit a valid application.',['status'=>400]);
        if(!empty($p['website_url'])) return new WP_Error('fbcm_invalid','The application could not be accepted.',['status'=>400]);
        $ip=sanitize_text_field($_SERVER['REMOTE_ADDR']??'unknown');
        $rate_key='fbcm_rest_'.hash_hmac('sha256',$ip,wp_salt('auth'));
        $attempts=(int)get_transient($rate_key);
        if($attempts>=5) return new WP_Error('fbcm_rate_limited','Too many applications from this connection. Please try again later.',['status'=>429]);
        set_transient($rate_key,$attempts+1,10*MINUTE_IN_SECONDS);
        $name=sanitize_text_field((string)($p['full_name']??''));
        $email=sanitize_email((string)($p['email']??''));
        $interest=sanitize_text_field((string)($p['interest']??''));
        if(!$name||mb_strlen($name)>120||!is_email($email)||mb_strlen($email)>190||!$interest||mb_strlen($interest)>120||empty($p['consent']))
            return new WP_Error('fbcm_invalid','Please complete your name, valid email, area of interest and consent.',['status'=>400]);
        $phone=mb_substr(sanitize_text_field((string)($p['phone']??'')),0,30);
        $location=mb_substr(sanitize_text_field((string)($p['location']??'')),0,120);
        $availability=mb_substr(sanitize_text_field((string)($p['availability']??'')),0,120);
        $message=mb_substr(sanitize_textarea_field((string)($p['message']??'')),0,1200);
        $content="Interest: $interest\nAvailability: $availability\nLocation: $location\nPhone: $phone\n\nMessage:\n$message";
        $id=wp_insert_post(['post_type'=>'fb_volunteer_application','post_status'=>'private','post_title'=>$name,'post_content'=>$content],true);
        if(is_wp_error($id)||!$id) return new WP_Error('fbcm_save_failed','We could not save your application. Please try again later.',['status'=>500]);
        update_post_meta($id,'_fbcm_email',$email);
        update_post_meta($id,'_fbcm_consent','1');
        update_post_meta($id,'_fbcm_consent_date',current_time('mysql'));
        update_post_meta($id,'_fbcm_status','new');
        $sent=wp_mail(get_option('admin_email'),'New FreeBooks volunteer application',"Name: $name\nEmail: $email\nInterest: $interest\nReview in WordPress > Volunteer Applications.");
        return new WP_REST_Response(['success'=>true,'message'=>'Thank you. Your volunteer application has been received.','notification_sent'=>(bool)$sent],201);
    }
    public static function register_types() {
        register_post_type('fb_volunteer_application',['labels'=>['name'=>'Volunteer Applications','singular_name'=>'Volunteer Application'],'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'supports'=>['title','editor'],'capability_type'=>'post','map_meta_cap'=>true,'menu_icon'=>'dashicons-groups']);
        register_post_type('fb_volunteer_opportunity',['labels'=>['name'=>'Volunteer Opportunities','singular_name'=>'Volunteer Opportunity','add_new_item'=>'Add Opportunity'],'public'=>true,'show_in_rest'=>true,'has_archive'=>false,'rewrite'=>['slug'=>'volunteer-opportunity'],'supports'=>['title','editor','excerpt'],'menu_icon'=>'dashicons-heart']);
    }
    public static function form_shortcode() {
        ob_start(); ?>
        <form class="fb-core-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="fbcm_apply"><?php wp_nonce_field('fbcm_apply','fbcm_nonce'); ?>
          <div class="fb-core-honeypot" aria-hidden="true"><label>Leave empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
          <label>Full name <input name="full_name" required maxlength="120" autocomplete="name"></label>
          <label>Email address <input type="email" name="email" required maxlength="190" autocomplete="email"></label>
          <label>Phone (optional) <input type="tel" name="phone" maxlength="30" autocomplete="tel"></label>
          <label>City / district <input name="location" maxlength="120"></label>
          <label>How would you like to help?
            <select name="interest" required><option value="">Select an area</option><option>Book sorting and cataloguing</option><option>Packing and dispatch</option><option>Collection and logistics</option><option>Digital and website support</option><option>Community outreach</option><option>Other</option></select>
          </label>
          <label>Availability <input name="availability" maxlength="120" placeholder="e.g. Weekends / 3 hours per week"></label>
          <label>Anything else? <textarea name="message" rows="4" maxlength="1200"></textarea></label>
          <label class="fb-core-consent"><input type="checkbox" name="consent" value="1" required> I agree that FreeBooks / SamagraShriSai Foundation may use these details to contact me about volunteering. I have read the privacy policy.</label>
          <button type="submit">Submit volunteer application</button>
        </form>
        <?php if(isset($_GET['fbcm_apply'])&&$_GET['fbcm_apply']==='sent')echo '<p class="fb-core-success">Thank you. Your volunteer application has been received.</p>';return ob_get_clean();
    }
    public static function handle_application() {
        if(!isset($_POST['fbcm_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbcm_nonce'])),'fbcm_apply'))wp_die('Please refresh the page and try again.');
        if(!empty($_POST['website_url']))wp_die('Unable to process this request.');
        $name=sanitize_text_field(wp_unslash($_POST['full_name']??''));$email=sanitize_email(wp_unslash($_POST['email']??''));$interest=sanitize_text_field(wp_unslash($_POST['interest']??''));
        if(!$name||!is_email($email)||!$interest||empty($_POST['consent']))wp_die('Please complete the required fields and consent.');
        $content="Interest: $interest\nAvailability: ".sanitize_text_field(wp_unslash($_POST['availability']??''))."\nLocation: ".sanitize_text_field(wp_unslash($_POST['location']??''))."\nPhone: ".sanitize_text_field(wp_unslash($_POST['phone']??''))."\n\nMessage:\n".sanitize_textarea_field(wp_unslash($_POST['message']??''));
        $id=wp_insert_post(['post_type'=>'fb_volunteer_application','post_status'=>'private','post_title'=>$name,'post_content'=>$content],true);
        if(is_wp_error($id)||!$id)wp_die('Unable to save your application.');
        update_post_meta($id,'_fbcm_email',$email);update_post_meta($id,'_fbcm_consent','1');update_post_meta($id,'_fbcm_consent_date',current_time('mysql'));
        wp_mail(get_option('admin_email'),'New FreeBooks volunteer application',"Name: $name\nEmail: $email\nInterest: $interest\nReview in WordPress > Volunteer Applications.");
        wp_safe_redirect(add_query_arg('fbcm_apply','sent',wp_get_referer()?:home_url('/')));exit;
    }
    public static function opportunities_shortcode($atts=[]) {
        $atts=shortcode_atts(['limit'=>12],$atts);
        $q=new WP_Query(['post_type'=>'fb_volunteer_opportunity','post_status'=>'publish','posts_per_page'=>min(30,max(1,absint($atts['limit']))),'orderby'=>'date','order'=>'DESC']);
        ob_start();echo '<div class="fb-core-grid">';
        if($q->have_posts())while($q->have_posts()){$q->the_post();echo '<article class="fb-core-card"><h3>'.esc_html(get_the_title()).'</h3><div>'.wp_kses_post(wpautop(get_the_content())).'</div></article>';}
        else echo '<p>Volunteer opportunities will be listed here soon.</p>';
        wp_reset_postdata();echo '</div>';return ob_get_clean();
    }
    public static function add_status_box(){add_meta_box('fbcm_status','Application review',[self::class,'status_box'],'fb_volunteer_application','side','high');}
    public static function status_box($post) {
        wp_nonce_field('fbcm_save_status','fbcm_status_nonce');$status=get_post_meta($post->ID,'_fbcm_status',true)?:'new';
        $opts=['new'=>'New','review'=>'Under review','contacted'=>'Contacted','accepted'=>'Accepted','declined'=>'Declined'];
        echo '<p><label for="fbcm_status">Status</label></p><select style="width:100%" name="fbcm_status" id="fbcm_status">';
        foreach($opts as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($status,$k,false).'>'.esc_html($v).'</option>';
        echo '</select><p>Email: '.esc_html(get_post_meta($post->ID,'_fbcm_email',true)).'</p><p>Consent recorded: '.(get_post_meta($post->ID,'_fbcm_consent',true)==='1'?'Yes':'No').'</p>';
    }
    public static function save_status($post_id) {
        if(!isset($_POST['fbcm_status_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbcm_status_nonce'])),'fbcm_save_status'))return;
        if((defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||!current_user_can('edit_post',$post_id))return;
        $s=sanitize_key(wp_unslash($_POST['fbcm_status']??'new'));if(in_array($s,['new','review','contacted','accepted','declined'],true))update_post_meta($post_id,'_fbcm_status',$s);
    }
    public static function columns($cols){$cols['fbcm_email']='Email';$cols['fbcm_status']='Status';return $cols;}
    public static function column_value($col,$id){
        if($col==='fbcm_email')echo esc_html(get_post_meta($id,'_fbcm_email',true));
        if($col==='fbcm_status'){$s=get_post_meta($id,'_fbcm_status',true)?:'new';$labels=['new'=>'New','review'=>'Under review','contacted'=>'Contacted','accepted'=>'Accepted','declined'=>'Declined'];echo esc_html($labels[$s]??$s);}
    }
    public static function admin_menu(){add_submenu_page('edit.php?post_type=fb_volunteer_application','Volunteer Reports','Volunteer Reports','manage_options','fbcm-reports',[self::class,'reports_page']);}
    public static function reports_page() {
        if(!current_user_can('manage_options'))return;
        $q=new WP_Query(['post_type'=>'fb_volunteer_application','post_status'=>array_keys(get_post_stati()),'posts_per_page'=>-1,'fields'=>'ids']);
        $counts=['new'=>0,'review'=>0,'contacted'=>0,'accepted'=>0,'declined'=>0];
        foreach($q->posts as $id){$s=get_post_meta($id,'_fbcm_status',true)?:'new';if(isset($counts[$s]))$counts[$s]++;}
        echo '<div class="wrap"><h1>Volunteer Reports</h1><table class="widefat striped" style="max-width:560px"><thead><tr><th>Status</th><th>Applications</th></tr></thead><tbody>';
        foreach(['new'=>'New','review'=>'Under review','contacted'=>'Contacted','accepted'=>'Accepted','declined'=>'Declined'] as $s=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html((string)$counts[$s]).'</td></tr>';
        echo '</tbody></table><p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=fbcm_export'),'fbcm_export')).'">Export applications CSV</a></p></div>';
    }
    public static function export_csv() {
        if(!current_user_can('manage_options'))wp_die('Not permitted.');check_admin_referer('fbcm_export');
        $q=new WP_Query(['post_type'=>'fb_volunteer_application','post_status'=>array_keys(get_post_stati()),'posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC']);
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=freebooks-volunteers-'.gmdate('Y-m-d').'.csv');
        $out=fopen('php://output','w');fputcsv($out,['ID','Date','Name','Email','Status','Details','Consent date']);
        foreach($q->posts as $p)fputcsv($out,[$p->ID,$p->post_date,$p->post_title,get_post_meta($p->ID,'_fbcm_email',true),get_post_meta($p->ID,'_fbcm_status',true)?:'new',$p->post_content,get_post_meta($p->ID,'_fbcm_consent_date',true)]);
        fclose($out);exit;
    }
}
