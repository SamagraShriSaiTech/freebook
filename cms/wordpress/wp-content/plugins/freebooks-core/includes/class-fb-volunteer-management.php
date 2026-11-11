<?php
if (!defined('ABSPATH')) exit;

/**
 * Volunteer task assignments, attendance and activity reporting.
 * Volunteers can only view and record activity for tasks assigned to their account.
 */
class FB_Volunteer_Management {
    const CPT = 'fb_volunteer_task';

    public static function register_types() {
        register_post_type(self::CPT, [
            'labels'=>[
                'name'=>'Volunteer Tasks',
                'singular_name'=>'Volunteer Task',
                'add_new_item'=>'Add Volunteer Task',
                'edit_item'=>'Edit Volunteer Task'
            ],
            'public'=>false,
            'show_ui'=>true,
            'show_in_menu'=>true,
            'show_in_rest'=>false,
            'supports'=>['title','editor'],
            'capability_type'=>'post',
            'map_meta_cap'=>true,
            'menu_icon'=>'dashicons-clipboard'
        ]);
        register_post_status('fbvm_completed',[
            'label'=>'Completed',
            'public'=>false,
            'internal'=>true,
            'show_in_admin_all_list'=>true,
            'show_in_admin_status_list'=>true,
            'label_count'=>_n_noop('Completed <span class="count">(%s)</span>','Completed <span class="count">(%s)</span>')
        ]);
    }

    public static function menu() {
        add_submenu_page(
            'edit.php?post_type='.self::CPT,
            'Volunteer Activity Reports',
            'Activity reports',
            'manage_options',
            'fbvm-reports',
            [self::class,'reports_page']
        );
    }

    public static function add_meta_box() {
        add_meta_box('fbvm_task_details','Assignment details',[self::class,'render_meta_box'],self::CPT,'normal','high');
    }

