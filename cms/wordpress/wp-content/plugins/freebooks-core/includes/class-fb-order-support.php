<?php
if (!defined('ABSPATH')) exit;

/**
 * Customer support, returns, refund requests and dispute workflow.
 * WooCommerce remains the source of truth for payments and actual refunds.
 * Shipping-carrier booking and parcel tracking remain with the carrier plugin.
 */
class FB_Order_Support {
    const TYPE = 'fbos_order_issue';
    const STATUSES = ['new'=>'New','triage'=>'Triage','in_progress'=>'In progress','awaiting_customer'=>'Awaiting customer','awaiting_carrier'=>'Awaiting carrier','approved'=>'Approved','declined'=>'Declined','resolved'=>'Resolved','closed'=>'Closed'];
    const KINDS = ['support'=>'General support','return'=>'Return request','refund'=>'Refund request','dispute'=>'Payment dispute','missing'=>'Missing item','wrong'=>'Wrong item','damage'=>'Damaged item','delivery'=>'Delivery concern','other'=>'Other'];

    public static function init() {
        add_action('init',[self::class,'register_type']);
        add_shortcode('freebooks_order_support',[self::class,'support_shortcode']);
        add_shortcode('freebooks_case_lookup',[self::class,'case_lookup_shortcode']);
        add_action('admin_post_nopriv_fbos_submit_issue',[self::class,'submit_issue']);
        add_action('admin_post_fbos_submit_issue',[self::class,'submit_issue']);
        add_action('add_meta_boxes',[self::class,'add_issue_box']);
        add_action('save_post_fbos_order_issue',[self::class,'save_issue_status']);
        add_filter('manage_fbos_order_issue_posts_columns',[self::class,'issue_columns']);
        add_action('manage_fbos_order_issue_posts_custom_column',[self::class,'issue_column'],10,2);
        add_action('admin_menu',[self::class,'admin_menu']);
        add_action('admin_post_fbos_export_cases',[self::class,'export_cases']);
    }

    public static function register_type() {
        register_post_type(self::TYPE,[
            'labels'=>['name'=>'Customer Cases','singular_name'=>'Customer Case','menu_name'=>'Customer Support','add_new_item'=>'Add Customer Case','edit_item'=>'Review Customer Case'],
            'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'supports'=>['title','editor'],
            'capability_type'=>'post','map_meta_cap'=>true,'menu_icon'=>'dashicons-sos'
        ]);
    }

    private static function find_order($id,$email) {
        if(!function_exists('wc_get_order'))return false;
        $order=wc_get_order(absint($id)); if(!$order)return false;
        $stored=strtolower(trim((string)$order->get_billing_email()));
        $given=strtolower(trim((string)$email));
        return $stored&&$given&&hash_equals($stored,$given)?$order:false;
    }

