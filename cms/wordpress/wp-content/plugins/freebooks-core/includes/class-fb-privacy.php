<?php
if (!defined('ABSPATH')) exit;

/**
 * Privacy export and erasure integration for FreeBooks-owned records.
 * WooCommerce customer/order personal data remains handled by WooCommerce core tools.
 */
class FB_Privacy {
    public static function init() {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
        add_action('admin_menu', [self::class, 'admin_menu'], 60);
        add_action('admin_init', [self::class, 'privacy_policy_content']);
        add_action('admin_post_fb_privacy_review', [self::class, 'review_request']);
        add_action('init', [self::class, 'register_request_type'], 25);
    }

    public static function register_request_type() {
        register_post_type('fb_privacy_request', ['label'=>'Privacy Requests','public'=>false,'show_ui'=>false,'show_in_rest'=>false,'supports'=>['title','editor','custom-fields'],'capability_type'=>'post']);
    }

    public static function register_exporter($exporters) {
        $exporters['freebooks-data'] = [
            'exporter_friendly_name' => 'FreeBooks donations, volunteer applications and newsletter',
            'callback' => [self::class, 'export_personal_data'],
        ];
        return $exporters;
    }

    public static function register_eraser($erasers) {
        $erasers['freebooks-data'] = [
            'eraser_friendly_name' => 'FreeBooks donations, volunteer applications and newsletter',
            'callback' => [self::class, 'erase_personal_data'],
        ];
        return $erasers;
    }

    private static function matching_ids($post_type, $meta_key, $email) {
        return get_posts([
            'post_type' => $post_type,
            'post_status' => ['publish','private','draft','pending','future'],
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => $meta_key,
            'meta_value' => $email,
        ]);
    }

    private static function donation_ids_for_email($email) {
        $ids = get_posts([
            'post_type' => FREEBOOKS_DONATION_CPT,
            'post_status' => ['publish','private','draft','pending'],
            'numberposts' => -1,
            'fields' => 'ids',
        ]);
        $matches = [];
        foreach ($ids as $id) {
            $donor = get_post_meta($id, '_fb_donor', true);
            if (is_array($donor) && strtolower(trim((string)($donor['email'] ?? ''))) === strtolower(trim($email))) {
                $matches[] = $id;
            }
        }
        return $matches;
    }

    public static function export_personal_data($email_address, $page = 1) {
        $email = sanitize_email($email_address);
        $data = [];
        $done = true;
        if (!is_email($email)) return ['data' => [], 'done' => true];

        foreach (self::donation_ids_for_email($email) as $id) {
            $donor = (array)get_post_meta($id, '_fb_donor', true);
            $items = (array)get_post_meta($id, '_fb_items', true);
            $data[] = [
                'group_id' => 'freebooks-donations',
                'group_label' => 'FreeBooks donation submissions',
                'item_id' => 'donation-' . absint($id),
                'data' => [
                    ['name' => 'Donation reference', 'value' => (string)get_post_meta($id, '_fb_reference', true)],
                    ['name' => 'Donor name', 'value' => (string)($donor['name'] ?? '')],
                    ['name' => 'Email', 'value' => (string)($donor['email'] ?? '')],
                    ['name' => 'Phone', 'value' => (string)($donor['phone'] ?? '')],
                    ['name' => 'City', 'value' => (string)($donor['city'] ?? '')],
                    ['name' => 'Items submitted', 'value' => wp_json_encode($items)],
                    ['name' => 'Handover preference', 'value' => (string)get_post_meta($id, '_fb_handover', true)],
                    ['name' => 'Message', 'value' => (string)get_post_meta($id, '_fb_message', true)],
                    ['name' => 'Current status', 'value' => (string)get_post_meta($id, '_fb_status', true)],
                    ['name' => 'Submission date', 'value' => (string)get_post_field('post_date', $id)],
                ],
            ];
        }

        foreach (self::matching_ids('fb_volunteer_application', '_fbcm_email', $email) as $id) {
            $data[] = [
                'group_id' => 'freebooks-volunteer',
                'group_label' => 'FreeBooks volunteer applications',
                'item_id' => 'volunteer-' . absint($id),
                'data' => [
                    ['name' => 'Name', 'value' => (string)get_the_title($id)],
                    ['name' => 'Email', 'value' => (string)get_post_meta($id, '_fbcm_email', true)],
                    ['name' => 'Application details', 'value' => (string)get_post_field('post_content', $id)],
                    ['name' => 'Status', 'value' => (string)get_post_meta($id, '_fbcm_status', true)],
                    ['name' => 'Consent date', 'value' => (string)get_post_meta($id, '_fbcm_consent_date', true)],
                ],
            ];
        }

        foreach (self::matching_ids('fbem_subscriber', '_fbem_email', $email) as $id) {
            $data[] = [
                'group_id' => 'freebooks-newsletter',
                'group_label' => 'FreeBooks newsletter subscriptions',
                'item_id' => 'newsletter-' . absint($id),
                'data' => [
                    ['name' => 'Email', 'value' => (string)get_post_meta($id, '_fbem_email', true)],
                    ['name' => 'Consent', 'value' => (string)get_post_meta($id, '_fbem_consent', true)],
                    ['name' => 'Consent date', 'value' => (string)get_post_meta($id, '_fbem_consent_date', true)],
                    ['name' => 'Subscription date', 'value' => (string)get_post_field('post_date', $id)],
                ],
            ];
        }

        return ['data' => $data, 'done' => $done];
    }