    public static function render_meta_box($post) {
        wp_nonce_field('fbvm_save_task_'.$post->ID,'fbvm_task_nonce');
        $assigned=absint(get_post_meta($post->ID,'_fbvm_assigned_user',true));
        $due=(string)get_post_meta($post->ID,'_fbvm_due_date',true);
        $location=(string)get_post_meta($post->ID,'_fbvm_location',true);
        $hours=(float)get_post_meta($post->ID,'_fbvm_estimated_hours',true);
        $status=get_post_meta($post->ID,'_fbvm_status',true)?:'open';
        $users=get_users(['orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name','user_email'],'number'=>500]);
        echo '<p><label for="fbvm_assigned_user"><strong>Assign to volunteer</strong></label><br><select name="fbvm_assigned_user" id="fbvm_assigned_user" style="min-width:320px"><option value="0">Unassigned</option>';
        foreach($users as $user) echo '<option value="'.esc_attr($user->ID).'" '.selected($assigned,$user->ID,false).'>'.esc_html($user->display_name.' — '.$user->user_email).'</option>';
        echo '</select></p>';
        echo '<p><label><strong>Due date</strong><br><input type="date" name="fbvm_due_date" value="'.esc_attr($due).'"></label></p>';
        echo '<p><label><strong>Location / work mode</strong><br><input type="text" class="regular-text" name="fbvm_location" maxlength="180" value="'.esc_attr($location).'" placeholder="Warehouse / Remote / City"></label></p>';
        echo '<p><label><strong>Estimated hours</strong><br><input type="number" min="0" max="1000" step="0.25" name="fbvm_estimated_hours" value="'.esc_attr($hours).'"></label></p>';
        echo '<p><label><strong>Task status</strong><br><select name="fbvm_status">';
        foreach(['open'=>'Open','assigned'=>'Assigned','in_progress'=>'In progress','completed'=>'Completed','cancelled'=>'Cancelled'] as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($status,$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label></p>';
        echo '<p class="description">Write task instructions in the main editor. Assigned volunteers see the task in their Volunteer Portal.</p>';
        $events=(array)get_post_meta($post->ID,'_fbvm_events',true);
        if ($events) {
            echo '<hr><h3>Recent volunteer activity</h3><ul>';
            foreach(array_slice(array_reverse($events),0,8) as $event) {
                $actor=!empty($event['user'])?get_user_by('id',(int)$event['user']):false;
                echo '<li><strong>'.esc_html($event['at']??'').'</strong> — '.esc_html($event['type']??'').': '.esc_html($event['details']??'').' <em>'.esc_html($actor?$actor->display_name:'System').'</em></li>';
            }
            echo '</ul>';
        }
    }

    public static function save_task($post_id) {
        if (!isset($_POST['fbvm_task_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbvm_task_nonce'])),'fbvm_save_task_'.$post_id)) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || !current_user_can('edit_post',$post_id)) return;
        $assigned=absint($_POST['fbvm_assigned_user']??0);
        $old=absint(get_post_meta($post_id,'_fbvm_assigned_user',true));
        if ($assigned) update_post_meta($post_id,'_fbvm_assigned_user',$assigned);
        else delete_post_meta($post_id,'_fbvm_assigned_user');
        update_post_meta($post_id,'_fbvm_due_date',sanitize_text_field(wp_unslash($_POST['fbvm_due_date']??'')));
        update_post_meta($post_id,'_fbvm_location',sanitize_text_field(wp_unslash($_POST['fbvm_location']??'')));
        update_post_meta($post_id,'_fbvm_estimated_hours',max(0,min(1000,(float)($_POST['fbvm_estimated_hours']??0))));
        $status=sanitize_key(wp_unslash($_POST['fbvm_status']??'open'));
        if (!in_array($status,['open','assigned','in_progress','completed','cancelled'],true)) $status='open';
        if ($assigned && $status==='open') $status='assigned';
        update_post_meta($post_id,'_fbvm_status',$status);
        if ($old!==$assigned) {
            self::record_event($post_id,'assignment_changed','Task assignment updated by staff.',get_current_user_id());
            if ($assigned) {
                $volunteer=get_user_by('id',$assigned);
                if ($volunteer && is_email($volunteer->user_email)) {
                    $due=get_post_meta($post_id,'_fbvm_due_date',true);
                    $body="Hello ".$volunteer->display_name.",\n\nA FreeBooks volunteer task has been assigned to you: ".get_the_title($post_id)."\n";
                    if ($due) $body.="Due date: ".$due."\n";
                    $body.="Please sign in to your Volunteer Portal to view the instructions and record attendance.\n\nFreeBooks\nSamagraShriSai Foundation";
                    wp_mail($volunteer->user_email,'New FreeBooks volunteer task assignment',$body,['Content-Type: text/plain; charset=UTF-8']);
                }
            }
        }
        if ($status==='completed') update_post_meta($post_id,'_fbvm_completed_at',current_time('mysql'));
    }

    private static function record_event($task_id,$type,$details,$user_id) {
        $events=get_post_meta($task_id,'_fbvm_events',true);
        if (!is_array($events)) $events=[];
        $events[]=[
            'at'=>current_time('mysql'),
            'type'=>sanitize_key($type),
            'user'=>absint($user_id),
            'details'=>sanitize_textarea_field($details)
        ];
        update_post_meta($task_id,'_fbvm_events',array_slice($events,-200));
    }

    private static function assigned_tasks($user_id) {
        return get_posts([
            'post_type'=>self::CPT,
            'post_status'=>['publish','private'],
            'numberposts'=>100,
            'orderby'=>'meta_value',
            'meta_key'=>'_fbvm_due_date',
            'order'=>'ASC',
            'meta_query'=>[['key'=>'_fbvm_assigned_user','value'=>absint($user_id),'compare'=>'=']]
        ]);
    }

    public static function volunteer_dashboard() {
        if (!is_user_logged_in()) return '<p>Please log in to view your volunteer assignments.</p>';
        $user=get_current_user_id();
        $tasks=self::assigned_tasks($user);
        ob_start();
        echo '<div class="fb-core-grid">';
        if (!$tasks) echo '<p>No tasks are currently assigned to your account.</p>';
        foreach($tasks as $task) {
            $status=get_post_meta($task->ID,'_fbvm_status',true)?:'assigned';
            $due=get_post_meta($task->ID,'_fbvm_due_date',true);
            $location=get_post_meta($task->ID,'_fbvm_location',true);
            $attendance=(array)get_post_meta($task->ID,'_fbvm_attendance',true);
            $active=false;
            foreach(array_reverse($attendance) as $entry) {
                if ((int)($entry['user']??0)===$user && ($entry['event']??'')==='check_in') {$active=true;break;}
                if ((int)($entry['user']??0)===$user && ($entry['event']??'')==='check_out') break;
            }
            $labels=['open'=>'Open','assigned'=>'Assigned','in_progress'=>'In progress','completed'=>'Completed','cancelled'=>'Cancelled'];
            echo '<article class="fb-core-card"><h3>'.esc_html(get_the_title($task)).'</h3><p>'.wp_kses_post(wpautop($task->post_content)).'</p>';
            echo '<p><strong>Status:</strong> '.esc_html($labels[$status]??$status);
            if($due) echo ' · <strong>Due:</strong> '.esc_html($due);
            if($location) echo ' · <strong>Location:</strong> '.esc_html($location);
            echo '</p>';
            if (!in_array($status,['completed','cancelled'],true)) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fbvm_attendance"><input type="hidden" name="task_id" value="'.esc_attr($task->ID).'"><input type="hidden" name="event" value="'.($active?'check_out':'check_in').'">';
                wp_nonce_field('fbvm_attendance_'.$task->ID);
                echo '<button type="submit">'.($active?'Check out':'Check in').'</button></form>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:12px"><input type="hidden" name="action" value="fbvm_activity"><input type="hidden" name="task_id" value="'.esc_attr($task->ID).'">';
                wp_nonce_field('fbvm_activity_'.$task->ID);
                echo '<label>Activity update<br><textarea name="activity" rows="2" maxlength="1000" required></textarea></label><button type="submit">Submit update</button></form>';
            }
            $hours=self::total_hours($task->ID,$user);
            echo '<p><strong>Recorded time:</strong> '.esc_html(number_format_i18n($hours,2)).' hours</p></article>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function total_hours($task_id,$user_id=0) {
        $entries=(array)get_post_meta($task_id,'_fbvm_attendance',true);
        $active=[];
        $seconds=0;
        foreach($entries as $entry) {
            if ($user_id && (int)($entry['user']??0)!==$user_id) continue;
            $uid=(int)($entry['user']??0);
            $event=$entry['event']??'';
            if ($event==='check_in') $active[$uid]=strtotime($entry['at']??'') ?: 0;
            if ($event==='check_out' && !empty($active[$uid])) {
                $end=strtotime($entry['at']??'') ?: 0;
                if ($end>$active[$uid]) $seconds+=($end-$active[$uid]);
                unset($active[$uid]);
            }
        }
        return $seconds/3600;
    }

    public static function handle_attendance() {
        if (!is_user_logged_in()) wp_die('Please log in.',403);
        $task_id=absint($_POST['task_id']??0);
        $user=get_current_user_id();
        check_admin_referer('fbvm_attendance_'.$task_id);
        if (!$task_id || get_post_type($task_id)!==self::CPT || absint(get_post_meta($task_id,'_fbvm_assigned_user',true))!==$user) wp_die('This task is not assigned to your account.',403);
        $event=sanitize_key(wp_unslash($_POST['event']??''));
        if (!in_array($event,['check_in','check_out'],true)) wp_die('Invalid attendance action.',400);
        $status=get_post_meta($task_id,'_fbvm_status',true)?:'assigned';
        if (in_array($status,['completed','cancelled'],true)) wp_die('This task is closed.',400);
        $entries=(array)get_post_meta($task_id,'_fbvm_attendance',true);
        $active=false;
        foreach(array_reverse($entries) as $entry) {
            if ((int)($entry['user']??0)!==$user) continue;
            if (($entry['event']??'')==='check_in') {$active=true;break;}
            if (($entry['event']??'')==='check_out') break;
        }
        if (($event==='check_in' && $active) || ($event==='check_out' && !$active)) wp_die('Attendance action is not valid for the current session.',400);
        $entries[]=['at'=>current_time('mysql'),'event'=>$event,'user'=>$user];
        update_post_meta($task_id,'_fbvm_attendance',array_slice($entries,-500));
        if ($event==='check_in') update_post_meta($task_id,'_fbvm_status','in_progress');
        self::record_event($task_id,$event,'Volunteer recorded '.$event.'.',$user);
        self::redirect_portal();
    }

    public static function handle_activity() {
        if (!is_user_logged_in()) wp_die('Please log in.',403);
        $task_id=absint($_POST['task_id']??0);
        $user=get_current_user_id();
        check_admin_referer('fbvm_activity_'.$task_id);
        if (!$task_id || get_post_type($task_id)!==self::CPT || absint(get_post_meta($task_id,'_fbvm_assigned_user',true))!==$user) wp_die('This task is not assigned to your account.',403);
        $activity=sanitize_textarea_field(wp_unslash($_POST['activity']??''));
        if ($activity==='') wp_die('Enter an activity update.',400);
        self::record_event($task_id,'activity_update',$activity,$user);
        wp_mail(get_option('admin_email'),'FreeBooks volunteer activity update',get_the_title($task_id)."\n\nVolunteer: ".wp_get_current_user()->display_name."\n\n".$activity);
        self::redirect_portal();
    }

    private static function redirect_portal() {
        $url=wp_get_referer()?:home_url('/volunteer-portal/');
        wp_safe_redirect(add_query_arg('fbvm_updated','1',$url));
        exit;
    }

    public static function columns($columns) {
        $columns['fbvm_volunteer']='Assigned volunteer';
        $columns['fbvm_due']='Due date';
        $columns['fbvm_status']='Task status';
        return $columns;
    }

    public static function column_value($column,$post_id) {
        if ($column==='fbvm_volunteer') {
            $id=absint(get_post_meta($post_id,'_fbvm_assigned_user',true));
            $user=$id?get_user_by('id',$id):false;
            echo esc_html($user?$user->display_name:'Unassigned');
        } elseif ($column==='fbvm_due') {
            echo esc_html(get_post_meta($post_id,'_fbvm_due_date',true));
        } elseif ($column==='fbvm_status') {
            echo esc_html(ucwords(str_replace('_',' ',get_post_meta($post_id,'_fbvm_status',true)?:'open')));
        }
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.',403);
        check_admin_referer('fbvm_export');
        $tasks=get_posts(['post_type'=>self::CPT,'post_status'=>'any','numberposts'=>-1,'orderby'=>'date','order'=>'DESC']);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=freebooks-volunteer-activity-'.gmdate('Y-m-d').'.csv');
        $out=fopen('php://output','w');
        fputcsv($out,['Task ID','Task','Volunteer','Email','Due date','Status','Recorded hours','Latest activity']);
        foreach($tasks as $task) {
            $uid=absint(get_post_meta($task->ID,'_fbvm_assigned_user',true));
            $user=$uid?get_user_by('id',$uid):false;
            $events=(array)get_post_meta($task->ID,'_fbvm_events',true);
            $latest=$events?end($events):[];
            fputcsv($out,[$task->ID,get_the_title($task),$user?$user->display_name:'Unassigned',$user?$user->user_email:'',get_post_meta($task->ID,'_fbvm_due_date',true),get_post_meta($task->ID,'_fbvm_status',true)?:'open',number_format(self::total_hours($task->ID),2,'.',''),$latest['details']??'']);
        }
        fclose($out);
        exit;
    }

    public static function reports_page() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        $tasks=get_posts(['post_type'=>self::CPT,'post_status'=>'any','numberposts'=>-1]);
        $counts=['open'=>0,'assigned'=>0,'in_progress'=>0,'completed'=>0,'cancelled'=>0];
        $hours=0;
        echo '<div class="wrap"><h1>Volunteer Activity Reports</h1><p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=fbvm_export'),'fbvm_export')).'">Export volunteer activity CSV</a></p><table class="widefat striped" style="max-width:700px"><thead><tr><th>Task status</th><th>Count</th></tr></thead><tbody>';
        foreach($tasks as $task) {
            $status=get_post_meta($task->ID,'_fbvm_status',true)?:'open';
            if(isset($counts[$status]))$counts[$status]++;
            $hours+=self::total_hours($task->ID);
        }
        foreach($counts as $status=>$count) echo '<tr><td>'.esc_html(ucwords(str_replace('_',' ',$status))).'</td><td>'.esc_html(number_format_i18n($count)).'</td></tr>';
        echo '</tbody></table><p><strong>Total recorded volunteer hours:</strong> '.esc_html(number_format_i18n($hours,2)).'</p>';
        echo '<h2>Task activity</h2><table class="widefat striped"><thead><tr><th>Task</th><th>Volunteer</th><th>Status</th><th>Hours</th><th>Latest activity</th></tr></thead><tbody>';
        foreach($tasks as $task) {
            $uid=absint(get_post_meta($task->ID,'_fbvm_assigned_user',true));$user=$uid?get_user_by('id',$uid):false;
            $events=(array)get_post_meta($task->ID,'_fbvm_events',true);$latest=$events?end($events):null;
            $status=get_post_meta($task->ID,'_fbvm_status',true)?:'open';
            echo '<tr><td><a href="'.esc_url(get_edit_post_link($task->ID)).'">'.esc_html(get_the_title($task)).'</a></td><td>'.esc_html($user?$user->display_name:'Unassigned').'</td><td>'.esc_html(ucwords(str_replace('_',' ',$status))).'</td><td>'.esc_html(number_format_i18n(self::total_hours($task->ID),2)).'</td><td>'.esc_html($latest['at']??'').' '.esc_html($latest['details']??'').'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
