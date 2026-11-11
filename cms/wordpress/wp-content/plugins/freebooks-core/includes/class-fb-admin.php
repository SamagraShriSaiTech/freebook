<?php
if (!defined('ABSPATH')) exit;
class FB_Admin {
 public static function menu() {
  add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT,'Donation review queue','Review queue','edit_fb_donations','fb-donation-queue',[self::class,'queue']);
 }
 public static function queue() {
  if(!current_user_can('edit_fb_donations'))wp_die('Permission denied.');
  if(isset($_POST['fb_action'],$_POST['donation_id'])){
   check_admin_referer('fb_review_action');$id=absint($_POST['donation_id']);$status=sanitize_key(wp_unslash($_POST['fb_action']));$reason=sanitize_textarea_field(wp_unslash($_POST['reason']??''));
   $res=FB_Donations::set_status($id,$status,$reason);
   echo is_wp_error($res)?'<div class="notice notice-error"><p>'.esc_html($res->get_error_message()).'</p></div>':'<div class="notice notice-success"><p>Status updated.</p></div>';
  }
  $posts=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>100,'orderby'=>'date','order'=>'DESC']);
  echo '<div class="wrap"><h1>FreeBooks — Donation review queue</h1><p>Submitted → Approved / Rejected / More Info → Pickup Scheduled → Received → Listed → Sold</p><table class="widefat striped"><thead><tr><th>Reference</th><th>Donor</th><th>Items / Photos</th><th>Handover</th><th>Status</th><th>Update</th></tr></thead><tbody>';
  if(!$posts)echo '<tr><td colspan="6">No donation submissions yet.</td></tr>';
  foreach($posts as $p){
   $id=$p->ID;$d=get_post_meta($id,'_fb_donor',true);$items=get_post_meta($id,'_fb_items',true);$status=get_post_meta($id,'_fb_status',true)?:'submitted';$ref=get_post_meta($id,'_fb_reference',true);
   echo '<tr><td><strong>'.esc_html($ref).'</strong><br>'.esc_html(get_the_date('Y-m-d H:i',$p)).'</td><td>'.esc_html($d['name']??'').'<br>'.esc_html($d['email']??'').'<br>'.esc_html($d['phone']??'').'<br>'.esc_html($d['city']??'').'</td><td>';
   foreach((array)$items as $i)echo '<p><strong>'.esc_html($i['title']??'').'</strong> × '.absint($i['quantity']??1).'<br>'.esc_html($i['category']??'').' | '.esc_html($i['condition']??'').'<br>'.esc_html($i['author']??'').' | ISBN '.esc_html($i['isbn']??'').'</p>';
   $photos=get_post_meta($id,'_fb_photos',true);foreach((array)$photos as $photo){$url=wp_get_attachment_url($photo);if($url)echo '<a target="_blank" rel="noopener" href="'.esc_url($url).'">View photo</a><br>';}
   echo '<details><summary>Donor message</summary>'.esc_html(get_post_meta($id,'_fb_message',true)).'</details></td><td>'.esc_html(get_post_meta($id,'_fb_handover',true)).'</td><td><strong>'.esc_html(ucwords(str_replace('_',' ',$status))).'</strong><br>'.esc_html(get_post_meta($id,'_fb_status_reason',true)).'</td><td><form method="post">';
   wp_nonce_field('fb_review_action');echo '<input type="hidden" name="donation_id" value="'.esc_attr($id).'"><select name="fb_action">';
   foreach(FB_Donations::STATUSES as $s)echo '<option value="'.esc_attr($s).'"'.selected($status,$s,false).'>'.esc_html(ucwords(str_replace('_',' ',$s))).'</option>';
   echo '</select><br><input name="reason" placeholder="Reason / note" style="width:100%;margin:6px 0"><button class="button button-primary">Save</button></form></td></tr>';
  }
  echo '</tbody></table><p>Use the Receiving &amp; listing screen to create catalogue drafts after donated items have been physically received. Review and publish each catalogue record separately.</p></div>';
 }
}
