<?php
if (!defined('ABSPATH')) exit;

/**
 * Private member and volunteer portals.
 * A volunteer application is linked to a WP account only by an administrator.
 */
class FB_Portals {
    public static function init() {
        add_shortcode('freebooks_member_portal',[self::class,'member_portal']);
        add_shortcode('freebooks_volunteer_portal',[self::class,'volunteer_portal']);
        add_action('add_meta_boxes',[self::class,'add_link_box']);
        add_action('save_post_fb_volunteer_application',[self::class,'save_link']);
        add_filter('manage_fb_volunteer_application_posts_columns',[self::class,'add_link_column']);
        add_action('manage_fb_volunteer_application_posts_custom_column',[self::class,'link_column'],10,2);
        add_action('admin_notices',[self::class,'portal_notice']);
    }

    public static function create_pages() {
        $pages = [
            'member-portal' => [
                'title' => 'Member Portal',
                'content' => '[freebooks_member_portal]',
            ],
            'volunteer-portal' => [
                'title' => 'Volunteer Portal',
                'content' => '[freebooks_volunteer_portal]',
            ],
        ];
        foreach ($pages as $slug => $data) {
            $existing = get_page_by_path($slug, OBJECT, 'page');
            if ($existing) {
                // Do not overwrite a page that the site owner has already customised.
                if (strpos((string)$existing->post_content, '[' . ($slug === 'member-portal' ? 'freebooks_member_portal' : 'freebooks_volunteer_portal')) === false) {
                    update_post_meta($existing->ID, '_freebooks_portal_shortcode_missing', '1');
                }
                continue;
            }
            wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => $data['title'],
                'post_name' => $slug,
                'post_content' => $data['content'],
            ]);
        }
    }

    private static function require_login() {
        if (is_user_logged_in()) return '';
        $redirect = get_permalink() ?: home_url('/');
        ob_start();
        echo '<div class="fb-core-portal-login"><p>Please log in to view your private FreeBooks account.</p>';
        wp_login_form(['redirect' => $redirect, 'remember' => true]);
        echo '<p><a href="' . esc_url(wp_lostpassword_url($redirect)) . '">Forgot password?</a></p></div>';
        return ob_get_clean();
    }

    private static function current_user_email() {
        $user = wp_get_current_user();
        return sanitize_email($user->user_email);
    }

    private static function portal_header($title, $description) {
        $user = wp_get_current_user();
        echo '<section class="fb-core-portal">';
        echo '<header class="fb-core-portal-header"><p class="fb-core-portal-eyebrow">FREEBOOKS ACCOUNT</p>';
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<p>' . esc_html($description) . '</p>';
        echo '<p class="fb-core-portal-welcome">Welcome, ' . esc_html($user->display_name) . '</p></header>';
    }

    public static function member_portal() {
        if (!is_user_logged_in()) return self::require_login();
        $user = wp_get_current_user();
        $email = self::current_user_email();
        ob_start();
        self::portal_header('Member Portal', 'Your FreeBooks activity and account information.');
        echo '<div class="fb-core-portal-grid">';

        // Recent customer orders are scoped to the authenticated WordPress user ID.
        echo '<section class="fb-core-portal-panel"><h3>My Orders</h3>';
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders(['customer_id' => $user->ID, 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC']);
            if ($orders) {
                echo '<ul class="fb-core-portal-list">';
                foreach ($orders as $order) {
                    echo '<li><strong>Order #' . esc_html($order->get_order_number()) . '</strong> — ' .
                        esc_html(wc_get_order_status_name($order->get_status())) . ' — ' .
                        wp_kses_post($order->get_formatted_order_total()) . '</li>';
                }
                echo '</ul>';
                if (function_exists('wc_get_page_permalink')) {
                    echo '<p><a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">Open full WooCommerce account</a></p>';
                }
            } else {
                echo '<p>No orders are linked to this account yet.</p>';
            }
        } else {
            echo '<p>Order history will appear here when WooCommerce is active.</p>';
        }
        echo '</section>';

        // Book requests are matched to the signed-in account's exact email address.
        echo '<section class="fb-core-portal-panel"><h3>My Book Requests</h3>';
        $requests = get_posts([
            'post_type' => 'fb_book_request',
            'post_status' => get_post_stati(),
            'numberposts' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [['key' => '_fbgm_email', 'value' => $email, 'compare' => '=']],
        ]);
        if ($requests) {
            echo '<ul class="fb-core-portal-list">';
            foreach ($requests as $request) {
                $labels = [
                    'fb-request-new' => 'New',
                    'fb-request-review' => 'Under review',
                    'fb-request-found' => 'Found',
                    'fb-request-unavailable' => 'Unavailable',
                    'fb-request-fulfilled' => 'Fulfilled',
                ];
                $status = get_post_status($request);
                echo '<li><strong>' . esc_html(get_the_title($request)) . '</strong><span>' .
                    esc_html($labels[$status] ?? 'Received') . '</span></li>';
            }
            echo '</ul>';
        } else {
            echo '<p>You have not submitted any book requests using this account email.</p>';
        }
        echo '</section>';

        echo '<section class="fb-core-portal-panel"><h3>My Wishlist</h3>';
        if (shortcode_exists('freebooks_wishlist')) echo do_shortcode('[freebooks_wishlist]');
        else echo '<p>Wishlist is not currently available.</p>';
        echo '</section>';

        echo '<section class="fb-core-portal-panel"><h3>My Volunteer Activity</h3>';
        $apps = get_posts([
            'post_type' => 'fb_volunteer_application',
            'post_status' => get_post_stati(),
            'numberposts' => 10,
            'meta_key' => '_fbcm_linked_user_id',
            'meta_value' => $user->ID,
        ]);
        if ($apps) {
            echo '<ul class="fb-core-portal-list">';
            $labels = ['new'=>'New','review'=>'Under review','contacted'=>'Contacted','accepted'=>'Accepted','declined'=>'Declined'];
            foreach ($apps as $app) {
                $status = get_post_meta($app->ID, '_fbcm_status', true) ?: 'new';
                echo '<li><strong>' . esc_html(get_the_title($app)) . '</strong><span>' . esc_html($labels[$status] ?? $status) . '</span></li>';
            }
            echo '</ul>';
        } else {
            echo '<p>No volunteer application has been linked to this account.</p>';
        }
        echo '</section></div>';
        echo '<p class="fb-core-portal-footer">For account assistance, contact the FreeBooks team using the official contact details on this website.</p>';
        echo '</section>';
        return ob_get_clean();
    }

    public static function volunteer_portal() {
        if (!is_user_logged_in()) return self::require_login();
        $user = wp_get_current_user();
        $apps = get_posts([
            'post_type' => 'fb_volunteer_application',
            'post_status' => get_post_stati(),
            'numberposts' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_fbcm_linked_user_id',
            'meta_value' => $user->ID,
        ]);
        ob_start();
        self::portal_header('Volunteer Portal', 'View your linked volunteer application and current opportunities.');
        if ($apps) {
            echo '<div class="fb-core-portal-grid">';
            $labels = ['new'=>'New','review'=>'Under review','contacted'=>'Contacted','accepted'=>'Accepted','declined'=>'Declined'];
            foreach ($apps as $app) {
                $status = get_post_meta($app->ID, '_fbcm_status', true) ?: 'new';
                echo '<article class="fb-core-portal-panel"><h3>' . esc_html(get_the_title($app)) . '</h3>';
                echo '<p><strong>Application status:</strong> ' . esc_html($labels[$status] ?? $status) . '</p>';
                echo '<p><strong>Submitted:</strong> ' . esc_html(get_the_date(get_option('date_format'), $app)) . '</p>';
                echo '<p>Your application details are read-only here. Contact the FreeBooks team if any information needs correction.</p></article>';
            }
            echo '</div>';
        } else {
            echo '<div class="fb-core-portal-panel"><h3>No linked volunteer application</h3><p>This WordPress account has not yet been linked to a volunteer application by the FreeBooks administrator. If you have applied, contact the team to verify and link your account.</p></div>';
        }
        echo '<h3 class="fb-core-portal-subheading">My assigned tasks & attendance</h3>';
        if (class_exists('FB_Volunteer_Management')) echo FB_Volunteer_Management::volunteer_dashboard();
        echo '<h3 class="fb-core-portal-subheading">Current opportunities</h3>';
        if (shortcode_exists('freebooks_volunteer_opportunities')) echo do_shortcode('[freebooks_volunteer_opportunities]');
        echo '<p class="fb-core-portal-footer">Your portal is read-only. Application decisions and profile changes are managed by authorised FreeBooks staff.</p>';
        echo '</section>';
        return ob_get_clean();
    }

    public static function add_link_box() {
        add_meta_box('fb_portal_link', 'Verified account linking', [self::class, 'link_box'], 'fb_volunteer_application', 'side', 'high');
    }

    public static function link_box($post) {
        wp_nonce_field('fb_portal_link_' . $post->ID, 'fb_portal_link_nonce');
        $linked_id = absint(get_post_meta($post->ID, '_fbcm_linked_user_id', true));
        $linked = $linked_id ? get_user_by('id', $linked_id) : false;
        echo '<p>Link this application to a WordPress user account. Only administrators/editors with permission to edit this application can change the link.</p>';
        if ($linked) {
            echo '<p><strong>Currently linked:</strong><br>' . esc_html($linked->display_name) . '<br>' . esc_html($linked->user_email) . '</p>';
        } else {
            echo '<p><strong>Currently linked:</strong> None</p>';
        }
        $email = $linked ? $linked->user_email : '';
        echo '<label for="fb_portal_user_email"><strong>WordPress account email</strong></label>';
        echo '<input type="email" id="fb_portal_user_email" name="fb_portal_user_email" value="' . esc_attr($email) . '" class="widefat" placeholder="Exact account email">';
        echo '<p class="description">Enter the exact email address of the account after verifying the applicant’s identity. Leave blank to unlink.</p>';
    }

    public static function save_link($post_id) {
        if (!isset($_POST['fb_portal_link_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fb_portal_link_nonce'])), 'fb_portal_link_' . $post_id)) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
        if (!isset($_POST['fb_portal_user_email'])) return;
        $email = sanitize_email(wp_unslash($_POST['fb_portal_user_email']));
        if ($email === '') {
            delete_post_meta($post_id, '_fbcm_linked_user_id');
            return;
        }
        if (!is_email($email)) return;
        $user = get_user_by('email', $email);
        if (!$user) {
            add_filter('redirect_post_location', function($location) {
                return add_query_arg('fb_portal_link_error', 'not_found', $location);
            });
            return;
        }
        update_post_meta($post_id, '_fbcm_linked_user_id', absint($user->ID));
        update_post_meta($post_id, '_fbcm_linked_by', get_current_user_id());
        update_post_meta($post_id, '_fbcm_linked_at', current_time('mysql'));
    }

    public static function add_link_column($columns) {
        $columns['fb_portal_user'] = 'Portal account';
        return $columns;
    }

    public static function link_column($column, $post_id) {
        if ($column !== 'fb_portal_user') return;
        $id = absint(get_post_meta($post_id, '_fbcm_linked_user_id', true));
        $user = $id ? get_user_by('id', $id) : false;
        echo $user ? esc_html($user->user_email) : 'Not linked';
    }

    public static function portal_notice() {
        if (!is_admin() || !current_user_can('edit_posts') || empty($_GET['fb_portal_link_error'])) return;
        if (sanitize_key(wp_unslash($_GET['fb_portal_link_error'])) === 'not_found') {
            echo '<div class="notice notice-error is-dismissible"><p>No WordPress account was found with that exact email address. The application was not linked.</p></div>';
        }
    }
}
