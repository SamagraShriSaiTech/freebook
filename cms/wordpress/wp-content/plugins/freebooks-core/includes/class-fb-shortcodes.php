<?php
if (!defined('ABSPATH')) exit;
class FB_Shortcodes {
    public static function donation_form() {
        $done=isset($_GET['fb_submitted']) && $_GET['fb_submitted']==='1';
        $html='<div class="fb-donation-form" style="max-width:760px;padding:22px;border:1px solid #dce7df;border-radius:14px;background:#fff">';
        if ($done) return $html.'<h2>Thank you for donating!</h2><p>Your reference number is <strong>'.esc_html(sanitize_text_field(wp_unslash($_GET['ref']??''))).'</strong>. Our team will review your submission.</p></div>';
        $html.='<h2>Donate books and learning materials</h2><p>Share books, notes, magazines, journals or stationery with another learner.</p>';
        $html.='<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fb_submit_donation">';
        $html.=wp_nonce_field('fb_public_donation','fb_donation_nonce',true,false);
        $settings=(array)get_option('freebooks_settings',[]);
        $html.='<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label>Leave this field empty<input type="text" name="fb_website" tabindex="-1" autocomplete="off"></label></div>';
        if (!empty($settings['turnstile_site_key']) && !empty($settings['turnstile_secret_key'])) { $html.='<div class="cf-turnstile" data-sitekey="'.esc_attr($settings['turnstile_site_key']).'"></div><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'; }
        $html.='<p><label>Full name<br><input required name="donor_name" type="text" maxlength="120" style="width:100%"></label></p>';
        $html.='<p><label>Email<br><input required name="donor_email" type="email" maxlength="190" style="width:100%"></label></p>';
        $html.='<p><label>Phone<br><input required name="donor_phone" type="tel" maxlength="30" style="width:100%"></label></p>';
        $html.='<p><label>City<br><input name="donor_city" type="text" maxlength="100" style="width:100%"></label></p>';
        $html.='<hr><h3>First item</h3><p><label>Category<br><select name="item_category" required><option value="">Choose category</option><option>Books</option><option>Notes</option><option>Magazines</option><option>Journals</option><option>Stationery</option></select></label></p>';
        $html.='<p><label>Item title / description<br><input required name="item_title" maxlength="180" style="width:100%"></label></p>';
        $html.='<p><label>Quantity<br><input required name="item_quantity" type="number" min="1" max="500" value="1"></label></p>';
        $html.='<p><label>Condition<br><select name="item_condition"><option value="">Select</option><option>New</option><option>Like new</option><option>Good</option><option>Fair</option><option>Used</option></select></label></p>';
        $html.='<p><label>Author (if applicable)<br><input name="item_author" maxlength="120"></label></p><p><label>ISBN (if applicable)<br><input name="item_isbn" maxlength="30"></label></p>';
        $html.='<p><label>Additional details<br><textarea name="item_details" rows="3" style="width:100%"></textarea></label></p>';
        $html.='<p><label>Handover preference<br><select required name="handover"><option value="">Choose</option><option value="drop_off">I can drop off</option><option value="pickup">Please contact me about pickup</option><option value="discuss">Discuss with me</option></select></label></p>';
        $html.='<p><label>Item photos (up to 3; JPG, PNG or WebP; max 5 MB each)<br><input type="file" name="fb_photos[]" accept="image/jpeg,image/png,image/webp" multiple></label></p>';
        $html.='<p><label>Message (optional)<br><textarea name="donor_message" rows="3" style="width:100%"></textarea></label></p>';
        $html.='<p><label><input required type="checkbox" name="donor_consent" value="1"> I agree that FreeBooks may contact me about this donation and use the submitted details to review it.</label></p>';
        $html.='<p><button type="submit" style="background:#176b55;color:white;border:0;padding:12px 22px;border-radius:8px;cursor:pointer">Submit donation</button></p></form></div>';
        return $html;
    }
    public static function handle_donation() {
        if (!empty($_POST['fb_website'])) wp_die('Submission could not be accepted.',400);
        if (!isset($_POST['fb_donation_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fb_donation_nonce'])),'fb_public_donation')) wp_die('Security check failed.',403);
        if (!FB_Governance::verify_turnstile(sanitize_text_field(wp_unslash($_POST['cf-turnstile-response']??'')))) wp_die('Please complete the security verification and try again.',403);
        $guard=FB_Governance::check_submission_guard();
        if (is_wp_error($guard)) wp_die(esc_html($guard->get_error_message()),429);
        $name=sanitize_text_field(wp_unslash($_POST['donor_name']??''));
        $email=sanitize_email(wp_unslash($_POST['donor_email']??''));
        $phone=sanitize_text_field(wp_unslash($_POST['donor_phone']??''));
        $title=sanitize_text_field(wp_unslash($_POST['item_title']??''));
        if (!$name || !is_email($email) || !$phone || !$title || empty($_POST['donor_consent'])) wp_die('Please complete all required fields.',400);
        $duplicate=FB_Governance::is_recent_duplicate($email,$title);
        if ($duplicate) wp_die('A similar donation was submitted recently using this email. Please contact the team if you believe this is an error.',409);
        $photos=[];
        if (!empty($_FILES['fb_photos']['name'][0])) {
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
            require_once ABSPATH.'wp-admin/includes/media.php';
            $files=$_FILES['fb_photos']; $total=min(count($files['name']),3);
            for($i=0;$i<$total;$i++) {
                if ((int)$files['size'][$i] > 5*1024*1024) continue;
                $single=['name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]];
                if ($single['error']!==UPLOAD_ERR_OK) continue;
                $check=wp_check_filetype_and_ext($single['tmp_name'],$single['name']);
                if (!in_array($check['type']??'', ['image/jpeg','image/png','image/webp'],true)) continue;
                $overrides=['test_form'=>false,'mimes'=>['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']];
                $upload=wp_handle_upload($single,$overrides);
                if (!empty($upload['file'])) {
                    $attachment=wp_insert_attachment(['post_mime_type'=>$upload['type'],'post_title'=>sanitize_file_name(pathinfo($upload['file'],PATHINFO_FILENAME)),'post_status'=>'private'], $upload['file']);
                    if ($attachment && !is_wp_error($attachment)) {
                        wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$upload['file']));
                        update_post_meta($attachment,'_fb_private_donation_upload',1);
                        $photos[]=$attachment;
                    }
                }
            }
        }
        $payload=[
            'donor'=>['name'=>$name,'email'=>$email,'phone'=>$phone,'city'=>sanitize_text_field(wp_unslash($_POST['donor_city']??''))],
            'items'=>[[
                'category'=>sanitize_text_field(wp_unslash($_POST['item_category']??'')),
                'title'=>$title,'quantity'=>max(1,min(500,absint($_POST['item_quantity']??1))),
                'condition'=>sanitize_text_field(wp_unslash($_POST['item_condition']??'')),
                'author'=>sanitize_text_field(wp_unslash($_POST['item_author']??'')),
                'isbn'=>sanitize_text_field(wp_unslash($_POST['item_isbn']??'')),
                'details'=>sanitize_textarea_field(wp_unslash($_POST['item_details']??''))
            ]],
            'handover'=>sanitize_text_field(wp_unslash($_POST['handover']??'')),
            'message'=>sanitize_textarea_field(wp_unslash($_POST['donor_message']??'')),
            'photos'=>$photos
        ];
        $created=FB_Donations::create($payload);
        if (is_wp_error($created)) wp_die(esc_html($created->get_error_message()),500);
        FB_Governance::record_submission($email,$title,(int)$created['id']);
        $back=wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg(['fb_submitted'=>'1','ref'=>$created['reference']],$back)); exit;
    }
    public static function tracking_form() {
        $html='<div class="fb-tracking-form" style="max-width:560px;padding:22px;border:1px solid #dce7df;border-radius:14px;background:#fff"><h2>Track your donation</h2>';
        $html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fb_track_donation">'.wp_nonce_field('fb_track_donation','fb_track_nonce',true,false);
        $html.='<p><label>Donation reference<br><input required name="reference" placeholder="FB-YYMM-XXXXXX" style="width:100%"></label></p>';
        $html.='<p><label>Email used for donation<br><input required type="email" name="email" style="width:100%"></label></p>';
        $html.='<p><label>Phone used for donation<br><input required name="phone" style="width:100%"></label></p>';
        $html.='<p><label>Email verification code (enter after requesting a code)<br><input name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" style="width:100%"></label></p>';
        $html.='<p><small>First submit your reference, email and phone. We will email a one-time code; enter it here and submit again.</small></p>';
        $html.='<button type="submit" style="background:#176b55;color:white;border:0;padding:12px 22px;border-radius:8px">Check status</button></form></div>';
        return $html;
    }
    public static function handle_tracking() {
        if (!isset($_POST['fb_track_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fb_track_nonce'])),'fb_track_donation')) wp_die('Security check failed.',403);
        $ref=sanitize_text_field(wp_unslash($_POST['reference']??'')); $email=sanitize_email(wp_unslash($_POST['email']??'')); $phone=sanitize_text_field(wp_unslash($_POST['phone']??''));
        $ip=$_SERVER['REMOTE_ADDR']??'unknown'; $rate_key='fb_track_rate_'.hash('sha256',$ip.'|'.$ref); $attempts=(int)get_transient($rate_key);
        if($attempts>=8) wp_die('Too many verification attempts. Please try again later.',429);
        set_transient($rate_key,$attempts+1,15*MINUTE_IN_SECONDS);
        $ids=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>1,'meta_key'=>'_fb_reference','meta_value'=>$ref,'fields'=>'ids']);
        $message='We could not verify those details. Please check your reference, email and phone.';
        $verified_id=0;
        if ($ids) {
            $id=(int)$ids[0]; $d=get_post_meta($id,'_fb_donor',true);
            $a=hash_equals(strtolower((string)($d['email']??'')),strtolower($email));
            $b=hash_equals(preg_replace('/\D+/','',(string)($d['phone']??'')),preg_replace('/\D+/','',$phone));
            if ($a && $b) {
                $otp_key='fb_track_otp_'.hash('sha256',$ref.'|'.strtolower($email));
                $submitted_otp=preg_replace('/\D+/','',(string)wp_unslash($_POST['otp']??''));
                if ($submitted_otp==='') {
                    $cooldown_key=$otp_key.'_cooldown';
                    if (get_transient($cooldown_key)) {
                        $message='A verification code was sent recently. Please wait one minute before requesting another code.';
                    } else {
                        $otp=(string)wp_rand(100000,999999);
                        set_transient($otp_key,wp_hash_password($otp),10*MINUTE_IN_SECONDS);
                        set_transient($cooldown_key,1,60);
                        $sent=wp_mail($email,'Your FreeBooks donation verification code','Your one-time verification code is '.$otp.'. It expires in 10 minutes. If you did not request this, you can ignore this email.');
                        FB_Donations::record_communication($id,'otp_email',$sent?'sent':'failed',$sent?'Donation tracking verification code email handed off.':'Could not hand off donation tracking verification code email.','tracking',$email);
                        $message=$sent?'A one-time code has been sent to your registered email address. Enter it in the verification code field and submit again.':'We could not send a verification email. Please contact FreeBooks support.';
                    }
                } else {
                    $hash=get_transient($otp_key);
                    if($hash && wp_check_password($submitted_otp,$hash)) {
                        delete_transient($otp_key); delete_transient($rate_key);
                        $verified_id=$id;
                        $message='Your donation details have been verified.';
                    } else $message='The verification code is invalid or expired. Submit again to request a new code.';
                }
            }
        }
        if ($verified_id) {
            $status=get_post_meta($verified_id,'_fb_status',true)?:'submitted';
            $audit=get_post_meta($verified_id,'_fb_audit',true);
            $audit=is_array($audit)?$audit:[];
            $labels=['submitted'=>'Submission received','approved'=>'Approved','rejected'=>'Not accepted','more_info'=>'More information requested','pickup_scheduled'=>'Pickup scheduled','received'=>'Items received','listed'=>'Catalogue listing published','sold'=>'Sold'];
            $content='<div style="font-family:Arial;max-width:680px;margin:40px auto;padding:28px;border:1px solid #dce7df;border-radius:14px;color:#24332f"><h2 style="color:#176b55">FreeBooks donation tracking</h2><p>'.esc_html($message).'</p><p><strong>Reference:</strong> '.esc_html($ref).'<br><strong>Current status:</strong> '.esc_html($labels[$status]??ucwords(str_replace('_',' ',$status))).'</p><h3>Status history</h3><ol>';
            foreach($audit as $event) {
                $event_key=sanitize_key($event['event']??'');
                $event_label=$labels[$event_key]??ucwords(str_replace('_',' ',$event_key));
                $content.='<li style="margin:10px 0"><strong>'.esc_html($event_label).'</strong> — '.esc_html($event['at']??'');
                if (!empty($event['reason'])) $content.='<br>'.esc_html($event['reason']);
                $content.='</li>';
            }
            $content.='</ol><p>For questions about this donation, contact the FreeBooks team using the contact details on our website.</p><p><a href="'.esc_url(wp_get_referer()?:home_url('/')).'">Return to tracking form</a></p></div>';
            wp_die($content,'Donation status',['response'=>200]);
        }
        wp_die('<div style="font-family:Arial;max-width:600px;margin:50px auto;padding:24px;border:1px solid #ddd;border-radius:12px"><h2>FreeBooks donation tracking</h2><p>'.esc_html($message).'</p><p><a href="'.esc_url(wp_get_referer()?:home_url('/')).'">Return to tracking form</a></p></div>','Donation status',['response'=>200]);
    }
}
