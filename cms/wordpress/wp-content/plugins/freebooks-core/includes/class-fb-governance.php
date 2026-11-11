<?php
if (!defined('ABSPATH')) exit;
class FB_Governance {
    const LOG_OPTION = 'fb_governance_audit_log';
    public static function init() {
        add_action('admin_menu',[self::class,'menu'],40);
        add_action('freebooks_donation_status_changed',[self::class,'status_changed'],10,2);
        add_action('admin_post_fb_governance_export',[self::class,'export_csv']);
    }
    public static function upgrade() {
        $v=(string)get_option('fb_governance_version','');
        if(version_compare($v,'1.0.0','<')) {
            self::add_caps(); update_option('fb_governance_version','1.0.0');
        }
    }
    public static function add_caps() {
        $admin=get_role('administrator');
        if($admin) foreach(['view_fb_audit_log','export_fb_reports','manage_fb_security'] as $cap) $admin->add_cap($cap);
        $mod=get_role('fb_moderator');
        if($mod) { $mod->add_cap('view_fb_audit_log'); $mod->add_cap('export_fb_reports'); }
    }
    public static function log($event,$donation_id=0,$details='') {
        $rows=get_option(self::LOG_OPTION,[]); if(!is_array($rows))$rows=[];
        $user=get_current_user_id(); $rows[]=[
            'time'=>current_time('mysql',true),'event'=>sanitize_key($event),'donation_id'=>absint($donation_id),
            'reference'=>$donation_id?sanitize_text_field((string)get_post_meta($donation_id,'_fb_reference',true)):'',
            'actor'=>$user?sanitize_text_field((string)(wp_get_current_user()->user_login??$user)):'system',
            'details'=>sanitize_textarea_field($details)
        ];
        if(count($rows)>500)$rows=array_slice($rows,-500);
        update_option(self::LOG_OPTION,$rows,false);
    }
    public static function status_changed($id,$status) { self::log('donation_'.$status,$id,'Donation status changed to '.$status); }
    public static function check_submission_guard() {
        $ip=$_SERVER['REMOTE_ADDR']??'unknown'; $key='fb_donate_guard_'.hash('sha256',$ip);
        $count=(int)get_transient($key); if($count>=5)return new WP_Error('rate_limited','Too many donation submissions from this connection. Please try again in 10 minutes.');
        set_transient($key,$count+1,10*MINUTE_IN_SECONDS); return true;
    }
    public static function is_recent_duplicate($email,$title) {
        $email=strtolower(trim((string)$email)); $title=strtolower(trim((string)$title)); if(!$email||!$title)return false;
        $ids=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>30,'date_query'=>[['after'=>'24 hours ago']],'fields'=>'ids','orderby'=>'date','order'=>'DESC']);
        foreach($ids as $id){$d=get_post_meta($id,'_fb_donor',true);$items=(array)get_post_meta($id,'_fb_items',true);if(strtolower((string)($d['email']??''))!==$email)continue;foreach($items as $item)if(strtolower(trim((string)($item['title']??'')))===$title)return true;}
        return false;
    }
    public static function record_submission($email,$title,$donation_id=0) { self::log('donation_submitted',$donation_id,'New public donation submission received.'); }
    private static function csv_safe($value) { $value=(string)$value; return preg_match('/^[\s]*[=+@\-]/u',$value) ? "'".$value : $value; }
    public static function verify_turnstile($token) {
        $settings=(array)get_option('freebooks_settings',[]);$secret=trim((string)($settings['turnstile_secret_key']??''));$site=trim((string)($settings['turnstile_site_key']??''));
        if(!$secret&&!$site)return true;
        if(!$secret||!$site||!$token)return false;
        $response=wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify',['timeout'=>8,'body'=>['secret'=>$secret,'response'=>$token,'remoteip'=>sanitize_text_field($_SERVER['REMOTE_ADDR']??'')]]);
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return false;
        $body=json_decode(wp_remote_retrieve_body($response),true);return !empty($body['success']);
    }
    public static function menu() {
        add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT,'Audit log','Audit log','view_fb_audit_log','fb-audit-log',[self::class,'audit_page']);
        add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT,'Reports','Reports & export','export_fb_reports','fb-reports',[self::class,'reports_page']);
    }
    public static function audit_page() {
        if(!current_user_can('view_fb_audit_log'))wp_die('Permission denied.');$rows=array_reverse((array)get_option(self::LOG_OPTION,[]));
        echo '<div class="wrap"><h1>FreeBooks audit log</h1><p>Latest 500 recorded donation and administration events. Avoid storing sensitive donor details in notes.</p><table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Event</th><th>Reference</th><th>Actor</th><th>Details</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="5">No audit events recorded yet.</td></tr>';
        foreach(array_slice($rows,0,250) as $r)echo '<tr><td>'.esc_html($r['time']??'').'</td><td>'.esc_html($r['event']??'').'</td><td>'.esc_html($r['reference']??'').'</td><td>'.esc_html($r['actor']??'').'</td><td>'.esc_html($r['details']??'').'</td></tr>';
        echo '</tbody></table></div>';
    }
    public static function reports_page() {
        if(!current_user_can('export_fb_reports'))wp_die('Permission denied.');$counts=array_fill_keys(FB_Donations::STATUSES,0);
        $ids=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>-1,'fields'=>'ids']);foreach($ids as $id){$s=get_post_meta($id,'_fb_status',true)?:'submitted';if(isset($counts[$s]))$counts[$s]++;}
        echo '<div class="wrap"><h1>FreeBooks reports</h1><p>Total donations: <strong>'.count($ids).'</strong></p><table class="widefat striped" style="max-width:620px"><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';foreach($counts as $s=>$n)echo '<tr><td>'.esc_html(ucwords(str_replace('_',' ',$s))).'</td><td>'.absint($n).'</td></tr>';echo '</tbody></table>';
        echo '<p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=fb_governance_export'),'fb_governance_export')).'">Download donations CSV</a></p><p>CSV includes donation reference, submission date, donor contact details, item summary, quantity, handover preference and current status. Store exports securely.</p></div>';
    }
    public static function export_csv() {
        if(!current_user_can('export_fb_reports')||!check_admin_referer('fb_governance_export'))wp_die('Not authorised.',403);
        $ids=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>-1,'fields'=>'ids','orderby'=>'date','order'=>'DESC']);
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=freebooks-donations-'.gmdate('Y-m-d').'.csv');$out=fopen('php://output','w');fputcsv($out,['Reference','Date','Donor','Email','Phone','City','Items','Quantity','Handover','Status']);
        foreach($ids as $id){$d=(array)get_post_meta($id,'_fb_donor',true);$items=(array)get_post_meta($id,'_fb_items',true);$titles=[];$qty=0;foreach($items as $it){$titles[]=(string)($it['title']??'');$qty+=absint($it['quantity']??1);}fputcsv($out,array_map([self::class,'csv_safe'],[get_post_meta($id,'_fb_reference',true),get_the_date('Y-m-d H:i:s',$id),$d['name']??'',$d['email']??'',$d['phone']??'',$d['city']??'',implode('; ',$titles),$qty,get_post_meta($id,'_fb_handover',true),get_post_meta($id,'_fb_status',true)]));}
        fclose($out);exit;
    }
}
