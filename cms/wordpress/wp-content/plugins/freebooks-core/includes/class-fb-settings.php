<?php
if (!defined('ABSPATH')) exit;
add_action('admin_init',function(){
 register_setting('freebooks_settings_group','freebooks_settings',['sanitize_callback'=>function($v){
  $keys=['contact_phone','contact_email','contact_address','whatsapp','facebook','instagram','youtube','linkedin','foundation_name','proceeds_copy','turnstile_site_key','turnstile_secret_key'];$o=[];
  foreach($keys as $k){$raw=trim((string)($v[$k]??''));if(in_array($k,['whatsapp','facebook','instagram','youtube','linkedin'],true))$o[$k]=$raw?esc_url_raw($raw,['http','https']):'';elseif($k==='contact_email')$o[$k]=sanitize_email($raw);elseif($k==='contact_phone')$o[$k]=preg_replace('/[^0-9+(). -]/','',$raw);elseif(in_array($k,['turnstile_site_key','turnstile_secret_key'],true))$o[$k]=sanitize_text_field($raw);else $o[$k]=sanitize_textarea_field($raw);}
  return $o;
 }]);
 register_setting('freebooks_settings_group','freebooks_next_url',['sanitize_callback'=>'esc_url_raw']);
 register_setting('freebooks_settings_group','freebooks_revalidate_secret',['sanitize_callback'=>'sanitize_text_field']);
 register_setting('freebooks_settings_group','freebooks_public_sitemap_url',['sanitize_callback'=>'esc_url_raw']);
 register_setting('freebooks_settings_group','freebooks_ga4_measurement_id',['sanitize_callback'=>function($v){$v=strtoupper(trim((string)$v));return preg_match('/^G-[A-Z0-9]{4,20}$/',$v)?$v:'';}]);
 register_setting('freebooks_settings_group','freebooks_gsc_verification',['sanitize_callback'=>function($v){return preg_replace('/[^A-Za-z0-9_\-]/','',trim((string)$v));}]);
 register_setting('freebooks_settings_group','freebooks_bing_verification',['sanitize_callback'=>function($v){return preg_replace('/[^A-Za-z0-9_\-]/','',trim((string)$v));}]);
 register_setting('freebooks_settings_group','freebooks_analytics_consent_required',['sanitize_callback'=>function($v){return $v==='1'?'1':'0';}]);
});
add_action('admin_menu',function(){
 add_options_page('FreeBooks settings','FreeBooks settings','manage_options','freebooks-settings',function(){
  if(!current_user_can('manage_options'))return;$s=get_option('freebooks_settings',[]);
  echo '<div class="wrap"><h1>FreeBooks settings</h1><form method="post" action="options.php">';settings_fields('freebooks_settings_group');
  $fields=['contact_phone'=>'Contact phone','contact_email'=>'Contact email','contact_address'=>'Contact address','whatsapp'=>'Official WhatsApp URL','facebook'=>'Facebook URL','instagram'=>'Instagram URL','youtube'=>'YouTube URL','linkedin'=>'LinkedIn URL','foundation_name'=>'Foundation name','proceeds_copy'=>'Use of proceeds wording','turnstile_site_key'=>'Cloudflare Turnstile site key','turnstile_secret_key'=>'Cloudflare Turnstile secret key'];
  foreach($fields as $k=>$label)echo '<p><label><strong>'.esc_html($label).'</strong><br><input class="regular-text" name="freebooks_settings['.esc_attr($k).']" value="'.esc_attr($s[$k]??'').'"></label></p>';
  echo '<h2>Donation form protection</h2><p>Optional Cloudflare Turnstile keys. Leave both blank to use the built-in honeypot, rate limit and duplicate checks only.</p>';
  echo '<h2>Frontend integration</h2><p>Next.js URL<br><input class="regular-text" name="freebooks_next_url" value="'.esc_attr(get_option('freebooks_next_url','')).'"></p>';
  echo '<p>Revalidation secret<br><input class="regular-text" type="password" autocomplete="new-password" name="freebooks_revalidate_secret" value="'.esc_attr(get_option('freebooks_revalidate_secret','')).'"></p>';
  echo '<h2>Public website SEO & analytics handoff</h2><p>These values are for the public Next.js website, not for indexing or tracking the WordPress CMS. The public frontend must apply its own SEO tags, sitemap and consent-aware analytics.</p>';
  echo '<p>Public sitemap URL<br><input class="regular-text" type="url" name="freebooks_public_sitemap_url" placeholder="https://freebook.co.in/sitemap.xml" value="'.esc_attr(get_option('freebooks_public_sitemap_url','')).'"></p>';
  echo '<p>Google Analytics 4 Measurement ID<br><input class="regular-text" name="freebooks_ga4_measurement_id" placeholder="G-XXXXXXXXXX" value="'.esc_attr(get_option('freebooks_ga4_measurement_id','')).'"></p>';
  echo '<p>Google Search Console verification token<br><input class="regular-text" name="freebooks_gsc_verification" value="'.esc_attr(get_option('freebooks_gsc_verification','')).'"></p>';
  echo '<p>Bing Webmaster verification token<br><input class="regular-text" name="freebooks_bing_verification" value="'.esc_attr(get_option('freebooks_bing_verification','')).'"></p>';
  echo '<p><label><input type="checkbox" name="freebooks_analytics_consent_required" value="1" '.checked(get_option('freebooks_analytics_consent_required','1'),'1',false).'> Require analytics consent before loading analytics on the public frontend</label></p>';
  submit_button();echo '</form></div>';
 });
});
