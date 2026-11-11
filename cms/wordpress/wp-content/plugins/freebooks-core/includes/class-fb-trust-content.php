<?php
if (!defined('ABSPATH')) exit;

/** Trust, impact presentation and idempotent starter-page setup. */
class FB_Trust_Content {
    const PAGE_OPTION = 'freebooks_core_v270_pages_created';

    public static function init() {
        add_action('init', [self::class, 'register_shortcodes']);
        add_action('admin_menu', [self::class, 'admin_menu'], 40);
        add_action('admin_post_fb_core_create_pages', [self::class, 'create_pages_action']);
    }

    public static function register_shortcodes() {
        add_shortcode('freebooks_transparency', [self::class, 'transparency_shortcode']);
        add_shortcode('freebooks_foundation_contact', [self::class, 'contact_shortcode']);
        add_shortcode('freebooks_site_cta', [self::class, 'cta_shortcode']);
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type=' . FREEBOOKS_DONATION_CPT, 'Trust & Site Pages', 'Trust & Site Pages', 'manage_options', 'freebooks-trust-pages', [self::class, 'admin_page']);
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) return;
        $created = (array) get_option(self::PAGE_OPTION, []);
        echo '<div class="wrap"><h1>FreeBooks — Trust & Site Pages</h1><p>Starter pages are created only when their URL slug is not already in use. Existing pages and their content are never overwritten.</p>';
        echo '<p><strong>Created by this module:</strong> ' . esc_html(count($created)) . ' page(s).</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('fb_core_create_pages');
        echo '<input type="hidden" name="action" value="fb_core_create_pages"><button class="button button-primary">Create any missing starter pages</button></form>';
        echo '<h2>Page list</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Page</th><th>URL</th><th>Status</th></tr></thead><tbody>';
        foreach (self::page_definitions() as $slug => $page) {
            $existing = get_page_by_path($slug, OBJECT, 'page');
            echo '<tr><td>' . esc_html($page['title']) . '</td><td><code>/' . esc_html($slug) . '/</code></td><td>';
            if ($existing) echo '<a href="' . esc_url(get_edit_post_link($existing->ID)) . '">Existing — edit page</a>';
            else echo '<span style="color:#b45309">Not created</span>';
            echo '</td></tr>';
        }
        echo '</tbody></table><p>Review and customize all starter text before launch. Contact details remain blank until configured. Privacy, terms and shipping/returns text is a starter draft for legal review, not legal advice.</p></div>';
    }

    public static function create_pages_action() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('fb_core_create_pages');
        self::create_missing_pages();
        wp_safe_redirect(add_query_arg(['page' => 'freebooks-trust-pages', 'created' => '1'], admin_url('edit.php?post_type=' . FREEBOOKS_DONATION_CPT)));
        exit;
    }

    public static function create_missing_pages() {
        $created = (array) get_option(self::PAGE_OPTION, []);
        foreach (self::page_definitions() as $slug => $page) {
            if (get_page_by_path($slug, OBJECT, 'page')) continue;
            $id = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => $page['title'],
                'post_name' => $slug,
                'post_content' => $page['content'],
                'comment_status' => 'closed',
            ], true);
            if (!is_wp_error($id) && $id) $created[$slug] = absint($id);
        }
        update_option(self::PAGE_OPTION, $created, false);
    }

    private static function page_definitions() {
        return [
            'about' => ['title' => 'About FreeBooks', 'content' => "<h2>Making learning resources easier to access</h2>\n<p>FreeBooks is an initiative of SamagraShriSai Foundation. It helps usable books and learning materials find readers who can benefit from them.</p>\n<p>People can offer books and educational materials for review. Approved items may be listed for purchase. Sales proceeds support students in need; necessary shipping and operating costs may also be met from those proceeds.</p>\n<p>FreeBooks does not accept monetary donations through this book donation programme.</p>\n[freebooks_site_cta]"],
            'how-it-works' => ['title' => 'How FreeBooks Works', 'content' => "<h2>Donate, review, reuse</h2>\n<ol><li><strong>Submit your books:</strong> Share details and photographs of books or learning materials you would like to pass on.</li><li><strong>Review:</strong> Our team reviews submissions and may contact you for more information or handover arrangements.</li><li><strong>Prepare and list:</strong> Accepted items are checked, categorized and listed when ready.</li><li><strong>Find your next book:</strong> Browse available items and place an order through the store.</li></ol>\n<p>Availability, condition and price are shown on each listing. Shipping charges and delivery information are shown during checkout.</p>\n[freebooks_site_cta]"],
            'donate' => ['title' => 'Donate Books & Learning Materials', 'content' => "<h2>Give useful learning materials another life</h2>\n<p>We welcome suitable books, notes, magazines, journals and stationery. Please provide accurate condition details and clear photographs. Submission does not guarantee acceptance; each donation is reviewed by the team.</p>\n<p>FreeBooks accepts books and learning materials, not monetary donations. No tax exemption or donation valuation is represented by the acknowledgement.</p>\n[freebooks_donation_form]"],
            'shop' => ['title' => 'Shop Books', 'content' => "<h2>Browse available books</h2>\n<p>Explore the current catalogue. Each listing shows its available condition and price. Single-copy books may sell out.</p>\n[freebooks_storefront]"],
            'impact' => ['title' => 'Our Impact', 'content' => "<h2>Progress through reuse and access</h2>\n<p>We share operational figures recorded by the platform and additional figures verified and entered by the Foundation. Counts are not independently audited unless explicitly stated.</p>\n[freebooks_impact_dashboard]\n<h2>Stories</h2>\n[freebooks_impact_stories]"],
            'transparency' => ['title' => 'Transparency', 'content' => "<h2>How the initiative works</h2>\n[freebooks_transparency]\n[freebooks_impact_dashboard]"],
            'faqs' => ['title' => 'Frequently Asked Questions', 'content' => "<h2>Questions about donating and ordering</h2>\n[freebooks_faqs]"],
            'volunteer' => ['title' => 'Volunteer with FreeBooks', 'content' => "<h2>Help books reach readers</h2>\n<p>Volunteers may support sorting, cataloguing, outreach and other activities based on current opportunities and team requirements.</p>\n[freebooks_volunteer_opportunities]\n[freebooks_volunteer_form]"],
            'contact' => ['title' => 'Contact FreeBooks', 'content' => "<h2>Get in touch</h2>\n<p>Use the official contact details configured by the Foundation below. If no details appear, please check back later.</p>\n[freebooks_foundation_contact]\n[freebooks_contact_links]"],
            'privacy-policy' => ['title' => 'Privacy Policy', 'content' => "<h2>Privacy policy — review before launch</h2>\n<p>FreeBooks collects information that visitors submit through donation, volunteer, newsletter, account and checkout forms. This may include contact details, submitted item details, order information and communications.</p>\n<p>Information is used to review submissions, manage listings and orders, respond to enquiries, provide requested updates, prevent misuse and meet applicable legal or operational obligations. Payment credentials are handled by the configured payment provider and are not intended to be stored by FreeBooks Core.</p>\n<p>Information may be processed by hosting, website, payment, email and shipping service providers as needed to provide the service. Access should be limited to authorized personnel. Retention, deletion, grievance contact, cookies, analytics and data-subject request procedures must be completed by the Foundation before launch.</p>\n<p><strong>Important:</strong> This starter text is not a complete legal policy. Add the Foundation's verified legal identity, contact, retention periods, processors and applicable rights/processes after legal review.</p>"],
            'terms' => ['title' => 'Terms & Conditions', 'content' => "<h2>Terms and conditions — review before launch</h2>\n<p>Listings describe used or new learning materials as accurately as possible. Condition, edition, availability and price are shown on the relevant listing. A donation submission is an offer for review and does not guarantee acceptance or listing.</p>\n<p>Orders are subject to stock availability, payment confirmation and the checkout terms displayed at purchase. Delivery estimates and tracking are provided through the selected shipping provider. Please contact the team promptly if an item arrives damaged or differs materially from its listing.</p>\n<p>FreeBooks does not accept monetary donations through this programme. Sales proceeds support students in need, and necessary shipping and operating costs may be met from those proceeds.</p>\n<p><strong>Important:</strong> This starter text is not a complete legal agreement. Add governing law, dispute handling, cancellation, liability, eligibility and verified organization details after legal review.</p>"],
            'shipping-returns' => ['title' => 'Shipping & Returns', 'content' => "<h2>Shipping</h2>\n<p>Available delivery options, shipping charges and estimated delivery details are displayed during checkout. Shipment booking, labels and parcel tracking are managed by the selected shipping provider through its WooCommerce integration. Use the carrier tracking link provided with your order for parcel updates.</p>\n<h2>Returns and order issues</h2>\n<p>If an item is incorrect or arrives damaged, contact the team with your order number and supporting details as soon as possible. Requests are reviewed individually under the published return policy and applicable law. A return or refund is not automatic.</p>\n<p><strong>Before launch:</strong> Configure the actual serviceable regions, dispatch timelines, return window, evidence requirements, refund process and contact details.</p>"],
        ];
    }

    public static function transparency_shortcode() {
        $settings = (array) get_option('freebooks_settings', []);
        $foundation = sanitize_text_field($settings['foundation_name'] ?? 'SamagraShriSai Foundation');
        $copy = sanitize_textarea_field($settings['proceeds_copy'] ?? 'Sales proceeds support students in need. Necessary shipping and operating costs may be met by the initiative.');
        return '<section class="fb-core-transparency"><h3>Who runs FreeBooks?</h3><p>' . esc_html($foundation) . ' operates FreeBooks as a book and learning-material reuse initiative.</p><h3>How are proceeds used?</h3><p>' . esc_html($copy) . '</p><h3>What can you donate?</h3><p>Books and suitable learning materials are reviewed before they are accepted and listed. FreeBooks does not accept monetary donations through this programme.</p><h3>How do we report impact?</h3><p>Operational figures are calculated from website records. Additional impact figures are entered by the Foundation and should be supported by its records. Website figures are not independently audited unless explicitly stated.</p><h3>Shipping and delivery</h3><p>Shipping charges and delivery options are shown at checkout. The selected WooCommerce shipping provider manages shipment booking and parcel tracking.</p></section>';
    }

    public static function contact_shortcode() {
        $s = (array) get_option('freebooks_settings', []);
        $rows = [];
        if (!empty($s['contact_email']) && is_email($s['contact_email'])) $rows[] = '<p><strong>Email:</strong> <a href="mailto:' . esc_attr(antispambot($s['contact_email'])) . '">' . esc_html(antispambot($s['contact_email'])) . '</a></p>';
        if (!empty($s['contact_phone'])) $rows[] = '<p><strong>Phone:</strong> <a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $s['contact_phone'])) . '">' . esc_html($s['contact_phone']) . '</a></p>';
        if (!empty($s['contact_address'])) $rows[] = '<p><strong>Address:</strong> ' . esc_html($s['contact_address']) . '</p>';
        return $rows ? '<div class="fb-core-contact-details">' . implode('', $rows) . '</div>' : '<p>Official contact details will be published here once confirmed by the Foundation.</p>';
    }

    public static function cta_shortcode() {
        $shop = get_page_by_path('shop');
        $donate = get_page_by_path('donate');
        $html = '<div class="fb-core-cta">';
        if ($donate) $html .= '<a class="fb-core-button" href="' . esc_url(get_permalink($donate)) . '">Donate books</a> ';
        if ($shop) $html .= '<a class="fb-core-button fb-core-button-secondary" href="' . esc_url(get_permalink($shop)) . '">Browse books</a>';
        return $html . '</div>';
    }
}