    public static function support_shortcode() {
        if(!function_exists('wc_get_order'))return '<p>Order support is temporarily unavailable.</p>';
        ob_start(); ?>
        <section class="fb-core-support-request">
        <h2>Order support, returns and refunds</h2>
        <p>Submit a request using your order number and checkout billing email. Each request is reviewed by the support team. Approval is not automatic.</p>
        <form class="fb-core-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="fbos_submit_issue"><?php wp_nonce_field('fbos_issue','fbos_issue_nonce'); ?>
          <label>Order number <input type="text" name="order_id" required maxlength="40" autocomplete="off"></label>
          <label>Billing email <input type="email" name="billing_email" required maxlength="190" autocomplete="email"></label>
          <label>Request type <select name="issue_type" required>
          <?php foreach(self::KINDS as $key=>$label) echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>'; ?>
          </select></label>
          <label>Item name (if relevant) <input type="text" name="item_name" maxlength="190"></label>
          <label>Quantity affected <input type="number" name="quantity" min="1" max="99" value="1"></label>
          <label>Reason <select name="reason"><option value="">Select a reason</option><option value="wrong_item">Wrong item</option><option value="damaged">Damaged item</option><option value="missing">Item missing</option><option value="not_as_described">Not as described</option><option value="duplicate_payment">Possible duplicate payment</option><option value="payment_not_reflected">Payment not reflected</option><option value="other">Other</option></select></label>
          <label>Requested refund amount (optional) <input type="number" name="requested_amount" min="0" step="0.01"></label>
          <label>Describe the issue <textarea name="message" rows="5" required maxlength="4000"></textarea></label>
          <label>Evidence (optional; JPG, PNG, WebP or PDF, max 5 MB) <input type="file" name="fbos_evidence" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
          <p class="fb-core-muted">Returns are considered for wrong or damaged items under the published policy. A request does not itself issue a refund. Approved refunds must be processed and recorded in WooCommerce. For parcel location, use the carrier’s tracking link.</p>
          <button type="submit">Submit request</button>
        </form>
        <?php
        if(isset($_GET['fbos_case'])&&$_GET['fbos_case']==='sent') {
            $ref=sanitize_text_field(wp_unslash($_GET['ref']??''));
            echo '<p class="fb-core-success">Your request has been submitted. Reference: <strong>'.esc_html($ref).'</strong>. Save this reference for updates.</p>';
        }
        echo '</section>';
        return ob_get_clean();
    }

