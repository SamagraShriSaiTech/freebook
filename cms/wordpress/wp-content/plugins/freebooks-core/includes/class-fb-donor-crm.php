<?php
if (!defined('ABSPATH')) exit;

/**
 * Donor CRM, campaign attribution and controlled bulk donation intake.
 * Marketing preferences are kept separate from operational donation updates.
 */
class FB_Donor_CRM {
    const CAMPAIGN_CPT = 'fb_campaign';
    const MAX_IMPORT_ROWS = 500;

    public static function init() {
        add_action('init', [self::class, 'register_campaign_type'], 25);
        add_action('admin_menu', [self::class, 'menu'], 45);
        add_action('add_meta_boxes', [self::class, 'add_campaign_metabox']);
        add_action('save_post_' . FREEBOOKS_DONATION_CPT, [self::class, 'save_campaign_metabox']);
        add_action('admin_post_fbcrm_import', [self::class, 'import_csv']);
    }

    public static function register_campaign_type() {
        register_post_type(self::CAMPAIGN_CPT, [
            'labels' => ['name'=>'Donation campaigns','singular_name'=>'Donation campaign','add_new_item'=>'Add donation campaign','edit_item'=>'Edit donation campaign'],
            'public'=>false, 'show_ui'=>true, 'show_in_menu'=>'edit.php?post_type='.FREEBOOKS_DONATION_CPT,
            'supports'=>['title','editor'], 'capability_type'=>'post', 'map_meta_cap'=>true,
            'menu_position'=>30, 'menu_icon'=>'dashicons-megaphone'
        ]);
    }

