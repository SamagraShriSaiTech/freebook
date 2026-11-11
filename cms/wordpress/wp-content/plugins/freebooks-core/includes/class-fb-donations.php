<?php
if (!defined('ABSPATH')) exit;
class FB_Donations {
    const STATUSES = ['submitted','approved','rejected','more_info','pickup_scheduled','received','listed','sold'];
    public static function create($payload) {
        $donor=$payload['donor']; $items=array_map([self::class,'sanitize_item'],$payload['items']);
        $id=wp_insert_post(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','post_title'=>'Donation — '.sanitize_text_field($donor['name']).' — '.current_time('Y-m-d H:i')],true);
        if (is_wp_error($id)) return $id;
        $reference='FB-'.gmdate('ym').'-'.strtoupper(wp_generate_password(6,false,false));
        update_post_meta($id,'_fb_reference',$reference);
        update_post_meta($id,'_fb_status','submitted');
        update_post_meta($id,'_fb_donor',[
            'name'=>sanitize_text_field($donor['name']),'email'=>sanitize_email($donor['email']),
            'phone'=>sanitize_text_field($donor['phone']),'city'=>sanitize_text_field($donor['city']??''),
            'marketing_opt_in'=>!empty($donor['marketing_opt_in']) ? 1 : 0,
            'preferred_contact'=>in_array(($donor['preferred_contact']??''),['email','phone','whatsapp','none'],true) ? sanitize_key($donor['preferred_contact']) : 'none'
        ]);
        update_post_meta($id,'_fb_items',$items);
        update_post_meta($id,'_fb_handover',sanitize_text_field($payload['handover']??''));
        update_post_meta($id,'_fb_message',sanitize_textarea_field($payload['message']??''));
        update_post_meta($id,'_fb_photos',array_map('absint',$payload['photos']??[]));
        update_post_meta($id,'_fb_audit',[['event'=>'submitted','at'=>current_time('mysql',true),'actor'=>'guest']]);
        self::email_donor($id,'submitted');
        return ['id'=>$id,'reference'=>$reference];
    }
    public static function sanitize_item($i) {
        return [
            'category'=>sanitize_text_field($i['category']??''),'title'=>sanitize_text_field($i['title']??''),
            'quantity'=>max(1,absint($i['quantity']??1)),'condition'=>sanitize_text_field($i['condition']??''),
            'details'=>sanitize_textarea_field($i['details']??''),'isbn'=>sanitize_text_field($i['isbn']??''),
            'author'=>sanitize_text_field($i['author']??''),'publisher'=>sanitize_text_field($i['publisher']??''),
            'language'=>sanitize_text_field($i['language']??''),'classSubject'=>sanitize_text_field($i['classSubject']??''),
            'format'=>sanitize_text_field($i['format']??''),'pages'=>sanitize_text_field($i['pages']??''),
            'issueYear'=>sanitize_text_field($i['issueYear']??'')
        ];
    }
    public static function set_status($id,$status,$reason='') {
        $p=get_post($id);
        if (!$p || $p->post_type!==FREEBOOKS_DONATION_CPT) return new WP_Error('invalid_donation','Donation not found.',['status'=>404]);
        if (!in_array($status,self::STATUSES,true)) return new WP_Error('invalid_status','Invalid status.',['status'=>400]);
        update_post_meta($id,'_fb_status',$status); update_post_meta($id,'_fb_status_reason',sanitize_textarea_field($reason));
        $audit=get_post_meta($id,'_fb_audit',true); if (!is_array($audit)) $audit=[];
        $audit[]=['event'=>$status,'reason'=>sanitize_textarea_field($reason),'at'=>current_time('mysql',true),'actor'=>get_current_user_id()];
        update_post_meta($id,'_fb_audit',$audit);
        self::email_donor($id,$status,$reason);
        do_action('freebooks_donation_status_changed',$id,$status);
        return true;
    }
    public static function email_donor($id,$status,$reason='') {
        $d=(array)get_post_meta($id,'_fb_donor',true);
        $email=sanitize_email($d['email']??'');
        $ref=(string)get_post_meta($id,'_fb_reference',true);
        if (!$email || !is_email($email)) {
            self::record_communication($id,'email','skipped','No valid donor email address is available.',$status);
            return false;
        }
        $wp_user = get_user_by('email', $email);
        if ($wp_user && class_exists('FB_Notifications')) {
            $prefs = FB_Notifications::preferences($wp_user->ID);
            if (empty($prefs['donation_updates'])) {
                self::record_communication($id,'email','suppressed','Donor opted out of additional donation status emails.',$status);
                return false;
            }
        }
        $labels=[
            'submitted'=>'We have received your donation submission.',
            'approved'=>'Your donation has been approved. Our team will contact you about handover.',
            'rejected'=>'We are unable to accept this donation.',
            'more_info'=>'We need a little more information about your donation.',
            'pickup_scheduled'=>'A pickup has been scheduled. Please coordinate with our team.',
            'received'=>'Your donated items have been received.',
            'listed'=>'Your approved items have been listed.',
            'sold'=>'An item from your donation has been sold.'
        ];
        $body="Hello ".($d['name']??'there').",\n\n".($labels[$status]??'Your donation status has changed.');
        if ($reason) $body.="\n\nDetails: ".sanitize_textarea_field($reason);
        $body.="\n\nDonation reference: ".$ref."\n\nThank you for helping learning materials reach another learner.\nFreeBooks\nSamagraShriSai Foundation";
        $sent=wp_mail($email,'FreeBooks donation update — '.$ref,$body,['Content-Type: text/plain; charset=UTF-8']);
        self::record_communication($id,'email',$sent?'sent':'failed',$sent?'Donation update email accepted by the WordPress mail handler.':'WordPress could not hand off the donation update email.',$status,$email);
        return (bool)$sent;
    }
    public static function record_communication($id,$channel,$result,$details='',$status='',$recipient='') {
        $rows=get_post_meta($id,'_fb_communication_log',true);
        if (!is_array($rows)) $rows=[];
        $rows[]=[
            'at'=>current_time('mysql',true),
            'channel'=>sanitize_key($channel),
            'result'=>sanitize_key($result),
            'status'=>sanitize_key($status),
            'recipient'=>sanitize_email($recipient),
            'details'=>sanitize_textarea_field($details),
            'actor'=>get_current_user_id()
        ];
        update_post_meta($id,'_fb_communication_log',array_slice($rows,-100));
    }

}