    public static function submit_issue() {
        if(!isset($_POST['fbos_issue_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbos_issue_nonce'])),'fbos_issue'))wp_die('Please refresh the page and try again.');
        $id=sanitize_text_field(wp_unslash($_POST['order_id']??''));
        $email=sanitize_email(wp_unslash($_POST['billing_email']??''));
        if(!ctype_digit($id))wp_die('Please enter a valid order number.');
        $order=self::find_order((int)$id,$email);
        if(!$order)wp_die('We could not match that order and billing email.');
        $kind=sanitize_key(wp_unslash($_POST['issue_type']??'support'));
        if(!isset(self::KINDS[$kind]))wp_die('Please select a valid request type.');
        $message=sanitize_textarea_field(wp_unslash($_POST['message']??''));
        if(!$message)wp_die('Please describe the issue.');
        $quantity=max(1,min(99,absint($_POST['quantity']??1)));
        $amount=isset($_POST['requested_amount'])&&$_POST['requested_amount']!==''?wc_format_decimal(wp_unslash($_POST['requested_amount'])):'';
        if($amount!==''&&(float)$amount<0)wp_die('Requested amount is invalid.');
        $ref='FB-'.strtoupper(wp_generate_password(8,false,false));
        $case=wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'private','post_title'=>'['.$ref.'] Order #'.$order->get_order_number().' — '.self::KINDS[$kind],'post_content'=>$message],true);
        if(is_wp_error($case)||!$case)wp_die('Unable to save your support request.');
        update_post_meta($case,'_fbos_order_id',$order->get_id());
        update_post_meta($case,'_fbos_customer_email',strtolower($email));
        update_post_meta($case,'_fbos_issue_type',$kind);
        update_post_meta($case,'_fbos_status','new');
        update_post_meta($case,'_fbos_reference',$ref);
        update_post_meta($case,'_fbos_item_name',sanitize_text_field(wp_unslash($_POST['item_name']??'')));
        update_post_meta($case,'_fbos_quantity',$quantity);
        update_post_meta($case,'_fbos_reason',sanitize_key(wp_unslash($_POST['reason']??'')));
        update_post_meta($case,'_fbos_requested_amount',$amount);
        update_post_meta($case,'_fbos_created_at',current_time('mysql',true));
        update_post_meta($case,'_fbos_events',[['time'=>current_time('mysql',true),'event'=>'submitted','note'=>'Customer submitted the request.']]);
        if(!empty($_FILES['fbos_evidence']['name'])) self::handle_evidence($case);
        if(class_exists('FB_Governance')) FB_Governance::log('customer_case_submitted',0,'Case '.$ref.' submitted for order #'.$order->get_order_number().'.');
        $subject='FreeBooks request received — '.$ref;
        $body="We received your request.\nReference: $ref\nOrder: #".$order->get_order_number()."\nRequest type: ".self::KINDS[$kind]."\n\nOur team will review it. This acknowledgement does not mean a return or refund has been approved.";
        wp_mail($email,$subject,$body);
        wp_mail(get_option('admin_email'),'New FreeBooks customer case — '.$ref,"Reference: $ref\nOrder: #".$order->get_order_number()."\nCustomer: $email\nType: ".self::KINDS[$kind]."\n\n$message\n\nReview under WordPress > Customer Support.");
        $referer=wp_get_referer()?:home_url('/');
        wp_safe_redirect(add_query_arg(['fbos_case'=>'sent','ref'=>$ref],$referer));exit;
    }


    /** Create a customer case from an authenticated FreeBooks account session. */
    public static function create_from_account($order, $user, $payload) {
        if (!$order || !$user || !is_array($payload)) return new WP_Error('fb_case_invalid','Please review your support request.',['status'=>400]);
        $throttle='fb_account_case_'.absint($user->ID);
        if (get_transient($throttle)) return new WP_Error('fb_case_rate','Please wait a minute before submitting another support request.',['status'=>429]);
        $kind=sanitize_key($payload['issue_type']??'support');
        if (!isset(self::KINDS[$kind])) return new WP_Error('fb_case_type','Select a valid request type.',['status'=>400]);
        $message=sanitize_textarea_field($payload['message']??'');
        if (!$message || strlen($message)>4000) return new WP_Error('fb_case_message','Describe the issue in 1–4000 characters.',['status'=>400]);
        $item_name=sanitize_text_field($payload['item_name']??'');
        if ($item_name) {
            $purchased=[]; foreach($order->get_items() as $line) $purchased[]=(string)$line->get_name();
            if (!in_array($item_name,$purchased,true)) return new WP_Error('fb_case_item','Choose an item from this order.',['status'=>400]);
        }
        $quantity=max(1,min(99,absint($payload['quantity']??1)));
        if ($item_name) {
            foreach ($order->get_items() as $line) {
                if ((string)$line->get_name()===$item_name && $quantity>(int)$line->get_quantity()) return new WP_Error('fb_case_quantity','Quantity affected cannot exceed the quantity purchased.',['status'=>400]);
            }
        }
        $reason=sanitize_key($payload['reason']??'');
        $allowed_reasons=['wrong_item','damaged','missing','not_as_described','duplicate_payment','payment_not_reflected','other'];
        if ($reason && !in_array($reason,$allowed_reasons,true)) return new WP_Error('fb_case_reason','Select a valid reason.',['status'=>400]);
        $amount='';
        if (isset($payload['requested_amount']) && $payload['requested_amount']!=='') {
            $amount=wc_format_decimal($payload['requested_amount']);
            if ($amount==='' || (float)$amount<0 || (float)$amount>(float)$order->get_total()) return new WP_Error('fb_case_amount','Requested amount is invalid.',['status'=>400]);
        }
        $ref='FB-'.strtoupper(wp_generate_password(8,false,false));
        $case=wp_insert_post([
            'post_type'=>self::TYPE,'post_status'=>'private',
            'post_title'=>'['.$ref.'] Order #'.$order->get_order_number().' — '.self::KINDS[$kind],
            'post_content'=>$message
        ],true);
        if (is_wp_error($case) || !$case) return new WP_Error('fb_case_save','Your request could not be saved. Please try again.',['status'=>500]);
        update_post_meta($case,'_fbos_order_id',$order->get_id());
        update_post_meta($case,'_fbos_customer_email',strtolower((string)$user->user_email));
        update_post_meta($case,'_fbos_issue_type',$kind);
        update_post_meta($case,'_fbos_status','new');
        update_post_meta($case,'_fbos_reference',$ref);
        update_post_meta($case,'_fbos_item_name',$item_name);
        update_post_meta($case,'_fbos_quantity',$quantity);
        update_post_meta($case,'_fbos_reason',$reason);
        update_post_meta($case,'_fbos_requested_amount',$amount);
        update_post_meta($case,'_fbos_created_at',current_time('mysql',true));
        update_post_meta($case,'_fbos_events',[['time'=>current_time('mysql',true),'event'=>'submitted','note'=>'Customer submitted the request from their FreeBooks account.']]);
        set_transient($throttle,1,MINUTE_IN_SECONDS);
        if (class_exists('FB_Governance')) FB_Governance::log('customer_case_submitted',0,'Case '.$ref.' submitted for order #'.$order->get_order_number().'.');
        $email=sanitize_email($user->user_email);
        if ($email && is_email($email)) {
            $body="We received your request.\nReference: $ref\nOrder: #".$order->get_order_number()."\nRequest type: ".self::KINDS[$kind]."\n\nOur team will review it. This acknowledgement does not mean a return or refund has been approved.";
            wp_mail($email,'FreeBooks request received — '.$ref,$body,['Content-Type: text/plain; charset=UTF-8']);
        }
        $admin_email=sanitize_email(get_option('admin_email'));
        if ($admin_email && is_email($admin_email)) wp_mail($admin_email,'New FreeBooks customer case — '.$ref,"Reference: $ref\nOrder: #".$order->get_order_number()."\nCustomer: ".$user->user_email."\nType: ".self::KINDS[$kind]."\n\n$message");
        return rest_ensure_response(['success'=>true,'reference'=>$ref,'status'=>'new','message'=>'Your request has been received. Our team will review it.']);
    }

    private static function handle_evidence($case_id) {
        if(!function_exists('media_handle_upload'))require_once ABSPATH.'wp-admin/includes/file.php';
        if(!function_exists('media_handle_upload'))require_once ABSPATH.'wp-admin/includes/media.php';
        if(!function_exists('wp_read_image_metadata'))require_once ABSPATH.'wp-admin/includes/image.php';
        $file=$_FILES['fbos_evidence'];
        if(!empty($file['error']))return;
        if((int)$file['size']>5*1024*1024)return;
        $check=wp_check_filetype_and_ext($file['tmp_name'],$file['name']);
        $allowed=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];
        if(empty($check['ext'])||!isset($allowed[strtolower($check['ext'])])||$check['type']!==$allowed[strtolower($check['ext'])])return;
        $attachment=media_handle_upload('fbos_evidence',$case_id,['post_title'=>'Evidence '.$case_id]);
        if(!is_wp_error($attachment))update_post_meta($case_id,'_fbos_evidence_id',absint($attachment));
    }

    public static function case_lookup_shortcode() {
        $result='';
        if(isset($_POST['fbos_lookup_submit'])) {
            $nonce=sanitize_text_field(wp_unslash($_POST['fbos_lookup_nonce']??''));
            if(wp_verify_nonce($nonce,'fbos_case_lookup')) {
                $ref=sanitize_text_field(wp_unslash($_POST['case_reference']??''));
                $email=strtolower(sanitize_email(wp_unslash($_POST['case_email']??'')));
                $ids=get_posts(['post_type'=>self::TYPE,'post_status'=>'private','numberposts'=>1,'meta_key'=>'_fbos_reference','meta_value'=>$ref,'fields'=>'ids']);
                if($ids) {
                    $case=$ids[0];$stored=strtolower((string)get_post_meta($case,'_fbos_customer_email',true));
                    if($stored&&$email&&hash_equals($stored,$email)) {
                        $status=get_post_meta($case,'_fbos_status',true)?:'new';
                        $result='<div class="fb-core-order-result"><h3>Request '.esc_html($ref).'</h3><p><strong>Status:</strong> '.esc_html(self::STATUSES[$status]??ucwords(str_replace('_',' ',$status))).'</p><p><strong>Request:</strong> '.esc_html(self::KINDS[get_post_meta($case,'_fbos_issue_type',true)]??'Support').'</p><p><strong>Last update:</strong> '.esc_html(get_post_modified_time('F j, Y g:i a',false,$case)).'</p><p>Our support team will contact you by email when a response is needed.</p></div>';
                    }
                }
                if(!$result)$result='<p class="fb-core-error">We could not find a matching request. Check the reference and email.</p>';
            } else $result='<p class="fb-core-error">Your session expired. Please refresh and try again.</p>';
        }
        ob_start(); ?>
        <section class="fb-core-order-lookup"><h2>Check a support request</h2>
        <form class="fb-core-form" method="post"><?php wp_nonce_field('fbos_case_lookup','fbos_lookup_nonce'); ?>
        <label>Case reference<input name="case_reference" required maxlength="30"></label>
        <label>Billing email<input type="email" name="case_email" required maxlength="190"></label>
        <button type="submit" name="fbos_lookup_submit" value="1">Check request</button></form>
        <?php echo $result; ?></section>
        <?php return ob_get_clean();
    }

    public static function add_issue_box() {
        add_meta_box('fbos_case_details','Customer case management',[self::class,'case_box'],self::TYPE,'normal','high');
    }

    public static function case_box($post) {
        wp_nonce_field('fbos_case_save','fbos_case_nonce');
        $status=get_post_meta($post->ID,'_fbos_status',true)?:'new';
        $kind=get_post_meta($post->ID,'_fbos_issue_type',true)?:'support';
        $evidence=absint(get_post_meta($post->ID,'_fbos_evidence_id',true));
        $events=(array)get_post_meta($post->ID,'_fbos_events',true);
        $assignee=absint(get_post_meta($post->ID,'_fbos_assignee',true));
        $priority=get_post_meta($post->ID,'_fbos_priority',true)?:'normal';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">';
        echo '<p><strong>Reference:</strong> '.esc_html(get_post_meta($post->ID,'_fbos_reference',true)).'</p>';
        echo '<p><strong>Order ID:</strong> '.esc_html((string)get_post_meta($post->ID,'_fbos_order_id',true)).'</p>';
        echo '<p><strong>Customer:</strong> '.esc_html(get_post_meta($post->ID,'_fbos_customer_email',true)).'</p>';
        echo '<p><strong>Request type:</strong> '.esc_html(self::KINDS[$kind]??$kind).'</p>';
        echo '<p><strong>Item / quantity:</strong> '.esc_html(get_post_meta($post->ID,'_fbos_item_name',true)).' / '.absint(get_post_meta($post->ID,'_fbos_quantity',true)).'</p>';
        echo '<p><strong>Reason:</strong> '.esc_html(get_post_meta($post->ID,'_fbos_reason',true)).'</p>';
        echo '<p><strong>Requested amount:</strong> '.esc_html((string)get_post_meta($post->ID,'_fbos_requested_amount',true)).'</p>';
        echo '</div><hr><p><label><strong>Status</strong><br><select name="fbos_status">';
        foreach(self::STATUSES as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($status,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label> &nbsp; <label><strong>Priority</strong><br><select name="fbos_priority">';
        foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($priority,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label> &nbsp; <label><strong>Assign to</strong><br><select name="fbos_assignee"><option value="0">Unassigned</option>';
        foreach(get_users(['orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name']]) as $u)echo '<option value="'.absint($u->ID).'" '.selected($assignee,$u->ID,false).'>'.esc_html($u->display_name).'</option>';
        echo '</select></label></p>';
        echo '<p><label><strong>Resolution / staff response note</strong><br><textarea name="fbos_resolution" rows="4" style="width:100%">'.esc_textarea(get_post_meta($post->ID,'_fbos_resolution',true)).'</textarea></label></p>';
        echo '<p><label><strong>Internal note (not emailed to customer)</strong><br><textarea name="fbos_internal_note" rows="3" style="width:100%"></textarea></label></p>';
        if($evidence)echo '<p><strong>Customer evidence:</strong> <a href="'.esc_url(wp_get_attachment_url($evidence)).'" target="_blank" rel="noopener">Open uploaded file</a></p>';
        echo '<p class="description">For approved refunds, open the WooCommerce order and use its Refund action. This case manager never issues a payment refund automatically. Carrier shipment tracking and labels remain in your carrier plugin.</p>';
        echo '<h4>Case activity</h4><ol>';
        foreach(array_slice(array_reverse($events),0,30) as $event)echo '<li>'.esc_html($event['time']??'').' — '.esc_html($event['event']??'update').(!empty($event['note'])?' — '.esc_html($event['note']):'').'</li>';
        echo '</ol>';
    }

    public static function save_issue_status($post_id) {
        if(!isset($_POST['fbos_case_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbos_case_nonce'])),'fbos_case_save'))return;
        if((defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||!current_user_can('edit_post',$post_id))return;
        $old=get_post_meta($post_id,'_fbos_status',true)?:'new';
        $status=sanitize_key(wp_unslash($_POST['fbos_status']??$old));
        if(!isset(self::STATUSES[$status]))$status=$old;
        $priority=sanitize_key(wp_unslash($_POST['fbos_priority']??'normal'));
        if(!in_array($priority,['low','normal','high','urgent'],true))$priority='normal';
        $assignee=absint($_POST['fbos_assignee']??0);
        $resolution=sanitize_textarea_field(wp_unslash($_POST['fbos_resolution']??''));
        update_post_meta($post_id,'_fbos_status',$status);
        update_post_meta($post_id,'_fbos_priority',$priority);
        update_post_meta($post_id,'_fbos_assignee',$assignee);
        update_post_meta($post_id,'_fbos_resolution',$resolution);
        $events=(array)get_post_meta($post_id,'_fbos_events',true);
        if($old!==$status) {
            $events[]=['time'=>current_time('mysql',true),'event'=>'status_'.$status,'note'=>'Status changed from '.$old.' to '.$status.'.'];
            $email=(string)get_post_meta($post_id,'_fbos_customer_email',true);
            if($email)wp_mail($email,'FreeBooks case update — '.get_post_meta($post_id,'_fbos_reference',true),"Your FreeBooks request status has been updated to: ".(self::STATUSES[$status]??$status).".\n\n".($resolution?"Support note: ".$resolution:"")."\n\nThis status update does not itself issue a refund.");
        }
        if(!empty($_POST['fbos_internal_note']))$events[]=['time'=>current_time('mysql',true),'event'=>'staff_note','note'=>sanitize_textarea_field(wp_unslash($_POST['fbos_internal_note']))];
        update_post_meta($post_id,'_fbos_events',array_slice($events,-100));
        if(class_exists('FB_Governance')&&($old!==$status||!empty($_POST['fbos_internal_note'])))FB_Governance::log('customer_case_updated',0,'Case '.get_post_meta($post_id,'_fbos_reference',true).' updated.');
    }

    public static function issue_columns($cols) {
        $cols['fbos_reference']='Reference';$cols['fbos_order']='Order';$cols['fbos_email']='Customer';$cols['fbos_kind']='Type';$cols['fbos_priority']='Priority';$cols['fbos_status']='Status';return $cols;
    }
    public static function issue_column($col,$id) {
        $map=['fbos_reference'=>'_fbos_reference','fbos_order'=>'_fbos_order_id','fbos_email'=>'_fbos_customer_email','fbos_priority'=>'_fbos_priority','fbos_status'=>'_fbos_status'];
        if(isset($map[$col]))echo esc_html((string)get_post_meta($id,$map[$col],true));
        if($col==='fbos_kind'){$k=get_post_meta($id,'_fbos_issue_type',true);echo esc_html(self::KINDS[$k]??$k);}
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type='.self::TYPE,'Support Overview','Support Overview','manage_options','fbos-overview',[self::class,'overview']);
    }

    public static function overview() {
        if(!current_user_can('manage_options'))return;
        $q=new WP_Query(['post_type'=>self::TYPE,'post_status'=>'private','posts_per_page'=>-1,'fields'=>'ids']);
        $counts=array_fill_keys(array_keys(self::STATUSES),0);$types=array_fill_keys(array_keys(self::KINDS),0);$urgent=0;$unassigned=0;
        foreach($q->posts as $id){$s=get_post_meta($id,'_fbos_status',true)?:'new';if(isset($counts[$s]))$counts[$s]++;$k=get_post_meta($id,'_fbos_issue_type',true)?:'support';if(isset($types[$k]))$types[$k]++;if(in_array(get_post_meta($id,'_fbos_priority',true),['high','urgent'],true))$urgent++;if(!get_post_meta($id,'_fbos_assignee',true))$unassigned++;}
        echo '<div class="wrap"><h1>Customer Support & Returns</h1><p>Review requests, assign staff, record decisions and reconcile approved refunds in WooCommerce.</p><h2>Case status</h2><table class="widefat striped" style="max-width:760px"><thead><tr><th>Status</th><th>Cases</th></tr></thead><tbody>';
        foreach(self::STATUSES as $s=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.absint($counts[$s]).'</td></tr>';
        echo '</tbody></table><h2>Request categories</h2><table class="widefat striped" style="max-width:760px"><thead><tr><th>Category</th><th>Cases</th></tr></thead><tbody>';
        foreach(self::KINDS as $k=>$label)echo '<tr><td>'.esc_html($label).'</td><td>'.absint($types[$k]).'</td></tr>';
        echo '</tbody></table><p><strong>High/urgent:</strong> '.absint($urgent).' &nbsp; <strong>Unassigned:</strong> '.absint($unassigned).' &nbsp; <strong>Total:</strong> '.absint(count($q->posts)).'</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('edit.php?post_type='.self::TYPE)).'">Open case queue</a> <a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=fbos_export_cases'),'fbos_export_cases')).'">Export cases CSV</a></p>';
        echo '<h2>Refund handling rule</h2><p>Approve or decline the request here, then use WooCommerce order management to perform any approved refund. Confirm the gateway result and record the WooCommerce refund ID in the case resolution note. This plugin does not promise or execute automatic refunds.</p>';
        echo '<h2>Shipping handling rule</h2><p>Use your shipping carrier plugin for return labels, shipment booking and parcel tracking. FreeBooks Core records the customer concern but does not duplicate carrier tracking.</p></div>';
    }

    public static function export_cases() {
        if(!current_user_can('manage_options')||!check_admin_referer('fbos_export_cases'))wp_die('Not authorised.',403);
        $ids=get_posts(['post_type'=>self::TYPE,'post_status'=>'private','numberposts'=>-1,'fields'=>'ids','orderby'=>'date','order'=>'DESC']);
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=freebooks-customer-cases-'.gmdate('Y-m-d').'.csv');
        $out=fopen('php://output','w');fputcsv($out,['Reference','Order ID','Customer email','Type','Status','Priority','Item','Quantity','Reason','Requested amount','Assignee','Created','Resolution']);
        foreach($ids as $id){$uid=absint(get_post_meta($id,'_fbos_assignee',true));$u=$uid?get_user_by('id',$uid):false;$row=[get_post_meta($id,'_fbos_reference',true),get_post_meta($id,'_fbos_order_id',true),get_post_meta($id,'_fbos_customer_email',true),get_post_meta($id,'_fbos_issue_type',true),get_post_meta($id,'_fbos_status',true),get_post_meta($id,'_fbos_priority',true),get_post_meta($id,'_fbos_item_name',true),get_post_meta($id,'_fbos_quantity',true),get_post_meta($id,'_fbos_reason',true),get_post_meta($id,'_fbos_requested_amount',true),$u?$u->display_name:'',get_post_meta($id,'_fbos_created_at',true),get_post_meta($id,'_fbos_resolution',true)];fputcsv($out,array_map([self::class,'csv_safe'],$row));}
        fclose($out);exit;
    }
    private static function csv_safe($v){$v=(string)$v;return preg_match('/^[\s]*[=+@\-]/u',$v)?"'".$v:$v;}
}
