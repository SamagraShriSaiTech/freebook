<?php
/**
 * Plugin Name: FreeBooks Core
 * Description: Unified FreeBooks platform plugin — donations, catalogue, inventory, community, engagement, impact and customer support.
 * Version: 5.3.0
 * Author: SamagraShriSai Foundation
 * Requires PHP: 8.1
 */
if (!defined('ABSPATH')) exit;
define('FREEBOOKS_CORE_VERSION', '5.3.0');
define('FREEBOOKS_DONATION_CPT', 'fb_donation');
define('FREEBOOKS_ITEM_CPT', 'fb_item');

require_once __DIR__ . '/includes/class-fb-donations.php';
require_once __DIR__ . '/includes/class-fb-donation-workflow.php';
require_once __DIR__ . '/includes/class-fb-donor-communications.php';
require_once __DIR__ . '/includes/class-fb-volunteer-management.php';
require_once __DIR__ . '/includes/class-fb-catalog.php';
require_once __DIR__ . '/includes/class-fb-shortcodes.php';
require_once __DIR__ . '/includes/class-fb-rest.php';
require_once __DIR__ . '/includes/class-fb-admin.php';
require_once __DIR__ . '/includes/class-fb-settings.php';
require_once __DIR__ . '/includes/class-fb-commerce.php';
require_once __DIR__ . '/includes/class-fb-inventory.php';
require_once __DIR__ . '/includes/class-fb-operations.php';
require_once __DIR__ . '/includes/class-fb-seo.php';
require_once __DIR__ . '/includes/class-fb-growth.php';
require_once __DIR__ . '/includes/class-fb-community.php';
require_once __DIR__ . '/includes/class-fb-engagement.php';
require_once __DIR__ . '/includes/class-fb-impact.php';
require_once __DIR__ . '/includes/class-fb-order-support.php';
require_once __DIR__ . '/includes/class-fb-portals.php';
require_once __DIR__ . '/includes/class-fb-governance.php';
require_once __DIR__ . '/includes/class-fb-storefront.php';
require_once __DIR__ . '/includes/class-fb-customer.php';
require_once __DIR__ . '/includes/class-fb-trust-content.php';
require_once __DIR__ . '/includes/class-fb-launch.php';
require_once __DIR__ . '/includes/class-fb-integration.php';
require_once __DIR__ . '/includes/class-fb-privacy.php';
require_once __DIR__ . '/includes/class-fb-security.php';
require_once __DIR__ . '/includes/class-fb-launch-monitor.php';
require_once __DIR__ . '/includes/class-fb-discovery.php';
require_once __DIR__ . '/includes/class-fb-donor-crm.php';
require_once __DIR__ . '/includes/class-fb-customer-experience.php';
require_once __DIR__ . '/includes/class-fb-fulfilment.php';
require_once __DIR__ . '/includes/class-fb-notifications.php';
require_once __DIR__ . '/includes/class-fb-analytics-operations.php';
require_once __DIR__ . '/includes/class-fb-launch-hardening.php';
require_once __DIR__ . '/includes/class-fb-accounts.php';
require_once __DIR__ . '/includes/class-fb-membership-community.php';
require_once __DIR__ . '/includes/class-fb-checkout.php';

