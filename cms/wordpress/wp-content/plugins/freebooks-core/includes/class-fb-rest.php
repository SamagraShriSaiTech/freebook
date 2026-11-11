<?php
if (!defined('ABSPATH')) exit;
class FB_REST {
 public static function register_routes() {
  register_rest_route('freebooks/v1','/donations',['methods'=>'POST','callback'=>[self::class,'create_donation'],'permission_callback'=>'__return_true']);
  register_rest_route('freebooks/v1','/donations/submit',['methods'=>'POST','callback'=>[self::class,'create_donation_with_uploads'],'permission_callback'=>'__return_true']);
  register_rest_route('freebooks/v1','/donations/(?P<reference>[A-Za-z0-9-]+)/track',['methods'=>'POST','callback'=>[self::class,'track_donation'],'permission_callback'=>'__return_true']);
  register_rest_route('freebooks/v1','/donations/portal/request-otp',['methods'=>'POST','callback'=>[self::class,'portal_request_otp'],'permission_callback'=>'__return_true']);
  register_rest_route('freebooks/v1','/donations/portal/verify-otp',['methods'=>'POST','callback'=>[self::class,'portal_verify_otp'],'permission_callback'=>'__return_true']);
  register_rest_route('freebooks/v1','/donations/portal/(?P<reference>[A-Za-z0-9-]+)',['methods'=>'GET','callback'=>[self::class,'portal_details'],'permission_callback'=>'__return_true']);
  register_rest_route('freebooks/v1','/impact',['methods'=>'GET','callback'=>[self::class,'impact'],'permission_callback'=>'__return_true']);
  register_rest_route('freebooks/v1','/revalidate',['methods'=>'POST','callback'=>[self::class,'revalidate'],'permission_callback'=>function($r){$s=(string)get_option('freebooks_revalidate_secret','');return $s!==''&&hash_equals($s,(string)$r->get_header('x-freebooks-secret'));}]);
 }
 public static function create_donation($r) {
  $ip=$_SERVER['REMOTE_ADDR']??'unknown';$k='fb_rate_'.md5($ip);$n=(int)get_transient($k);
  $p=$r->get_json_params();
  if($n>=5)return new WP_Error('rate_limited','Please wait before submitting again.',['status'=>429]);
  if(!is_array($p)||empty($p['consent'])||empty($p['donor']['name'])||!is_email($p['donor']['email']??'')||empty($p['donor']['phone'])||empty($p['items'])||!is_array($p['items'])||count($p['items'])>20)return new WP_Error('invalid_donation','Valid donor details and 1–20 item groups are required.',['status'=>400]);
  foreach($p['items'] as $i)if(!is_array($i)||empty($i['title'])||empty($i['category']))return new WP_Error('invalid_item','Each item needs title and category.',['status'=>400]);
  set_transient($k,$n+1,10*MINUTE_IN_SECONDS);$result=FB_Donations::create($p);if(is_wp_error($result))return $result;
  $campaign_id=absint($p['campaign_id']??0);
  if($campaign_id && get_post_type($campaign_id)===FB_Donor_CRM::CAMPAIGN_CPT) update_post_meta($result['id'],'_fb_campaign_id',$campaign_id);
  return new WP_REST_Response(['donation_id'=>$result['reference'],'status'=>'submitted'],201);
 }

