<?php
if (!defined('ABSPATH')) exit;

class FB_SEO {
    public static function init() {
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('save_post', [self::class, 'save_meta'], 10, 2);
        add_filter('pre_get_document_title', [self::class, 'document_title'], 20);
    }

    private static function seo_plugin_active() {
        return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('SEOPRESS_VERSION') || defined('AIOSEO_VERSION');
    }

    public static function add_meta_box() {
        foreach (['page', 'post', FREEBOOKS_ITEM_CPT] as $type) {
            add_meta_box('fb_seo_fields', 'FreeBooks SEO settings', [self::class, 'meta_box'], $type, 'normal', 'default');
        }
    }

    public static function meta_box($post) {
        wp_nonce_field('fb_seo_save', 'fb_seo_nonce');
        $title = get_post_meta($post->ID, '_fb_seo_title', true);
        $description = get_post_meta($post->ID, '_fb_seo_description', true);
        echo '<p><label for="fb_seo_title"><strong>SEO title</strong></label><br><input class="large-text" maxlength="70" id="fb_seo_title" name="fb_seo_title" value="' . esc_attr($title) . '"><span class="description">Leave blank to use the page title.</span></p>';
        echo '<p><label for="fb_seo_description"><strong>Meta description</strong></label><br><textarea class="large-text" rows="3" maxlength="200" id="fb_seo_description" name="fb_seo_description">' . esc_textarea($description) . '</textarea><span class="description">Write a concise, accurate summary. No keyword stuffing.</span></p>';
    }

    public static function save_meta($post_id, $post) {
        if (!isset($_POST['fb_seo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fb_seo_nonce'])), 'fb_seo_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || !in_array($post->post_type, ['page', 'post', FREEBOOKS_ITEM_CPT], true)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, '_fb_seo_title', sanitize_text_field(wp_unslash($_POST['fb_seo_title'] ?? '')));
        update_post_meta($post_id, '_fb_seo_description', sanitize_textarea_field(wp_unslash($_POST['fb_seo_description'] ?? '')));
    }

    public static function document_title($title) {
        if (is_admin() || self::seo_plugin_active() || !is_singular()) return $title;
        $id = get_queried_object_id();
        $custom = $id ? get_post_meta($id, '_fb_seo_title', true) : '';
        return $custom ? $custom : $title;
    }

    private static function description($id = 0) {
        if ($id) {
            $custom = get_post_meta($id, '_fb_seo_description', true);
            if ($custom) return wp_trim_words(wp_strip_all_tags($custom), 35, '');
            $excerpt = get_the_excerpt($id);
            if ($excerpt) return wp_trim_words(wp_strip_all_tags($excerpt), 35, '');
            return wp_trim_words(wp_strip_all_tags(get_post_field('post_content', $id)), 35, '');
        }
        return 'Discover and share books and learning materials with FreeBooks, an initiative of SamagraShriSai Foundation.';
    }

    public static function output_meta() {
        if (is_admin() || is_feed() || self::seo_plugin_active()) return;
        $id = is_singular() ? get_queried_object_id() : 0;
        $title = $id ? (get_post_meta($id, '_fb_seo_title', true) ?: get_the_title($id)) : get_bloginfo('name');
        $desc = self::description($id);
        $url = $id ? get_permalink($id) : home_url('/');
        $image = $id ? get_the_post_thumbnail_url($id, 'large') : get_site_icon_url(512);
        echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="' . esc_attr(is_singular('post') ? 'article' : 'website') . '">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        if ($image) echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        echo '<meta name="twitter:card" content="' . esc_attr($image ? 'summary_large_image' : 'summary') . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($desc) . '">' . "\n";
    }

    public static function output_schema() {
        if (is_admin() || is_feed() || self::seo_plugin_active()) return;
        $settings = (array) get_option('freebooks_settings', []);
        $foundation = sanitize_text_field($settings['foundation_name'] ?? 'SamagraShriSai Foundation');
        $social = [];
        foreach (['facebook', 'instagram', 'youtube', 'linkedin'] as $key) if (!empty($settings[$key])) $social[] = esc_url_raw($settings[$key]);
        $org = [
            '@context' => 'https://schema.org', '@type' => 'Organization',
            'name' => $foundation, 'url' => home_url('/'),
            'description' => 'An initiative supporting access to books and learning materials through reuse and resale.',
        ];
        if ($social) $org['sameAs'] = $social;
        if (!empty($settings['contact_email']) && is_email($settings['contact_email'])) $org['email'] = sanitize_email($settings['contact_email']);
        if (!empty($settings['contact_phone'])) $org['telephone'] = sanitize_text_field($settings['contact_phone']);
        self::print_jsonld($org);

        $website = [
            '@context' => 'https://schema.org', '@type' => 'WebSite',
            'name' => get_bloginfo('name'), 'url' => home_url('/'),
            'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => home_url('/?s={search_term_string}')], 'query-input' => 'required name=search_term_string'],
        ];
        self::print_jsonld($website);

        if (!is_singular()) return;
        $id = get_queried_object_id();
        if (!$id) return;
        $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/')]];
        $post = get_post($id);
        if ($post && $post->post_type !== 'page') {
            $archive = get_post_type_archive_link($post->post_type);
            if ($archive) $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => get_post_type_object($post->post_type)->labels->name, 'item' => $archive];
        }
        $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => get_the_title($id), 'item' => get_permalink($id)];
        self::print_jsonld(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs]);

        if ($post->post_type !== FREEBOOKS_ITEM_CPT) return;
        $price = (float) get_post_meta($id, '_fb_price', true);
        $stock = (int) get_post_meta($id, '_fb_stock', true);
        $condition = strtolower((string) get_post_meta($id, '_fb_condition', true));
        $condition_map = ['new' => 'https://schema.org/NewCondition', 'like new' => 'https://schema.org/UsedCondition', 'good' => 'https://schema.org/UsedCondition', 'fair' => 'https://schema.org/UsedCondition', 'used' => 'https://schema.org/UsedCondition'];
        $book = [
            '@context' => 'https://schema.org', '@type' => 'Book', 'name' => get_the_title($id),
            'description' => self::description($id), 'url' => get_permalink($id),
            'offers' => ['@type' => 'Offer', 'priceCurrency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'INR', 'price' => number_format($price, 2, '.', ''), 'availability' => $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'itemCondition' => $condition_map[$condition] ?? 'https://schema.org/UsedCondition', 'url' => get_permalink($id)],
        ];
        foreach (['author' => '_fb_author', 'isbn' => '_fb_isbn', 'publisher' => '_fb_publisher', 'inLanguage' => '_fb_language'] as $field => $meta) {
            $value = trim((string) get_post_meta($id, $meta, true));
            if ($value !== '') $book[$field] = $field === 'author' ? ['@type' => 'Person', 'name' => $value] : $value;
        }
        $image = get_the_post_thumbnail_url($id, 'full');
        if ($image) $book['image'] = $image;
        self::print_jsonld($book);
    }

    private static function print_jsonld($data) {
        echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}