add_action('init', function () {
    register_post_type(FREEBOOKS_DONATION_CPT, [
        'labels' => ['name'=>'Donations','singular_name'=>'Donation','menu_name'=>'FreeBooks','all_items'=>'All Donations','add_new_item'=>'Add Donation','edit_item'=>'View Donation'],
        'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'supports'=>['title','custom-fields'],
        'capability_type'=>['fb_donation','fb_donations'],'map_meta_cap'=>true,
        'capabilities'=>[
            'edit_post'=>'edit_fb_donation','read_post'=>'read_fb_donation','delete_post'=>'delete_fb_donation',
            'edit_posts'=>'edit_fb_donations','edit_others_posts'=>'edit_others_fb_donations','publish_posts'=>'publish_fb_donations',
            'read_private_posts'=>'read_private_fb_donations','delete_posts'=>'delete_fb_donations',
            'delete_private_posts'=>'delete_private_fb_donations','delete_published_posts'=>'delete_published_fb_donations',
            'delete_others_posts'=>'delete_others_fb_donations','edit_private_posts'=>'edit_private_fb_donations',
            'edit_published_posts'=>'edit_published_fb_donations'
        ], 'menu_icon'=>'dashicons-book-alt'
    ]);
    FB_Catalog::register_post_type();
});
add_action('rest_api_init', ['FB_REST', 'register_routes']);
add_action('admin_menu', ['FB_Admin', 'menu']);
add_action('admin_menu', ['FB_Donation_Workflow', 'menu']);
add_action('admin_menu', ['FB_Donor_Communications', 'menu']);
add_action('admin_menu', ['FB_Volunteer_Management', 'menu']);
add_action('admin_post_fbvm_attendance', ['FB_Volunteer_Management', 'handle_attendance']);
add_action('admin_post_fbvm_activity', ['FB_Volunteer_Management', 'handle_activity']);
add_action('admin_post_fbvm_export', ['FB_Volunteer_Management', 'export_csv']);
add_action('admin_post_fb_donor_resend_email', ['FB_Donor_Communications', 'resend_email']);
add_action('admin_post_fb_donation_create_catalogue', ['FB_Donation_Workflow', 'create_catalogue_drafts']);
add_action('transition_post_status', ['FB_Donation_Workflow', 'catalogue_item_published'], 30, 3);
add_action('admin_menu', ['FB_Operations', 'menu']);
add_action('admin_menu', ['FB_Inventory', 'menu']);
add_action('admin_post_fb_inventory_export', ['FB_Inventory', 'export_csv']);
add_action('admin_post_fb_inventory_import', ['FB_Inventory', 'import_csv']);
add_action('admin_post_fb_inventory_adjust', ['FB_Inventory', 'adjust_stock']);
add_action('admin_post_fbcm_export', ['FB_Community', 'export_csv']);
add_action('admin_post_fbem_export_subscribers', ['FB_Engagement', 'export_subscribers']);
add_action('admin_post_fb_governance_export', ['FB_Governance', 'export_csv']);
add_action('plugins_loaded', ['FB_Commerce', 'init']);
add_action('plugins_loaded', ['FB_Growth', 'init']);
add_action('plugins_loaded', ['FB_Community', 'init']);
add_action('plugins_loaded', ['FB_Engagement', 'init']);
add_action('plugins_loaded', ['FB_Impact', 'init']);
add_action('plugins_loaded', ['FB_Order_Support', 'init']);
add_action('plugins_loaded', ['FB_Portals', 'init']);
add_action('plugins_loaded', ['FB_Governance', 'init']);
add_action('plugins_loaded', ['FB_Storefront', 'init']);
add_action('plugins_loaded', ['FB_Customer', 'init']);
add_action('plugins_loaded', ['FB_SEO', 'init']);
add_action('plugins_loaded', ['FB_Trust_Content', 'init']);
add_action('plugins_loaded', ['FB_Launch', 'init']);
add_action('plugins_loaded', ['FB_Integration', 'init']);
add_action('plugins_loaded', ['FB_Privacy', 'init']);
add_action('plugins_loaded', ['FB_Security', 'init']);
add_action('plugins_loaded', ['FB_Launch_Monitor', 'init']);
add_action('plugins_loaded', ['FB_Discovery', 'init']);
add_action('plugins_loaded', ['FB_Donor_CRM', 'init']);
add_action('plugins_loaded', ['FB_Customer_Experience', 'init']);
add_action('plugins_loaded', ['FB_Fulfilment', 'init']);
add_action('plugins_loaded', ['FB_Notifications', 'init']);
add_action('plugins_loaded', ['FB_Analytics_Operations', 'init']);
add_action('plugins_loaded', ['FB_Launch_Hardening', 'init']);
add_action('plugins_loaded', ['FB_Accounts', 'init']);
add_action('plugins_loaded', ['FB_Membership_Community', 'init']);
add_action('plugins_loaded', ['FB_Checkout', 'init']);
add_action('admin_post_fb_discovery_export', ['FB_Discovery', 'export_csv']);
add_action('init', ['FB_Volunteer_Management', 'register_types'], 20);
add_action('add_meta_boxes', ['FB_Volunteer_Management', 'add_meta_box']);
add_action('save_post_fb_volunteer_task', ['FB_Volunteer_Management', 'save_task']);
add_filter('manage_fb_volunteer_task_posts_columns', ['FB_Volunteer_Management', 'columns']);
add_action('manage_fb_volunteer_task_posts_custom_column', ['FB_Volunteer_Management', 'column_value'], 10, 2);
add_action('init', ['FB_Operations', 'register_roles'], 30);
add_action('template_redirect', ['FB_Operations', 'handle_print_ack']);
// WordPress is the private CMS origin; the public Next.js frontend owns indexable SEO and sitemap output.
add_filter('wp_robots', function ($robots) {
    if (!is_admin()) { $robots['noindex'] = true; $robots['nofollow'] = true; }
    return $robots;
}, 99);
add_filter('wp_sitemaps_enabled', '__return_false', 99);
add_action('wp_head', ['FB_SEO', 'output_schema'], 30);
add_action('wp_head', ['FB_SEO', 'output_meta'], 5);
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('freebooks-core', plugin_dir_url(__FILE__).'assets/freebooks-core.css', [], FREEBOOKS_CORE_VERSION);
});