 public static function create_donation_with_uploads($r) {
  $ip=$_SERVER['REMOTE_ADDR']??'unknown';
  $rate_key='fb_rest_donate_'.hash('sha256',$ip);
  $attempts=(int)get_transient($rate_key);
  if($attempts>=5)return new WP_Error('rate_limited','Too many donation submissions. Please wait 10 minutes and try again.',['status'=>429]);

  $honeypot=sanitize_text_field((string)$r->get_param('fb_website'));
  if($honeypot!=='')return new WP_Error('invalid_submission','Submission could not be accepted.',['status'=>400]);

  $token=sanitize_text_field((string)$r->get_param('cf-turnstile-response'));
  if(!FB_Governance::verify_turnstile($token))return new WP_Error('verification_failed','Please complete the security verification and try again.',['status'=>403]);

  $payload=$r->get_param('payload');
  if(is_string($payload))$payload=json_decode(wp_unslash($payload),true);
  if(!is_array($payload))return new WP_Error('invalid_payload','Donation details could not be read.',['status'=>400]);

  $donor=$payload['donor']??[];
  $items=$payload['items']??[];
  if(empty($payload['consent'])||empty($donor['name'])||!is_email($donor['email']??'')||empty($donor['phone'])||!is_array($items)||count($items)<1||count($items)>20)
   return new WP_Error('invalid_donation','Valid donor details, consent and 1–20 item groups are required.',['status'=>400]);

  foreach($items as $item){
   if(!is_array($item)||empty($item['title'])||empty($item['category']))
    return new WP_Error('invalid_item','Each item needs a title and category.',['status'=>400]);
   if(FB_Governance::is_recent_duplicate($donor['email'],$item['title']))
    return new WP_Error('possible_duplicate','A similar item was submitted recently using this email. Please contact the team if you believe this is an error.',['status'=>409]);
  }

  set_transient($rate_key,$attempts+1,10*MINUTE_IN_SECONDS);
  $guard=FB_Governance::check_submission_guard();
  if(is_wp_error($guard))return new WP_Error('rate_limited',$guard->get_error_message(),['status'=>429]);

  $files=$r->get_file_params();
  $photo_files=$files['fb_photos']??[];
  $photos=[];
  if(!empty($photo_files)){
   if(!function_exists('wp_handle_upload'))require_once ABSPATH.'wp-admin/includes/file.php';
   if(!function_exists('wp_generate_attachment_metadata'))require_once ABSPATH.'wp-admin/includes/image.php';
   if(!function_exists('wp_insert_attachment'))require_once ABSPATH.'wp-admin/includes/media.php';
   $names=$photo_files['name']??[];
   if(!is_array($names))return new WP_Error('invalid_photos','Photo upload data is invalid.',['status'=>400]);
   if(count($names)>3)return new WP_Error('too_many_photos','You can upload up to 3 photos.',['status'=>400]);
   for($i=0;$i<count($names);$i++){
    $single=[
     'name'=>$photo_files['name'][$i]??'',
     'type'=>$photo_files['type'][$i]??'',
     'tmp_name'=>$photo_files['tmp_name'][$i]??'',
     'error'=>$photo_files['error'][$i]??UPLOAD_ERR_NO_FILE,
     'size'=>$photo_files['size'][$i]??0
    ];
    if($single['error']===UPLOAD_ERR_NO_FILE)continue;
    if($single['error']!==UPLOAD_ERR_OK)return new WP_Error('photo_upload_failed','One of the photos could not be uploaded.',['status'=>400]);
    if((int)$single['size']>5*1024*1024)return new WP_Error('photo_too_large','Each photo must be 5 MB or smaller.',['status'=>400]);
    $check=wp_check_filetype_and_ext($single['tmp_name'],$single['name']);
    if(!in_array($check['type']??'', ['image/jpeg','image/png','image/webp'],true))
     return new WP_Error('invalid_photo_type','Photos must be JPG, PNG or WebP images.',['status'=>400]);
    $overrides=['test_form'=>false,'mimes'=>['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']];
    $upload=wp_handle_upload($single,$overrides);
    if(empty($upload['file']))return new WP_Error('photo_upload_failed','A photo could not be stored.',['status'=>400]);
    $attachment=wp_insert_attachment([
     'post_mime_type'=>$upload['type'],
     'post_title'=>sanitize_file_name(pathinfo($upload['file'],PATHINFO_FILENAME)),
     'post_status'=>'private'
    ],$upload['file']);
    if(!$attachment||is_wp_error($attachment))return new WP_Error('photo_record_failed','A photo could not be attached to the donation.',['status'=>500]);
    wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$upload['file']));
    update_post_meta($attachment,'_fb_private_donation_upload',1);
    $photos[]=(int)$attachment;
   }
  }

  $payload['photos']=$photos;
  $created=FB_Donations::create($payload);
  if(is_wp_error($created))return $created;
  FB_Governance::record_submission($donor['email'],$items[0]['title']??'',(int)$created['id']);
  $campaign_id=absint($payload['campaign_id']??0);
  if($campaign_id && get_post_type($campaign_id)===FB_Donor_CRM::CAMPAIGN_CPT)
   update_post_meta($created['id'],'_fb_campaign_id',$campaign_id);
  return new WP_REST_Response(['donation_id'=>$created['reference'],'status'=>'submitted'],201);
 }

 public static function track_donation($r) {
  $ref=sanitize_text_field($r['reference']);$p=$r->get_json_params();$email=sanitize_email($p['email']??'');$phone=sanitize_text_field($p['phone']??'');
  if(!$email||!$phone)return new WP_Error('missing_contact','Email and phone are required.',['status'=>400]);
  $ids=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>1,'meta_key'=>'_fb_reference','meta_value'=>$ref,'fields'=>'ids']);
  if(!$ids)return new WP_Error('not_found','Donation not found.',['status'=>404]);$id=$ids[0];$d=get_post_meta($id,'_fb_donor',true);
  if(!hash_equals(strtolower((string)($d['email']??'')),strtolower($email))||!hash_equals(preg_replace('/\D+/','',(string)($d['phone']??'')),preg_replace('/\D+/','',$phone)))return new WP_Error('not_authorized','Contact details do not match.',['status'=>403]);
  return ['donation_id'=>$ref,'status'=>get_post_meta($id,'_fb_status',true),'updated_at'=>get_post_modified_time('c',true,$id)];
 }

 public static function portal_request_otp($r) {
  $p=$r->get_json_params(); $ref=sanitize_text_field($p['reference']??''); $email=sanitize_email($p['email']??''); $phone=preg_replace('/\D+/','',(string)($p['phone']??''));
  $ip=$_SERVER['REMOTE_ADDR']??'unknown'; $ipkey='fb_portal_req_ip_'.hash('sha256',$ip); $n=(int)get_transient($ipkey);
  if($n>=8)return new WP_Error('rate_limited','Too many requests. Please wait before trying again.',['status'=>429]);
  set_transient($ipkey,$n+1,15*MINUTE_IN_SECONDS);
  $ids=$ref?get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>1,'meta_key'=>'_fb_reference','meta_value'=>$ref,'fields'=>'ids']):[];
  $generic=['message'=>'If the reference and contact details match a donation, a verification code will be sent to its registered email address.'];
  if(!$ids)return new WP_REST_Response($generic,200);
  $id=(int)$ids[0]; $d=get_post_meta($id,'_fb_donor',true); $stored_email=strtolower((string)($d['email']??'')); $stored_phone=preg_replace('/\D+/','',(string)($d['phone']??''));
  if(!$email||!$phone||!hash_equals($stored_email,strtolower($email))||!hash_equals($stored_phone,$phone))return new WP_REST_Response($generic,200);
  $key='fb_portal_otp_'.hash('sha256',$ref.'|'.$stored_email); $cool=$key.'_cooldown';
  if(get_transient($cool))return new WP_REST_Response(['message'=>'A code was recently requested. Please wait one minute before requesting another.'],200);
  $otp=(string)wp_rand(100000,999999); set_transient($key,wp_hash_password($otp),10*MINUTE_IN_SECONDS); set_transient($key.'_attempts',0,10*MINUTE_IN_SECONDS); set_transient($cool,1,60);
  $sent=wp_mail($stored_email,'Your FreeBooks donation verification code',"Your one-time verification code is {$otp}. It expires in 10 minutes. If you did not request this, you can ignore this email.");
  if(class_exists('FB_Donations'))FB_Donations::record_communication($id,'otp_email',$sent?'sent':'failed',$sent?'Donation portal verification code email handed off.':'Could not hand off donation portal verification code email.','tracking',$stored_email);
  return new WP_REST_Response(['message'=>$sent?'If the details match, a verification code has been sent to the registered email address.':'We could not send the verification email. Please contact support.'],200);
 }
 public static function portal_verify_otp($r) {
  $p=$r->get_json_params(); $ref=sanitize_text_field($p['reference']??''); $email=sanitize_email($p['email']??''); $phone=preg_replace('/\D+/','',(string)($p['phone']??'')); $otp=preg_replace('/\D+/','',(string)($p['otp']??''));
  $ip=$_SERVER['REMOTE_ADDR']??'unknown'; $rate='fb_portal_verify_'.hash('sha256',$ip.'|'.$ref); $n=(int)get_transient($rate);
  if($n>=8)return new WP_Error('rate_limited','Too many verification attempts. Please wait 15 minutes.',['status'=>429]); set_transient($rate,$n+1,15*MINUTE_IN_SECONDS);
  $ids=$ref?get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>1,'meta_key'=>'_fb_reference','meta_value'=>$ref,'fields'=>'ids']):[];
  if(!$ids)return new WP_Error('verification_failed','The details or verification code are invalid or expired.',['status'=>403]);
  $id=(int)$ids[0];$d=get_post_meta($id,'_fb_donor',true);$stored_email=strtolower((string)($d['email']??''));$stored_phone=preg_replace('/\D+/','',(string)($d['phone']??''));
  if(!$email||!$phone||!hash_equals($stored_email,strtolower($email))||!hash_equals($stored_phone,$phone))return new WP_Error('verification_failed','The details or verification code are invalid or expired.',['status'=>403]);
  $key='fb_portal_otp_'.hash('sha256',$ref.'|'.$stored_email);$hash=get_transient($key);$tries=(int)get_transient($key.'_attempts');
  if(!$hash||$tries>=5||strlen($otp)!==6||!wp_check_password($otp,$hash)){set_transient($key.'_attempts',$tries+1,10*MINUTE_IN_SECONDS);return new WP_Error('verification_failed','The details or verification code are invalid or expired.',['status'=>403]);}
  delete_transient($key);delete_transient($key.'_attempts');delete_transient($rate);
  $token=bin2hex(random_bytes(32));$token_hash=hash('sha256',$token);set_transient('fb_portal_session_'.$token_hash,['id'=>$id,'reference'=>$ref],30*MINUTE_IN_SECONDS);
  return new WP_REST_Response(['access_token'=>$token,'expires_in'=>1800,'donation'=>self::portal_payload($id,$ref)],200);
 }
 public static function portal_details($r) {
  $token=sanitize_text_field((string)$r->get_header('x-freebooks-portal-token'));$ref=sanitize_text_field($r['reference']);
  if(!$token||strlen($token)!==64)return new WP_Error('unauthorized','Please verify your email to view this donation.',['status'=>401]);
  $session=get_transient('fb_portal_session_'.hash('sha256',$token));
  if(!is_array($session)||empty($session['id'])||!hash_equals((string)$session['reference'],$ref))return new WP_Error('unauthorized','Your verification session has expired. Please verify again.',['status'=>401]);
  return self::portal_payload((int)$session['id'],$ref);
 }
 private static function portal_payload($id,$ref) {
  $status=get_post_meta($id,'_fb_status',true)?:'submitted';$audit=get_post_meta($id,'_fb_audit',true);$audit=is_array($audit)?$audit:[];
  $labels=['submitted'=>'Submission received','approved'=>'Approved','rejected'=>'Not accepted','more_info'=>'More information requested','pickup_scheduled'=>'Pickup scheduled','received'=>'Items received','listed'=>'Catalogue listing published','sold'=>'Sold'];$history=[];
  foreach($audit as $event){$key=sanitize_key($event['event']??'');$history[]=['status'=>$key,'label'=>$labels[$key]??ucwords(str_replace('_',' ',$key)),'at'=>sanitize_text_field($event['at']??''),'note'=>sanitize_textarea_field($event['reason']??'')];}
  $items=get_post_meta($id,'_fb_items',true);if(!is_array($items))$items=[];$safe_items=[];foreach($items as $item)$safe_items[]=['title'=>sanitize_text_field($item['title']??'Donated item'),'category'=>sanitize_text_field($item['category']??''),'quantity'=>absint($item['quantity']??1)];
  return ['donation_id'=>$ref,'status'=>$status,'status_label'=>$labels[$status]??ucwords(str_replace('_',' ',$status)),'updated_at'=>get_post_modified_time('c',true,$id),'items'=>$safe_items,'history'=>$history];
 }

 public static function impact() {
  if(class_exists('FB_Impact')) return FB_Impact::public_metrics();
  $donations=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>-1,'fields'=>'ids']);
  $received=0;$listed=0;$sold=0;
  foreach($donations as $id){$s=get_post_meta($id,'_fb_status',true);if(in_array($s,['received','listed','sold'],true))$received++;if(in_array($s,['listed','sold'],true))$listed++;if($s==='sold')$sold++;}
  return ['donations_received'=>$received,'donations_listed'=>$listed,'donations_sold'=>$sold,'students_supported'=>null,'verified'=>false,'note'=>'Donation counts are operational counts, not a verified student-impact figure.'];
 }
 public static function revalidate($r) {
  $base=rtrim((string)get_option('freebooks_next_url',''),'/');$secret=(string)get_option('freebooks_revalidate_secret','');
  if(!$base||!$secret)return new WP_Error('not_configured','Revalidation is not configured.',['status'=>503]);
  $res=wp_remote_post($base.'/api/revalidate',['timeout'=>5,'headers'=>['Content-Type'=>'application/json','x-revalidate-secret'=>$secret],'body'=>wp_json_encode(['path'=>'/shop'])]);
  if(is_wp_error($res))return new WP_Error('revalidation_failed',$res->get_error_message(),['status'=>502]);
  return ['sent'=>wp_remote_retrieve_response_code($res)<400];
 }
}