    public static function menu() {
        add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT, 'Donor CRM', 'Donor CRM', 'edit_fb_donations', 'fb-donor-crm', [self::class,'crm_page']);
        add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT, 'Campaign reports', 'Campaign reports', 'export_fb_reports', 'fb-campaign-reports', [self::class,'campaign_reports']);
        add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT, 'Bulk donation intake', 'Bulk intake', 'edit_fb_donations', 'fb-bulk-donation-intake', [self::class,'import_page']);
    }

    private static function donation_ids() {
        return get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>-1,'fields'=>'ids','orderby'=>'date','order'=>'DESC']);
    }

    private static function donor_key($email) {
        return strtolower(trim(sanitize_email($email)));
    }

    public static function crm_page() {
        if (!current_user_can('edit_fb_donations')) wp_die('Permission denied.',403);
        $needle = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $segment = sanitize_key(wp_unslash($_GET['segment'] ?? 'all'));
        $groups = [];
        foreach (self::donation_ids() as $id) {
            $donor = (array)get_post_meta($id,'_fb_donor',true);
            $email = self::donor_key($donor['email'] ?? '');
            if (!$email) continue;
            if ($needle && stripos(implode(' ',[$donor['name']??'',$email,$donor['phone']??'',$donor['city']??'']),$needle)===false) continue;
            if (!isset($groups[$email])) $groups[$email] = ['donor'=>$donor,'ids'=>[],'items'=>0,'last'=>'','statuses'=>[]];
            $groups[$email]['ids'][] = $id;
            $groups[$email]['items'] += array_sum(array_map(function($item){return max(1,absint($item['quantity']??1));},(array)get_post_meta($id,'_fb_items',true)));
            $groups[$email]['statuses'][] = get_post_meta($id,'_fb_status',true) ?: 'submitted';
            $date = get_post_time('Y-m-d H:i',false,$id);
            if (!$groups[$email]['last'] || $date > $groups[$email]['last']) $groups[$email]['last'] = $date;
        }
        if ($segment==='repeat') $groups=array_filter($groups,function($g){return count($g['ids'])>1;});
        elseif ($segment==='first_time') $groups=array_filter($groups,function($g){return count($g['ids'])===1;});
        elseif ($segment==='opted_in') $groups=array_filter($groups,function($g){return !empty($g['donor']['marketing_opt_in']);});
        elseif ($segment==='not_opted_in') $groups=array_filter($groups,function($g){return empty($g['donor']['marketing_opt_in']);});
        uasort($groups,function($a,$b){return count($b['ids']) <=> count($a['ids']);});
        echo '<div class="wrap"><h1>Donor CRM</h1><p>Donor profiles are grouped by email address. Donation records remain the source of truth; no donor account is created automatically.</p>';
        echo '<form method="get"><input type="hidden" name="post_type" value="'.esc_attr(FREEBOOKS_DONATION_CPT).'"><input type="hidden" name="page" value="fb-donor-crm"><input type="search" name="s" value="'.esc_attr($needle).'" placeholder="Search name, email, phone or city"> <select name="segment"><option value="all" '.selected($segment,'all',false).'>All donors</option><option value="repeat" '.selected($segment,'repeat',false).'>Repeat donors</option><option value="first_time" '.selected($segment,'first_time',false).'>First-time donors</option><option value="opted_in" '.selected($segment,'opted_in',false).'>Marketing opted-in</option><option value="not_opted_in" '.selected($segment,'not_opted_in',false).'>Not opted-in</option></select> <button class="button">Apply filters</button></form>';
        echo '<table class="widefat striped" style="margin-top:14px"><thead><tr><th>Donor</th><th>Contact</th><th>City</th><th>Donations</th><th>Items</th><th>Latest donation</th><th>Marketing preference</th><th>Preferred contact</th><th>History</th></tr></thead><tbody>';
        if (!$groups) echo '<tr><td colspan="9">No matching donor profiles.</td></tr>';
        foreach ($groups as $email=>$g) {
            $d=$g['donor'];
            echo '<tr><td><strong>'.esc_html($d['name']??'').'</strong></td><td>'.esc_html($email).'<br>'.esc_html($d['phone']??'').'</td><td>'.esc_html($d['city']??'').'</td><td>'.count($g['ids']).'</td><td>'.absint($g['items']).'</td><td>'.esc_html($g['last']).'</td><td>'.(!empty($d['marketing_opt_in'])?'Opted in':'Not opted in').'</td><td>'.esc_html(ucfirst($d['preferred_contact']??'none')).'</td><td><details><summary>View '.count($g['ids']).' record(s)</summary><ul>';
            foreach ($g['ids'] as $id) echo '<li>'.esc_html(get_post_meta($id,'_fb_reference',true)).' — '.esc_html(get_the_date('Y-m-d',$id)).' — '.esc_html(ucwords(str_replace('_',' ',get_post_meta($id,'_fb_status',true)?:'submitted'))).'</li>';
            echo '</ul></details></td></tr>';
        }
        echo '</tbody></table><p><strong>Privacy:</strong> use donor details only for legitimate donation administration. Marketing contact requires a separate, recorded opt-in.</p></div>';
    }

    public static function add_campaign_metabox() {
        add_meta_box('fb_donation_campaign','Donation campaign',[self::class,'campaign_metabox'],FREEBOOKS_DONATION_CPT,'side','default');
    }

    public static function campaign_metabox($post) {
        wp_nonce_field('fbcrm_save_campaign_'.$post->ID,'fbcrm_campaign_nonce');
        $selected=absint(get_post_meta($post->ID,'_fb_campaign_id',true));
        $campaigns=get_posts(['post_type'=>self::CAMPAIGN_CPT,'post_status'=>['publish','draft','private'],'numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
        echo '<label for="fbcrm_campaign_id">Attribute this donation to</label><select name="fbcrm_campaign_id" id="fbcrm_campaign_id" style="width:100%;margin-top:6px"><option value="0">— General / unassigned —</option>';
        foreach($campaigns as $campaign) echo '<option value="'.absint($campaign->ID).'" '.selected($selected,$campaign->ID,false).'>'.esc_html($campaign->post_title).'</option>';
        echo '</select><p class="description">Campaign attribution does not change donation status or inventory.</p>';
    }

    public static function save_campaign_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['fbcrm_campaign_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbcrm_campaign_nonce'])),'fbcrm_save_campaign_'.$post_id)) return;
        if (!current_user_can('edit_post',$post_id)) return;
        $campaign=absint($_POST['fbcrm_campaign_id']??0);
        if ($campaign && get_post_type($campaign)!==self::CAMPAIGN_CPT) $campaign=0;
        if ($campaign) update_post_meta($post_id,'_fb_campaign_id',$campaign);
        else delete_post_meta($post_id,'_fb_campaign_id');
    }

    public static function campaign_reports() {
        if (!current_user_can('export_fb_reports')) wp_die('Permission denied.',403);
        $campaigns=get_posts(['post_type'=>self::CAMPAIGN_CPT,'post_status'=>['publish','draft','private'],'numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
        $stats=[];
        foreach($campaigns as $c) $stats[$c->ID]=['title'=>$c->post_title,'donations'=>0,'received'=>0,'items'=>0,'sold'=>0];
        $stats[0]=['title'=>'General / unassigned','donations'=>0,'received'=>0,'items'=>0,'sold'=>0];
        foreach(self::donation_ids() as $id) {
            $cid=absint(get_post_meta($id,'_fb_campaign_id',true));
            if (!isset($stats[$cid])) $cid=0;
            $stats[$cid]['donations']++;
            $status=get_post_meta($id,'_fb_status',true)?:'submitted';
            if (in_array($status,['received','listed','sold'],true)) $stats[$cid]['received']++;
            if ($status==='sold') $stats[$cid]['sold']++;
            foreach((array)get_post_meta($id,'_fb_items',true) as $item) $stats[$cid]['items']+=max(1,absint($item['quantity']??1));
        }
        echo '<div class="wrap"><h1>Donation campaign performance</h1><p>Counts are based on donation records and their current status. They do not represent monetary donations or verified student impact.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Campaign</th><th>Donations</th><th>Received / listed / sold</th><th>Items submitted</th><th>Donations marked sold</th></tr></thead><tbody>';
        foreach($stats as $row) echo '<tr><td>'.esc_html($row['title']).'</td><td>'.absint($row['donations']).'</td><td>'.absint($row['received']).'</td><td>'.absint($row['items']).'</td><td>'.absint($row['sold']).'</td></tr>';
        echo '</tbody></table><p><a class="button" href="'.esc_url(admin_url('edit.php?post_type='.self::CAMPAIGN_CPT)).'">Manage campaigns</a></p></div>';
    }

    public static function import_page() {
        if (!current_user_can('edit_fb_donations')) wp_die('Permission denied.',403);
        echo '<div class="wrap"><h1>Bulk donation intake</h1><p>Import up to '.self::MAX_IMPORT_ROWS.' donation item rows from a CSV. Each valid row becomes an individual donation record and follows the existing review workflow.</p>';
        if (isset($_GET['fbcrm_imported'])) echo '<div class="notice notice-success"><p>Imported '.absint($_GET['fbcrm_imported']).' donation record(s). Skipped: '.absint($_GET['fbcrm_skipped']??0).'.</p></div>';
        echo '<p>Required CSV columns: <code>name,email,phone,title,category</code>. Optional: <code>city,quantity,condition,details,handover,campaign_id</code>.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fbcrm_import">';
        wp_nonce_field('fbcrm_import_csv'); echo '<input type="file" name="fbcrm_csv" accept=".csv,text/csv" required> '; submit_button('Import CSV','primary','submit',false); echo '</form>';
        echo '<p>CSV template header: <code>name,email,phone,city,title,category,quantity,condition,details,handover,campaign_id</code>. This tool does not send marketing messages.</p></div>';
    }

    public static function import_csv() {
        if (!current_user_can('edit_fb_donations')) wp_die('Permission denied.',403);
        check_admin_referer('fbcrm_import_csv');
        if (empty($_FILES['fbcrm_csv']['tmp_name']) || !is_uploaded_file($_FILES['fbcrm_csv']['tmp_name'])) wp_die('Please upload a valid CSV file.',400);
        if (!empty($_FILES['fbcrm_csv']['size']) && (int)$_FILES['fbcrm_csv']['size'] > 5*1024*1024) wp_die('CSV must be 5 MB or smaller.',400);
        $fh=fopen($_FILES['fbcrm_csv']['tmp_name'],'r');
        if (!$fh) wp_die('Could not read the uploaded CSV.',400);
        $headers=fgetcsv($fh);
        if (!$headers) { fclose($fh); wp_die('CSV header row is missing.',400); }
        $headers=array_map(function($h){return strtolower(trim((string)$h));},$headers);
        foreach(['name','email','phone','title','category'] as $required) if (!in_array($required,$headers,true)) { fclose($fh); wp_die('Missing required CSV column: '.esc_html($required),400); }
        $imported=0; $skipped=0; $rows=0;
        while (($values=fgetcsv($fh))!==false) {
            if (++$rows > self::MAX_IMPORT_ROWS) { $skipped++; continue; }
            if (count(array_filter($values,function($v){return trim((string)$v)!=='';}))===0) continue;
            $row=[]; foreach($headers as $i=>$key) $row[$key]=sanitize_text_field($values[$i]??'');
            if (!is_email($row['email']??'') || empty($row['name']) || empty($row['phone']) || empty($row['title']) || empty($row['category'])) { $skipped++; continue; }
            $item=['title'=>$row['title'],'category'=>$row['category'],'quantity'=>max(1,absint($row['quantity']??1)),'condition'=>$row['condition']??'','details'=>$row['details']??''];
            $result=FB_Donations::create(['donor'=>['name'=>$row['name'],'email'=>$row['email'],'phone'=>$row['phone'],'city'=>$row['city']??''],'items'=>[$item],'handover'=>$row['handover']??'','message'=>'Imported through staff bulk intake.','consent'=>true]);
            if (is_wp_error($result)) { $skipped++; continue; }
            $campaign=absint($row['campaign_id']??0);
            if ($campaign && get_post_type($campaign)===self::CAMPAIGN_CPT) update_post_meta($result['id'],'_fb_campaign_id',$campaign);
            if (class_exists('FB_Governance')) FB_Governance::log('bulk_donation_imported',$result['id'],'Donation imported through staff CSV intake.');
            $imported++;
        }
        fclose($fh);
        wp_safe_redirect(add_query_arg(['page'=>'fb-bulk-donation-intake','fbcrm_imported'=>$imported,'fbcrm_skipped'=>$skipped],admin_url('edit.php?post_type='.FREEBOOKS_DONATION_CPT)));
        exit;
    }
}