add_shortcode('freebooks_donation_form', ['FB_Shortcodes','donation_form']);
add_shortcode('freebooks_track_donation', ['FB_Shortcodes','tracking_form']);
add_action('admin_post_nopriv_fb_submit_donation', ['FB_Shortcodes','handle_donation']);
add_action('admin_post_fb_submit_donation', ['FB_Shortcodes','handle_donation']);
add_action('admin_post_nopriv_fb_track_donation', ['FB_Shortcodes','handle_tracking']);
add_action('admin_post_fb_track_donation', ['FB_Shortcodes','handle_tracking']);


function freebooks_core_upgrade() {
    $stored=(string)get_option('freebooks_core_version','1.4.0');
    if (version_compare($stored, FREEBOOKS_CORE_VERSION, '>=')) return;

    // Bring forward contact/social settings from the former Engagement add-on.
    $settings=(array)get_option('freebooks_settings',[]);
    $legacy_contact=(array)get_option('fbem_contact_settings',[]);
    $map=['phone'=>'contact_phone','email'=>'contact_email','whatsapp'=>'whatsapp','facebook'=>'facebook','instagram'=>'instagram','youtube'=>'youtube','linkedin'=>'linkedin'];
    foreach($map as $old=>$new) {
        $current=trim((string)($settings[$new]??''));
        if ($current==='' || strpos($current,'{{')===0) {
            if (!empty($legacy_contact[$old])) $settings[$new]=$legacy_contact[$old];
            elseif (strpos($current,'{{')===0) $settings[$new]='';
        }
    }
    if ($settings) update_option('freebooks_settings',$settings);

    // Preserve manually verified impact figures entered in the former Impact add-on.
    if (!get_option('fb_impact_metrics')) {
        $legacy_metrics=(array)get_option('fbim_manual_metrics',[]);
        if ($legacy_metrics) update_option('fb_impact_metrics',[
            'students_supported'=>absint($legacy_metrics['students_supported']??0),
            'volunteers'=>absint($legacy_metrics['volunteers']??0),
            'community_partners'=>absint($legacy_metrics['community_partners']??0),
            'operating_cost_note'=>sanitize_text_field($legacy_metrics['operating_cost_note']??'')
        ]);
    }

    FB_Catalog::register_post_type();
    FB_Growth::register_request_type();
    FB_Community::register_types();
    FB_Engagement::register_types();
    FB_Impact::register_story_type();
    FB_Order_Support::register_type();
    FB_Portals::create_pages();
    FB_Operations::register_roles();
    FB_Governance::upgrade();
    FB_Trust_Content::create_missing_pages();
    update_option('freebooks_core_version', FREEBOOKS_CORE_VERSION);
    flush_rewrite_rules();
    update_option('freebooks_core_version', FREEBOOKS_CORE_VERSION);
}
// Upgrade runs on init, after WordPress has initialized WP_Rewrite.
 // Registering rewrite-bearing post types on plugins_loaded can fatal because
 // the global rewrite object is not initialized yet.
add_action('init','freebooks_core_upgrade',99);

register_activation_hook(__FILE__, function () {
    if (!get_option('freebooks_settings')) {
        add_option('freebooks_settings', [
            'contact_phone'=>'','contact_email'=>'','contact_address'=>'',
            'whatsapp'=>'','facebook'=>'','instagram'=>'','youtube'=>'','linkedin'=>'',
            'foundation_name'=>'SamagraShriSai Foundation',
            'proceeds_copy'=>'Sales proceeds support students in need. Necessary shipping and operating costs may be met by the initiative.'
        ]);
    }
    // Register rewrite-bearing post types before flushing rewrite rules.
    FB_Catalog::register_post_type();
    FB_Growth::register_request_type();
    FB_Community::register_types();
    FB_Engagement::register_types();
    FB_Impact::register_story_type();
    FB_Order_Support::register_type();
    FB_Portals::create_pages();
    FB_Operations::register_roles();
    FB_Governance::upgrade();
    FB_Trust_Content::create_missing_pages();
    update_option('freebooks_core_version', FREEBOOKS_CORE_VERSION);
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
