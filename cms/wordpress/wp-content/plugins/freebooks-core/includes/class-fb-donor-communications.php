<?php
if (!defined('ABSPATH')) exit;
class FB_Donor_Communications {
    public static function menu() {
        add_submenu_page(
            'edit.php?post_type='.FREEBOOKS_DONATION_CPT,
            'Donor communications',
            'Donor communications',
            'edit_fb_donations',
            'fb-donor-communications',
            [self::class,'page']
        );
    }
    public static function page() {
        if (!current_user_can('edit_fb_donations')) wp_die('Permission denied.');
        $ids=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>100,'orderby'=>'date','order'=>'DESC','fields'=>'ids']);
        echo '<div class="wrap"><h1>Donor communications</h1><p>Review recent email delivery hand-offs and resend the current donation status update when necessary. WordPress mail success does not guarantee inbox delivery.</p>';
        if (isset($_GET['fb_mail_result'])) echo '<div class="notice notice-'.($_GET['fb_mail_result']==='sent'?'success':'error').'"><p>'.($_GET['fb_mail_result']==='sent'?'Email handed off to the WordPress mail handler.':'Email hand-off failed. Check SMTP and the donor email address.').'</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>Reference</th><th>Donor</th><th>Current status</th><th>Latest email event</th><th>Action</th></tr></thead><tbody>';
        if (!$ids) echo '<tr><td colspan="5">No donation records found.</td></tr>';
        foreach ($ids as $id) {
            $donor=(array)get_post_meta($id,'_fb_donor',true);
            $log=(array)get_post_meta($id,'_fb_communication_log',true);
            $latest=null;
            foreach(array_reverse($log) as $entry) if (($entry['channel']??'')==='email') {$latest=$entry;break;}
            $status=get_post_meta($id,'_fb_status',true)?:'submitted';
            echo '<tr><td><strong>'.esc_html(get_post_meta($id,'_fb_reference',true)).'</strong></td>';
            echo '<td>'.esc_html($donor['name']??'').'<br>'.esc_html($donor['email']??'').'</td>';
            echo '<td>'.esc_html(ucwords(str_replace('_',' ',$status))).'</td>';
            echo '<td>'.($latest?esc_html(($latest['at']??'').' — '.($latest['result']??'').' — '.($latest['status']??'')):'No email event recorded').'</td>';
            echo '<td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fb_donor_resend_email"><input type="hidden" name="donation_id" value="'.esc_attr($id).'">';
            wp_nonce_field('fb_donor_resend_email_'.$id);
            submit_button('Resend current status','secondary','submit',false);
            echo '</form></td></tr>';
            if ($log) {
                echo '<tr><td colspan="5"><details><summary>Communication history ('.count($log).')</summary><ul>';
                foreach(array_slice(array_reverse($log),0,10) as $entry) echo '<li>'.esc_html(($entry['at']??'').' | '.($entry['channel']??'').' | '.($entry['result']??'').' | '.($entry['details']??'')).'</li>';
                echo '</ul></details></td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }
    public static function resend_email() {
        $id=absint($_POST['donation_id']??0);
        if (!$id || !current_user_can('edit_fb_donations')) wp_die('Permission denied.',403);
        check_admin_referer('fb_donor_resend_email_'.$id);
        $status=get_post_meta($id,'_fb_status',true)?:'submitted';
        $reason=(string)get_post_meta($id,'_fb_status_reason',true);
        $sent=FB_Donations::email_donor($id,$status,$reason);
        if (class_exists('FB_Governance')) FB_Governance::log('donor_status_email_resent',$id,$sent?'Current status email resent.':'Resend attempt failed.');
        wp_safe_redirect(add_query_arg(['page'=>'fb-donor-communications','fb_mail_result'=>$sent?'sent':'failed'],admin_url('edit.php?post_type='.FREEBOOKS_DONATION_CPT)));
        exit;
    }
}
