<?php
if (!defined('ABSPATH')) exit;
/** Secure customer accounts using opaque, expiring bearer tokens. */
class FB_Accounts {
    const TOKEN_META = '_fb_account_tokens';
    public static function init() {
        add_action('rest_api_init', [self::class, 'routes']);
    }
    public static function routes() {
        register_rest_route('freebooks/v1','/account/register',['methods'=>'POST','callback'=>[self::class,'register'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/account/login',['methods'=>'POST','callback'=>[self::class,'login'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/account/me',['methods'=>'GET','callback'=>[self::class,'me'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/account/profile',['methods'=>'POST','callback'=>[self::class,'profile'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/account/logout',['methods'=>'POST','callback'=>[self::class,'logout'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/account/wishlist',['methods'=>['GET','POST','DELETE'],'callback'=>[self::class,'wishlist'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/account/orders',['methods'=>'GET','callback'=>[self::class,'orders'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/account/order-detail',['methods'=>'GET','callback'=>[self::class,'order_detail'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/account/order-support',['methods'=>'POST','callback'=>[self::class,'submit_order_support'],'permission_callback'=>'__return_true']);
        register_rest_route('freebooks/v1','/account/privacy-request',['methods'=>'POST','callback'=>[self::class,'privacy_request'],'permission_callback'=>'__return_true']);
    }
    private static function issue_token($user_id) {
        $token=bin2hex(random_bytes(32)); $rows=get_user_meta($user_id,self::TOKEN_META,true); if(!is_array($rows))$rows=[];
        $now=time(); $rows=array_values(array_filter($rows,fn($r)=>is_array($r)&&absint($r['expires']??0)>$now));
        $rows[]=['hash'=>hash('sha256',$token),'expires'=>$now+14*DAY_IN_SECONDS];
        update_user_meta($user_id,self::TOKEN_META,array_slice($rows,-5)); set_transient('fb_account_token_'.hash('sha256',$token),absint($user_id),14*DAY_IN_SECONDS); return $token;
    }
    private static function user($request) {
        $header=(string)$request->get_header('authorization');
        if(!preg_match('/^Bearer\s+([a-f0-9]{64})$/i',$header,$m))return new WP_Error('fb_auth','Please sign in to continue.',['status'=>401]);
        $hash=hash('sha256',$m[1]); $uid=absint(get_transient('fb_account_token_'.$hash));
        if(!$uid)return new WP_Error('fb_auth','Your session has expired. Please sign in again.',['status'=>401]);
        $rows=get_user_meta($uid,self::TOKEN_META,true); $valid=false;
        foreach((array)$rows as $row) if(!empty($row['hash'])&&hash_equals((string)$row['hash'],$hash)&&absint($row['expires']??0)>time()){$valid=true;break;}
        $user=$valid?get_user_by('id',$uid):false;
        return $user?:new WP_Error('fb_auth','Your session has expired. Please sign in again.',['status'=>401]);
    }
    private static function result($user,$token=null) {
        $data=['id'=>$user->ID,'name'=>$user->display_name,'email'=>$user->user_email,'phone'=>(string)get_user_meta($user->ID,'billing_phone',true),'city'=>(string)get_user_meta($user->ID,'billing_city',true)];
        if($token)$data['token']=$token; return rest_ensure_response(['account'=>$data]);
    }
    public static function register($r) {
        $name=sanitize_text_field($r->get_param('name')); $email=sanitize_email($r->get_param('email')); $pass=(string)$r->get_param('password');
        if(!$name||!is_email($email)||strlen($pass)<10)return new WP_Error('fb_invalid','Enter your name, a valid email and a password of at least 10 characters.',['status'=>400]);
        if(email_exists($email))return new WP_Error('fb_exists','An account already exists for this email. Please sign in.',['status'=>409]);
        $id=wp_insert_user(['user_login'=>sanitize_user(current(explode('@',$email)),true).'-'.wp_generate_password(5,false,false),'user_email'=>$email,'user_pass'=>$pass,'display_name'=>$name,'role'=>get_role('customer')?'customer':'subscriber']);
        if(is_wp_error($id))return $id;
        update_user_meta($id,'billing_first_name',$name); if($r->get_param('phone'))update_user_meta($id,'billing_phone',sanitize_text_field($r->get_param('phone')));
        $user=get_user_by('id',$id); return self::result($user,self::issue_token($id));
    }
    public static function login($r) {
        $email=sanitize_email($r->get_param('email')); $user=get_user_by('email',$email);
        if(!$user||!wp_check_password((string)$r->get_param('password'),$user->user_pass,$user->ID))return new WP_Error('fb_login','Email or password is incorrect.',['status'=>401]);
        return self::result($user,self::issue_token($user->ID));
    }
    public static function me($r) { $u=self::user($r); return is_wp_error($u)?$u:self::result($u); }
    public static function profile($r) { $u=self::user($r); if(is_wp_error($u))return $u; $name=sanitize_text_field($r->get_param('name')); if($name)wp_update_user(['ID'=>$u->ID,'display_name'=>$name]); update_user_meta($u->ID,'billing_phone',sanitize_text_field($r->get_param('phone'))); update_user_meta($u->ID,'billing_city',sanitize_text_field($r->get_param('city'))); return self::result(get_user_by('id',$u->ID)); }
    public static function logout($r) { $u=self::user($r); if(is_wp_error($u))return $u; $header=(string)$r->get_header('authorization'); preg_match('/^Bearer\s+([a-f0-9]{64})$/i',$header,$m); $hash=hash('sha256',$m[1]); $rows=get_user_meta($u->ID,self::TOKEN_META,true); update_user_meta($u->ID,self::TOKEN_META,array_values(array_filter((array)$rows,fn($x)=>!hash_equals((string)($x['hash']??''),$hash)))); delete_transient('fb_account_token_'.$hash); return rest_ensure_response(['success'=>true]); }
    public static function wishlist($r) { $u=self::user($r); if(is_wp_error($u))return $u; $ids=array_values(array_unique(array_filter(array_map('absint',(array)get_user_meta($u->ID,'_fb_wishlist',true))))); if($r->get_method()==='POST'){$id=absint($r->get_param('product_id')); if(!$id||get_post_type($id)!=='product')return new WP_Error('fb_product','Book not found.',['status'=>404]); $ids[]=$id;} elseif($r->get_method()==='DELETE'){$id=absint($r->get_param('product_id'));$ids=array_values(array_diff($ids,[$id]));} update_user_meta($u->ID,'_fb_wishlist',array_values(array_unique($ids))); $items=[]; foreach(array_values(array_unique($ids)) as $pid){$p=get_post($pid);if($p)$items[]=['id'=>$pid,'name'=>get_the_title($pid),'url'=>get_permalink($pid)];} return rest_ensure_response(['product_ids'=>array_values(array_unique($ids)),'items'=>$items]); }

    private static function owned_order($user, $order_id) {
        if (!function_exists('wc_get_order')) return false;
        $order = wc_get_order(absint($order_id));
        if (!$order) return false;
        $customer_id = (int)$order->get_customer_id();
        if ($customer_id > 0 && $customer_id !== (int)$user->ID) return false;
        if ($customer_id === 0 && strtolower(trim((string)$order->get_billing_email())) !== strtolower(trim((string)$user->user_email))) return false;
        return $order;
    }

    public static function order_detail($r) {
        $user = self::user($r);
        if (is_wp_error($user)) return $user;
        $order = self::owned_order($user, $r->get_param('id'));
        if (!$order) return new WP_Error('fb_order_not_found', 'Order not found.', ['status'=>404]);
        $created = $order->get_date_created();
        $fulfilment = [
            'status'=>(string)$order->get_meta('_fb_fulfilment_status'),
            'status_label'=>FB_Fulfilment::STATUSES[(string)$order->get_meta('_fb_fulfilment_status')] ?? '',
            'carrier'=>(string)$order->get_meta('_fb_fulfilment_carrier'),
            'tracking_number'=>(string)$order->get_meta('_fb_fulfilment_tracking_number'),
            'tracking_url'=>(string)$order->get_meta('_fb_fulfilment_tracking_url'),
            'dispatch_date'=>(string)$order->get_meta('_fb_fulfilment_dispatch_date'),
            'estimated_delivery'=>(string)$order->get_meta('_fb_fulfilment_eta')
        ];
        if ($fulfilment['tracking_url'] && !wp_http_validate_url($fulfilment['tracking_url'])) $fulfilment['tracking_url']='';
        $items=[];
        foreach ($order->get_items() as $line) {
            $items[] = [
                'name'=>sanitize_text_field($line->get_name()),
                'quantity'=>(int)$line->get_quantity(),
                'total'=>wp_strip_all_tags(wc_price((float)$line->get_total(), ['currency'=>$order->get_currency()])),
                'product_id'=>(int)$line->get_product_id()
            ];
        }
        $cases=[];
        if (class_exists('FB_Order_Support')) {
            $case_ids=get_posts([
                'post_type'=>FB_Order_Support::TYPE,'post_status'=>'private','numberposts'=>20,'fields'=>'ids',
                'meta_query'=>[
                    ['key'=>'_fbos_order_id','value'=>$order->get_id(),'compare'=>'='],
                    ['key'=>'_fbos_customer_email','value'=>strtolower((string)$user->user_email),'compare'=>'=']
                ]
            ]);
            foreach ($case_ids as $case_id) {
                $status=(string)get_post_meta($case_id,'_fbos_status',true);
                $kind=(string)get_post_meta($case_id,'_fbos_issue_type',true);
                $cases[]=[
                    'reference'=>(string)get_post_meta($case_id,'_fbos_reference',true),
                    'status'=>FB_Order_Support::STATUSES[$status]??ucwords(str_replace('_',' ',$status)),
                    'type'=>FB_Order_Support::KINDS[$kind]??'Support request',
                    'date'=>get_post_time(DATE_ATOM,true,$case_id)
                ];
            }
        }
        return rest_ensure_response([
            'id'=>$order->get_id(),
            'number'=>(string)$order->get_order_number(),
            'status'=>$order->get_status(),
            'status_label'=>wc_get_order_status_name($order->get_status()),
            'date_created'=>$created?$created->date(DATE_ATOM):null,
            'total_formatted'=>wp_strip_all_tags($order->get_formatted_order_total()),
            'currency'=>$order->get_currency(),
            'payment_method'=>sanitize_text_field($order->get_payment_method_title()),
            'items'=>$items,
            'fulfilment'=>$fulfilment,
            'support_cases'=>$cases,
            'customer_notes'=>array_map(function($note){return ['date'=>$note->comment_date_gmt,'note'=>wp_strip_all_tags($note->comment_content)];},$order->get_customer_order_notes())
        ]);
    }

    public static function submit_order_support($r) {
        $user = self::user($r);
        if (is_wp_error($user)) return $user;
        $order = self::owned_order($user, $r->get_param('order_id'));
        if (!$order) return new WP_Error('fb_order_not_found', 'Order not found.', ['status'=>404]);
        if (!class_exists('FB_Order_Support')) return new WP_Error('fb_support_unavailable', 'Customer support is temporarily unavailable.', ['status'=>503]);
        $payload=$r->get_json_params();
        if (!is_array($payload)) $payload=[];
        return FB_Order_Support::create_from_account($order, $user, $payload);
    }

    public static function privacy_request($r) {
        $user = self::user($r);
        if (is_wp_error($user)) return $user;
        $type = sanitize_key((string)$r->get_param('request_type'));
        if (!in_array($type, ['access', 'deletion'], true)) return new WP_Error('fb_privacy_type', 'Choose a valid privacy request type.', ['status'=>400]);
        $details = sanitize_textarea_field((string)$r->get_param('details'));
        if (strlen($details) > 2000) return new WP_Error('fb_privacy_details', 'Please keep additional details under 2,000 characters.', ['status'=>400]);
        $existing = get_posts([
            'post_type'=>'fb_privacy_request', 'post_status'=>['private'], 'numberposts'=>1, 'fields'=>'ids',
            'meta_query'=>[
                ['key'=>'_fbpr_user_id','value'=>$user->ID,'compare'=>'='],
                ['key'=>'_fbpr_type','value'=>$type,'compare'=>'='],
                ['key'=>'_fbpr_status','value'=>'pending','compare'=>'=']
            ]
        ]);
        if ($existing) return new WP_Error('fb_privacy_pending', 'You already have a pending request of this type. The team will review it shortly.', ['status'=>409]);
        $reference = 'PR-' . strtoupper(wp_generate_password(8, false, false));
        $id = wp_insert_post([
            'post_type'=>'fb_privacy_request', 'post_status'=>'private',
            'post_title'=>$reference . ' — ' . ($type === 'deletion' ? 'Account deletion' : 'Data access') . ' — ' . $user->user_email,
            'post_content'=>$details, 'meta_input'=>[
                '_fbpr_reference'=>$reference, '_fbpr_user_id'=>$user->ID,
                '_fbpr_email'=>strtolower($user->user_email), '_fbpr_type'=>$type,
                '_fbpr_status'=>'pending', '_fbpr_submitted_at'=>current_time('mysql')
            ]
        ], true);
        if (is_wp_error($id)) return new WP_Error('fb_privacy_save', 'Your request could not be saved. Please try again later.', ['status'=>500]);
        return rest_ensure_response(['success'=>true,'reference'=>$reference,'status'=>'pending','message'=>'Your privacy request has been submitted. The FreeBooks team will verify and review it before taking action.']);
    }

    public static function orders($r) { $u=self::user($r); if(is_wp_error($u))return $u; if(!function_exists('wc_get_orders'))return rest_ensure_response(['orders'=>[],'message'=>'Order history will appear when the shop is connected.']); $orders=wc_get_orders(['customer_id'=>$u->ID,'limit'=>20,'orderby'=>'date','order'=>'DESC']); $out=[]; foreach($orders as $o)$out[]=['id'=>$o->get_id(),'number'=>$o->get_order_number(),'status'=>$o->get_status(),'date'=>$o->get_date_created()?$o->get_date_created()->date('c'):'','total'=>$o->get_formatted_order_total(),'items'=>array_map(fn($i)=>$i->get_name(),$o->get_items())]; return rest_ensure_response(['orders'=>$out]); }
}
