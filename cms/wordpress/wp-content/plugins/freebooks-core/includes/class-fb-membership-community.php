<?php
if (!defined('ABSPATH')) exit;
/** Membership recognition, member book requests and moderated community updates. */
class FB_Membership_Community {
    const LEDGER = '_fb_member_points_ledger';
    public static function init() {
        add_action('init',[self::class,'register_types'],25);
        add_action('rest_api_init',[self::class,'routes']);
        add_action('woocommerce_order_status_completed',[self::class,'award_order_points']);
        add_action('save_post_fb_volunteer_application',[self::class,'award_volunteer_points'],40,3);
        add_action('admin_menu',[self::class,'admin_menu']);
        add_shortcode('freebooks_community_updates',[self::class,'community_shortcode']);
    }
    public static function register_types() {
        register_post_type('fb_community_update',[
            'labels'=>['name'=>'Community Updates','singular_name'=>'Community Update','add_new_item'=>'Add Community Update'],
            'public'=>true,'show_in_rest'=>true,'has_archive'=>false,'rewrite'=>['slug'=>'community-update'],
            'supports'=>['title','editor','excerpt','thumbnail'],'menu_icon'=>'dashicons-megaphone'
        ]);
    }
    public static function routes() {
        register_rest_route('freebooks/v1','/membership/me',['methods'=>'GET','callback'=>[self::class,'member_summary'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/membership/profile',['methods'=>'POST','callback'=>[self::class,'save_profile'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/membership/book-requests',['methods'=>['GET','POST'],'callback'=>[self::class,'book_requests'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/community/updates',['methods'=>'GET','callback'=>[self::class,'updates'],'permission_callback'=>'__return_true']);
    }
    private static function current_user($request) {
        $header=(string)$request->get_header('authorization');
        if(!preg_match('/^Bearer\s+([a-f0-9]{64})$/i',$header,$m)) return new WP_Error('fb_auth','Please sign in to access membership features.',['status'=>401]);
        $hash=hash('sha256',$m[1]); $uid=absint(get_transient('fb_account_token_'.$hash));
        if(!$uid) return new WP_Error('fb_auth','Your session has expired. Please sign in again.',['status'=>401]);
        $rows=get_user_meta($uid,FB_Accounts::TOKEN_META,true); $valid=false;
        foreach((array)$rows as $row) if(!empty($row['hash'])&&hash_equals((string)$row['hash'],$hash)&&absint($row['expires']??0)>time()){$valid=true;break;}
        $user=$valid?get_user_by('id',$uid):false;
        return $user?:new WP_Error('fb_auth','Your session has expired. Please sign in again.',['status'=>401]);
    }
    private static function ledger($user_id) { $v=get_user_meta($user_id,self::LEDGER,true);return is_array($v)?$v:[]; }
    private static function points($user_id) { $sum=0;foreach(self::ledger($user_id) as $row)$sum+=(int)($row['points']??0);return max(0,$sum); }
    private static function level($points) { if($points>=500)return 'Community Champion';if($points>=100)return 'Active Reader';return 'Reader'; }
    private static function add_points($user_id,$points,$reason,$reference) {
        $user_id=absint($user_id);$points=absint($points);if(!$user_id||!$points)return;
        $rows=self::ledger($user_id);foreach($rows as $row)if(($row['reference']??'')===(string)$reference)return;
        $rows[]=['points'=>$points,'reason'=>sanitize_text_field($reason),'reference'=>sanitize_text_field((string)$reference),'date'=>current_time('mysql')];
        update_user_meta($user_id,self::LEDGER,array_slice($rows,-100));
    }
    public static function award_order_points($order_id) {
        if(!function_exists('wc_get_order'))return;$order=wc_get_order($order_id);if(!$order)return;$uid=absint($order->get_customer_id());if(!$uid)return;
        $amount=(float)$order->get_total();$points=max(1,(int)floor($amount/100));
        self::add_points($uid,$points,'Completed purchase','order-'.$order_id);
    }
    public static function award_volunteer_points($post_id,$post=null,$update=false) {
        if(wp_is_post_revision($post_id)||get_post_type($post_id)!=='fb_volunteer_application')return;
        $status=get_post_meta($post_id,'_fbcm_status',true);if($status!=='accepted'||get_post_meta($post_id,'_fb_member_points_awarded',true))return;
        $email=sanitize_email(get_post_meta($post_id,'_fbcm_email',true));$user=$email?get_user_by('email',$email):false;
        if($user){self::add_points($user->ID,25,'Volunteer application accepted','volunteer-'.$post_id);update_post_meta($post_id,'_fb_member_points_awarded','1');}
    }
    public static function member_summary($request) {
        $u=self::current_user($request);if(is_wp_error($u))return $u;
        $ledger=array_reverse(self::ledger($u->ID));$requests=get_posts(['post_type'=>'fb_book_request','post_status'=>array_keys(get_post_stati()),'numberposts'=>30,'meta_key'=>'_fb_member_user_id','meta_value'=>$u->ID,'orderby'=>'date','order'=>'DESC']);
        $r=[];foreach($requests as $p)$r[]=['id'=>$p->ID,'title'=>get_the_title($p),'status'=>get_post_status($p),'date'=>$p->post_date];
        $points=self::points($u->ID);
        return rest_ensure_response(['membership'=>['name'=>$u->display_name,'email'=>$u->user_email,'points'=>$points,'level'=>self::level($points),'next_level_points'=>$points<100?100:($points<500?500:null),'interests'=>(array)get_user_meta($u->ID,'_fb_reading_interests',true),'ledger'=>array_slice($ledger,0,20)],'book_requests'=>$r]);
    }
    public static function save_profile($request) {
        $u=self::current_user($request);if(is_wp_error($u))return $u;
        $interests=array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)$request->get_param('interests'))))),0,12);
        update_user_meta($u->ID,'_fb_reading_interests',$interests);
        $bio=sanitize_textarea_field($request->get_param('reading_bio'));update_user_meta($u->ID,'_fb_reading_bio',mb_substr($bio,0,500));
        return rest_ensure_response(['success'=>true,'interests'=>$interests,'reading_bio'=>$bio]);
    }
    public static function book_requests($request) {
        $u=self::current_user($request);if(is_wp_error($u))return $u;
        if($request->get_method()==='GET') {
            $posts=get_posts(['post_type'=>'fb_book_request','post_status'=>array_keys(get_post_stati()),'numberposts'=>30,'meta_key'=>'_fb_member_user_id','meta_value'=>$u->ID,'orderby'=>'date','order'=>'DESC']);$out=[];
            foreach($posts as $p)$out[]=['id'=>$p->ID,'title'=>get_the_title($p),'status'=>get_post_status($p),'date'=>$p->post_date];
            return rest_ensure_response(['requests'=>$out]);
        }
        $title=sanitize_text_field($request->get_param('title'));$author=sanitize_text_field($request->get_param('author'));$notes=sanitize_textarea_field($request->get_param('notes'));
        if(!$title||mb_strlen($title)>180)return new WP_Error('fb_request_invalid','Enter a book title (maximum 180 characters).',['status'=>400]);
        $id=wp_insert_post(['post_type'=>'fb_book_request','post_status'=>'fb-request-new','post_title'=>$title,'post_content'=>$notes],true);
        if(is_wp_error($id))return $id;
        update_post_meta($id,'_fbgm_name',$u->display_name);update_post_meta($id,'_fbgm_email',$u->user_email);update_post_meta($id,'_fbgm_author',$author);update_post_meta($id,'_fb_member_user_id',$u->ID);
        return new WP_REST_Response(['success'=>true,'request'=>['id'=>$id,'title'=>$title,'status'=>'fb-request-new']],201);
    }
    public static function updates($request) {
        $q=new WP_Query(['post_type'=>'fb_community_update','post_status'=>'publish','posts_per_page'=>min(20,max(1,absint($request->get_param('per_page')?:8))),'orderby'=>'date','order'=>'DESC']);$items=[];
        foreach($q->posts as $p)$items[]=['id'=>$p->ID,'title'=>get_the_title($p),'excerpt'=>wp_strip_all_tags(get_the_excerpt($p)),'content'=>wp_kses_post(apply_filters('the_content',$p->post_content)),'date'=>get_the_date('c',$p),'url'=>get_permalink($p),'image'=>get_the_post_thumbnail_url($p,'large')?:null];
        return rest_ensure_response(['updates'=>$items]);
    }
    public static function community_shortcode($atts=[]) {
        $q=new WP_Query(['post_type'=>'fb_community_update','post_status'=>'publish','posts_per_page'=>8,'orderby'=>'date','order'=>'DESC']);ob_start();echo '<div class="fb-core-grid">';
        if($q->have_posts())while($q->have_posts()){$q->the_post();echo '<article class="fb-core-card"><h3>'.esc_html(get_the_title()).'</h3><p>'.esc_html(wp_trim_words(get_the_excerpt()?:get_the_content(),28)).'</p></article>';}
        else echo '<p>Community stories and reading activities will appear here soon.</p>';wp_reset_postdata();echo '</div>';return ob_get_clean();
    }
    public static function admin_menu() { add_submenu_page('edit.php?post_type=fb_community_update','Membership Overview','Membership Overview','manage_options','fb-membership-overview',[self::class,'admin_page']); }
    public static function admin_page() {
        if(!current_user_can('manage_options'))return;$users=get_users(['meta_key'=>self::LEDGER,'number'=>200,'fields'=>['ID','display_name','user_email']]);
        echo '<div class="wrap"><h1>Membership & Community</h1><p>Recognition points are non-cash, non-transferable participation indicators. They are not a donation receipt, discount balance or monetary value.</p><table class="widefat striped"><thead><tr><th>Member</th><th>Email</th><th>Points</th><th>Level</th></tr></thead><tbody>';
        foreach($users as $u){$points=self::points($u->ID);echo '<tr><td>'.esc_html($u->display_name).'</td><td>'.esc_html($u->user_email).'</td><td>'.esc_html((string)$points).'</td><td>'.esc_html(self::level($points)).'</td></tr>';}
        if(!$users)echo '<tr><td colspan="4">No members with points yet.</td></tr>';echo '</tbody></table><p>Manage public community posts under Community Updates. Book requests remain in the Book Requests admin list.</p></div>';
    }
}