    public static function erase_personal_data($email_address, $page = 1) {
        $email = sanitize_email($email_address);
        $removed = false;
        $retained = false;
        $messages = [];
        if (!is_email($email)) return ['items_removed' => false, 'items_retained' => false, 'messages' => ['A valid email address is required.'], 'done' => true];

        // Donation provenance and item history are retained, while direct donor identifiers are anonymised.
        foreach (self::donation_ids_for_email($email) as $id) {
            $donor = (array)get_post_meta($id, '_fb_donor', true);
            $donor['name'] = 'Anonymised donor';
            $donor['email'] = '';
            $donor['phone'] = '';
            $donor['city'] = '';
            update_post_meta($id, '_fb_donor', $donor);
            update_post_meta($id, '_fb_message', '');
            wp_update_post(['ID' => $id, 'post_title' => 'Donation — anonymised — ' . get_post_field('post_date', $id)]);
            $removed = true;
            $retained = true;
        }

        // Volunteer applications are deleted on an approved erasure request.
        foreach (self::matching_ids('fb_volunteer_application', '_fbcm_email', $email) as $id) {
            wp_delete_post($id, true);
            $removed = true;
        }

        // Newsletter subscriber records and unsubscribe tokens are removed.
        foreach (self::matching_ids('fbem_subscriber', '_fbem_email', $email) as $id) {
            wp_delete_post($id, true);
            $removed = true;
        }

        if ($retained) {
            $messages[] = 'Donation item and status history were retained for operational and audit continuity; donor name, email, phone, city and message were anonymised.';
        }
        if ($removed) {
            $messages[] = 'Matching FreeBooks volunteer and newsletter records were erased where found.';
        } else {
            $messages[] = 'No matching FreeBooks records were found.';
        }
        $messages[] = 'WooCommerce orders and WordPress user-account data are handled separately by their respective WordPress/WooCommerce privacy tools.';
        return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true];
    }

    public static function privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) return;
        $content = '<h2>FreeBooks donations, volunteering and newsletter</h2><p>When you submit a book or learning-material donation, apply to volunteer, or subscribe to updates, the site processes the information you provide to administer the request, contact you about its status and maintain operational records. Newsletter subscriptions can be withdrawn using the unsubscribe link in the email.</p><p>You may request an export or erasure of personal data through the site administrator. Where donation records need to be retained for operational, safety or audit continuity, direct donor identifiers may be anonymised while non-identifying item and status history is retained. WooCommerce order and account data are also subject to WooCommerce privacy tools and applicable retention requirements.</p><p>Review and customise this text to match the Foundation actual practices, contact details, retention schedule, service providers and applicable law before publication.</p>';
        wp_add_privacy_policy_content('FreeBooks', wp_kses_post($content));
    }

    public static function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . FREEBOOKS_DONATION_CPT,
            'Privacy & data rights',
            'Privacy & data rights',
            'manage_options',
            'freebooks-privacy',
            [self::class, 'admin_page']
        );
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) return;
        $requests = get_posts(['post_type'=>'fb_privacy_request','post_status'=>['private'],'numberposts'=>100,'orderby'=>'date','order'=>'DESC']);
        echo '<div class="wrap"><h1>FreeBooks privacy & data rights</h1>';
        echo '<p>Review customer requests here. Verify the requester and check legal and operational retention requirements before processing any export or erasure.</p>';
        echo '<h2>Customer privacy requests</h2>';
        if (!$requests) echo '<p>No customer requests have been submitted.</p>';
        else {
            echo '<table class="widefat striped"><thead><tr><th>Reference</th><th>Customer</th><th>Request</th><th>Submitted</th><th>Status</th><th>Details</th><th>Action</th></tr></thead><tbody>';
            foreach ($requests as $request) {
                $id=$request->ID; $ref=(string)get_post_meta($id,'_fbpr_reference',true); $email=(string)get_post_meta($id,'_fbpr_email',true);
                $type=(string)get_post_meta($id,'_fbpr_type',true); $status=(string)get_post_meta($id,'_fbpr_status',true);
                $label=$type==='deletion'?'Account deletion':'Data access / export';
                echo '<tr><td><strong>'.esc_html($ref).'</strong></td><td>'.esc_html($email).'</td><td>'.esc_html($label).'</td><td>'.esc_html(get_post_meta($id,'_fbpr_submitted_at',true)).'</td><td>'.esc_html(ucfirst($status)).'</td><td>'.esc_html($request->post_content ?: '—').'</td><td>';
                if ($status==='pending') {
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                    wp_nonce_field('fb_privacy_review_'.$id);
                    echo '<input type="hidden" name="action" value="fb_privacy_review"><input type="hidden" name="request_id" value="'.absint($id).'"><button class="button button-secondary">Mark reviewed</button></form>';
                } else echo '—';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h2>WordPress privacy tools</h2><p>FreeBooks donation, volunteer and newsletter records are connected to native WordPress exporters and erasers. WooCommerce customer and order records are handled by WooCommerce privacy tools.</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('tools.php?page=export_personal_data')).'">Open WordPress data export</a> <a class="button" href="'.esc_url(admin_url('tools.php?page=remove_personal_data')).'">Open WordPress data erasure</a></p>';
        echo '<p><strong>Safeguard:</strong> Marking a request reviewed does not perform an export or deletion. Verify identity, process it with the appropriate tools and document the outcome separately. Donation item/status history may be retained while direct identifiers are anonymised.</p></div>';
    }

    public static function review_request() {
        if (!current_user_can('manage_options')) wp_die('You are not allowed to review privacy requests.');
        $id=absint($_POST['request_id'] ?? 0);
        check_admin_referer('fb_privacy_review_'.$id);
        if ($id && get_post_type($id)==='fb_privacy_request') {
            update_post_meta($id,'_fbpr_status','reviewed');
            update_post_meta($id,'_fbpr_reviewed_at',current_time('mysql'));
            update_post_meta($id,'_fbpr_reviewed_by',get_current_user_id());
        }
        wp_safe_redirect(admin_url('edit.php?post_type='.FREEBOOKS_DONATION_CPT.'&page=freebooks-privacy'));
        exit;
    }
}
