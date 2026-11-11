<?php
if (!defined('ABSPATH')) exit;

/**
 * Donation receiving and catalogue listing workflow.
 * Catalogue entries are created as drafts and require staff review before publishing.
 */
class FB_Donation_Workflow {
    public static function menu() {
        add_submenu_page(
            'edit.php?post_type='.FREEBOOKS_DONATION_CPT,
            'Receiving & listing',
            'Receiving & listing',
            'edit_fb_donations',
            'fb-donation-workflow',
            [self::class,'page']
        );
    }

    private static function can_manage($donation_id=0) {
        return current_user_can('manage_options') || current_user_can('edit_fb_donations') ||
            ($donation_id && current_user_can('edit_post',$donation_id));
    }

    public static function page() {
        if (!self::can_manage()) wp_die('Permission denied.');
        $ids=get_posts([
            'post_type'=>FREEBOOKS_DONATION_CPT,
            'post_status'=>'private',
            'numberposts'=>200,
            'orderby'=>'date',
            'order'=>'DESC',
            'fields'=>'ids'
        ]);
        $rows=[];
        foreach($ids as $id) {
            $status=get_post_meta($id,'_fb_status',true) ?: 'submitted';
            if (!in_array($status,['received','listed','sold'],true)) continue;
            $rows[]=$id;
        }
        echo '<div class="wrap"><h1>Donation receiving & catalogue listing</h1>';
        echo '<p>Confirm physical receipt, generate draft catalogue records from accepted donated items, review each record, then publish it when ready. Creating drafts does not make items purchasable.</p>';
        if (isset($_GET['fb_created'])) echo '<div class="notice notice-success"><p>Created '.absint($_GET['fb_created']).' catalogue draft(s). Existing linked drafts were not duplicated.</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>Donation</th><th>Donor</th><th>Items</th><th>Status</th><th>Catalogue records</th><th>Action</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">No received donations are ready for catalogue processing.</td></tr>';
        foreach($rows as $id) {
            $donor=(array)get_post_meta($id,'_fb_donor',true);
            $items=(array)get_post_meta($id,'_fb_items',true);
            $linked=(array)get_post_meta($id,'_fb_catalogue_item_ids',true);
            $status=get_post_meta($id,'_fb_status',true) ?: 'submitted';
            echo '<tr><td><strong>'.esc_html(get_post_meta($id,'_fb_reference',true)).'</strong><br>'.esc_html(get_the_date('Y-m-d',$id)).'</td>';
            echo '<td>'.esc_html($donor['name']??'').'<br>'.esc_html($donor['email']??'').'</td><td>';
            foreach($items as $index=>$item) echo '<p><strong>'.esc_html($item['title']??'Untitled item').'</strong> × '.absint($item['quantity']??1).'<br>'.esc_html($item['category']??'').'</p>';
            echo '</td><td>'.esc_html(ucwords(str_replace('_',' ',$status))).'</td><td>';
            if ($linked) {
                foreach($linked as $catalogue_id) {
                    $post=get_post(absint($catalogue_id));
                    if ($post) echo '<a href="'.esc_url(get_edit_post_link($post->ID)).'">'.esc_html(get_the_title($post)).'</a> — '.esc_html(ucwords($post->post_status)).'<br>';
                }
            } else echo 'Not created';
            echo '</td><td>';
            if ($status==='received' || $status==='listed') {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fb_donation_create_catalogue"><input type="hidden" name="donation_id" value="'.esc_attr($id).'">';
                wp_nonce_field('fb_donation_create_catalogue_'.$id);
                submit_button($linked?'Check / create missing drafts':'Create catalogue drafts','primary','submit',false);
                echo '</form>';
            } else echo 'All linked catalogue records have been published or sold.';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Important:</strong> verify the received quantity, condition, price, language and storage location on each catalogue draft before publishing. Acknowledgements are not tax receipts.</p></div>';
    }

    public static function create_catalogue_drafts() {
        $id=absint($_POST['donation_id']??0);
        if (!$id || !self::can_manage($id)) wp_die('Permission denied.',403);
        check_admin_referer('fb_donation_create_catalogue_'.$id);
        $post=get_post($id);
        if (!$post || $post->post_type!==FREEBOOKS_DONATION_CPT) wp_die('Donation not found.',404);
        $status=get_post_meta($id,'_fb_status',true);
        if (!in_array($status,['received','listed'],true)) wp_die('Only received donations can be converted into catalogue drafts.',400);

        $items=(array)get_post_meta($id,'_fb_items',true);
        $donor=(array)get_post_meta($id,'_fb_donor',true);
        $linked=(array)get_post_meta($id,'_fb_catalogue_item_ids',true);
        $linked=array_values(array_filter(array_map('absint',$linked)));
        $created=0;

        foreach($items as $index=>$item) {
            $existing=get_posts([
                'post_type'=>FREEBOOKS_ITEM_CPT,
                'post_status'=>'any',
                'numberposts'=>1,
                'meta_query'=>[
                    ['key'=>'_fb_source_donation_id','value'=>$id,'compare'=>'='],
                    ['key'=>'_fb_source_item_index','value'=>(int)$index,'compare'=>'=']
                ],
                'fields'=>'ids'
            ]);
            if ($existing) {
                if (!in_array((int)$existing[0],$linked,true)) $linked[]=(int)$existing[0];
                continue;
            }
            $title=sanitize_text_field($item['title']??'Donated learning material');
            $content=sanitize_textarea_field($item['details']??'');
            $new_id=wp_insert_post([
                'post_type'=>FREEBOOKS_ITEM_CPT,
                'post_status'=>'draft',
                'post_title'=>$title,
                'post_content'=>$content
            ],true);
            if (is_wp_error($new_id)) continue;

            $quantity=max(1,absint($item['quantity']??1));
            $meta=[
                '_fb_price'=>0,
                '_fb_stock'=>$quantity,
                '_fb_condition'=>sanitize_text_field($item['condition']??''),
                '_fb_isbn'=>sanitize_text_field($item['isbn']??''),
                '_fb_author'=>sanitize_text_field($item['author']??''),
                '_fb_publisher'=>sanitize_text_field($item['publisher']??''),
                '_fb_language'=>sanitize_text_field($item['language']??''),
                '_fb_donation_reference'=>get_post_meta($id,'_fb_reference',true),
                '_fb_source_donation_id'=>$id,
                '_fb_source_item_index'=>(int)$index,
                '_fb_donor_contributor_name'=>sanitize_text_field($donor['name']??''),
                '_fb_availability'=>'available'
            ];
            foreach($meta as $key=>$value) update_post_meta($new_id,$key,$value);

            $category=sanitize_text_field($item['category']??'');
            if ($category && taxonomy_exists('fb_item_category')) {
                $term=term_exists($category,'fb_item_category');
                if (!$term) $term=wp_insert_term($category,'fb_item_category');
                if (!is_wp_error($term)) {
                    $term_id=is_array($term) ? absint($term['term_id']??0) : absint($term);
                    if ($term_id) wp_set_object_terms($new_id,[$term_id],'fb_item_category');
                }
            }
            $linked[]=(int)$new_id;
            $created++;
        }
        update_post_meta($id,'_fb_catalogue_item_ids',array_values(array_unique($linked)));
        if (!$linked) wp_die('No catalogue drafts could be created.',500);
        wp_safe_redirect(add_query_arg(['page'=>'fb-donation-workflow','fb_created'=>$created],admin_url('edit.php?post_type='.FREEBOOKS_DONATION_CPT)));
        exit;
    }

    public static function catalogue_item_published($new_status,$old_status,$post) {
        if (!$post || $post->post_type!==FREEBOOKS_ITEM_CPT || $new_status!=='publish' || $old_status==='publish') return;
        $donation_id=absint(get_post_meta($post->ID,'_fb_source_donation_id',true));
        if (!$donation_id) return;
        $ids=array_values(array_filter(array_map('absint',(array)get_post_meta($donation_id,'_fb_catalogue_item_ids',true))));
        if (!$ids) return;
        foreach($ids as $catalogue_id) {
            $catalogue=get_post($catalogue_id);
            if (!$catalogue || $catalogue->post_status!=='publish') return;
        }
        $current=get_post_meta($donation_id,'_fb_status',true);
        if ($current==='received') {
            FB_Donations::set_status($donation_id,'listed','All catalogue records linked to this donation have been published.');
        }
    }
}
