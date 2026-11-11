<?php
if (!defined('ABSPATH')) exit;
class FB_Operations {
    public static function register_roles() {
        $volunteer=['read'=>true,'read_fb_donation'=>true,'read_private_fb_donations'=>true];
        $moderator=$volunteer+['edit_fb_donations'=>true,'edit_others_fb_donations'=>true,'edit_private_fb_donations'=>true,'edit_published_fb_donations'=>true,'upload_files'=>true];
        if(!get_role('fb_volunteer'))add_role('fb_volunteer','FreeBooks Volunteer',$volunteer);
        if(!get_role('fb_moderator'))add_role('fb_moderator','FreeBooks Moderator',$moderator);
        $v=get_role('fb_volunteer');if($v)foreach($volunteer as $cap=>$grant)$v->add_cap($cap,$grant);
        $m=get_role('fb_moderator');if($m)foreach($moderator as $cap=>$grant)$m->add_cap($cap,$grant);
        $admin=get_role('administrator');
        if($admin)foreach(array_keys($moderator+['edit_fb_donation'=>true,'delete_fb_donation'=>true,'publish_fb_donations'=>true,'delete_fb_donations'=>true,'delete_private_fb_donations'=>true,'delete_published_fb_donations'=>true,'delete_others_fb_donations'=>true,'manage_fb_inventory'=>true,'manage_fb_operations'=>true]) as $cap)$admin->add_cap($cap);
    }
    public static function menu() {
        add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT,'Donation acknowledgements','Acknowledgements','edit_fb_donations','fb-acknowledgements',[self::class,'ack_page']);
        add_submenu_page('edit.php?post_type='.FREEBOOKS_ITEM_CPT,'Shelf labels','Print labels','manage_options','fb-print-labels',[self::class,'labels_page']);
    }
    public static function ack_page() {
        if(!current_user_can('edit_fb_donations'))wp_die('Permission denied.');
        $ids=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>100,'orderby'=>'date','order'=>'DESC','fields'=>'ids']);
        echo '<div class="wrap"><h1>Donation acknowledgements</h1><p>Print a non-tax donation acknowledgement. Use browser Print → Save as PDF. This is not a tax receipt or valuation.</p><table class="widefat striped"><thead><tr><th>Reference</th><th>Donor</th><th>Date</th><th>Action</th></tr></thead><tbody>';
        if(!$ids)echo '<tr><td colspan="4">No donations yet.</td></tr>';
        foreach($ids as $id){$d=get_post_meta($id,'_fb_donor',true);$url=add_query_arg(['fb_print_ack'=>1,'donation_id'=>$id,'_wpnonce'=>wp_create_nonce('fb_print_ack_'.$id)],home_url('/'));echo '<tr><td>'.esc_html(get_post_meta($id,'_fb_reference',true)).'</td><td>'.esc_html($d['name']??'').'</td><td>'.esc_html(get_the_date('Y-m-d',$id)).'</td><td><a class="button" target="_blank" rel="noopener" href="'.esc_url($url).'">Print acknowledgement</a></td></tr>';}
        echo '</tbody></table></div>';
    }
    public static function handle_print_ack() {
        if(empty($_GET['fb_print_ack']))return;
        $id=absint($_GET['donation_id']??0);
        if(!$id||!current_user_can('edit_fb_donations')||!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce']??'')),'fb_print_ack_'.$id))wp_die('Not authorised.',403);
        $p=get_post($id);if(!$p||$p->post_type!==FREEBOOKS_DONATION_CPT)wp_die('Donation not found.',404);
        $d=get_post_meta($id,'_fb_donor',true);$items=get_post_meta($id,'_fb_items',true);$settings=get_option('freebooks_settings',[]);nocache_headers();
        echo '<!doctype html><html><head><meta charset="utf-8"><title>FreeBooks Donation Acknowledgement</title><style>body{font:16px Arial,sans-serif;color:#24332f;max-width:760px;margin:40px auto;padding:30px;border:2px solid #176b55}h1{color:#176b55}small{color:#666}.line{border-top:1px solid #ddd;margin:24px 0}@media print{button{display:none}body{margin:0;border:0}}</style></head><body>';
        echo '<h1>FreeBooks</h1><h2>Donation Acknowledgement</h2><p>Dear '.esc_html($d['name']??'Donor').',</p><p>Thank you for donating learning materials to FreeBooks, an initiative of '.esc_html($settings['foundation_name']??'SamagraShriSai Foundation').'. This confirms your submission details; it is not a valuation, tax receipt, or claim of tax exemption.</p><p><strong>Reference:</strong> '.esc_html(get_post_meta($id,'_fb_reference',true)).'<br><strong>Submission date:</strong> '.esc_html(get_the_date('F j, Y',$id)).'</p><h3>Items submitted</h3><ul>';
        foreach((array)$items as $i)echo '<li>'.esc_html($i['title']??'Item').' — '.absint($i['quantity']??1).' × '.esc_html($i['category']??'').'</li>';
        echo '</ul><div class="line"></div><p>'.esc_html($settings['proceeds_copy']??'Sales proceeds support students in need.').'</p><p>With gratitude,<br>'.esc_html($settings['foundation_name']??'SamagraShriSai Foundation').'</p><small>No tax benefit is represented by this document.</small><p><button onclick="window.print()">Print / Save as PDF</button></p></body></html>';exit;
    }
    public static function labels_page() {
        if(!current_user_can('manage_options'))wp_die('Permission denied.');
        $ids=get_posts(['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>['publish','draft','private'],'numberposts'=>200,'orderby'=>'title','order'=>'ASC']);
        echo '<div class="wrap"><h1>Catalogue shelf labels</h1><p>Print labels with item title, inventory ID, shelf code and public catalogue URL. QR image generation is not included.</p><button class="button" onclick="window.print()">Print labels</button><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:20px">';
        foreach($ids as $id)echo '<div style="border:1px dashed #888;padding:12px;min-height:90px;break-inside:avoid"><strong>'.esc_html(get_the_title($id)).'</strong><br><small>ID: '.esc_html($id).' | Shelf: '.esc_html(get_post_meta($id,'_fb_shelf_code',true)).'</small><br><small>'.esc_html(get_permalink($id)).'</small></div>';
        echo '</div><style>@media print{#adminmenumain,#wpadminbar,.wrap>h1,.wrap>p,.wrap>.button{display:none!important}.wrap{margin:0!important}.wrap>div{grid-template-columns:repeat(3,1fr)!important}}</style></div>';
    }
}
