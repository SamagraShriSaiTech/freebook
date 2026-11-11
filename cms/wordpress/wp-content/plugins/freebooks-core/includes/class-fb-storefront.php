<?php
if (!defined('ABSPATH')) exit;

/** Customer-facing catalogue and book detail presentation. */
class FB_Storefront {
    public static function init() {
        add_shortcode('freebooks_storefront', [self::class, 'shop_shortcode']);
        add_shortcode('freebooks_book_detail', [self::class, 'detail_shortcode']);
        add_shortcode('freebooks_customer_links', [self::class, 'customer_links_shortcode']);
        add_filter('the_content', [self::class, 'append_single_details'], 25);
    }

    public static function shop_shortcode($atts = []) {
        $atts = shortcode_atts(['per_page' => 12, 'show_filters' => 'yes'], $atts, 'freebooks_storefront');
        $term = sanitize_text_field(wp_unslash($_GET['fb_shop_q'] ?? ''));
        $category = absint($_GET['fb_shop_cat'] ?? 0);
        $condition = sanitize_text_field(wp_unslash($_GET['fb_shop_condition'] ?? ''));
        $language = sanitize_text_field(wp_unslash($_GET['fb_shop_language'] ?? ''));
        $author = sanitize_text_field(wp_unslash($_GET['fb_shop_author'] ?? ''));
        $isbn = sanitize_text_field(wp_unslash($_GET['fb_shop_isbn'] ?? ''));
        $publisher = sanitize_text_field(wp_unslash($_GET['fb_shop_publisher'] ?? ''));
        $format = sanitize_text_field(wp_unslash($_GET['fb_shop_format'] ?? ''));
        $min_price = isset($_GET['fb_shop_min_price']) ? max(0,(float)wp_unslash($_GET['fb_shop_min_price'])) : '';
        $max_price = isset($_GET['fb_shop_max_price']) ? max(0,(float)wp_unslash($_GET['fb_shop_max_price'])) : '';
        $availability = sanitize_key(wp_unslash($_GET['fb_shop_stock'] ?? ''));
        $sort = sanitize_key(wp_unslash($_GET['fb_shop_sort'] ?? 'newest'));
        $page = max(1, absint($_GET['fb_shop_page'] ?? 1));
        $args = [
            'post_type' => FREEBOOKS_ITEM_CPT, 'post_status' => 'publish',
            'posts_per_page' => min(48, max(1, absint($atts['per_page']))),
            'paged' => $page, 's' => $term, 'ignore_sticky_posts' => true,
        ];
        if ($category) $args['tax_query'] = [['taxonomy'=>'fb_item_category','field'=>'term_id','terms'=>$category]];
        $meta = [];
        if ($condition !== '') $meta[] = ['key'=>'_fb_condition','value'=>$condition,'compare'=>'='];
        if ($language !== '') $meta[] = ['key'=>'_fb_language','value'=>$language,'compare'=>'LIKE'];
        if ($author !== '') $meta[] = ['key'=>'_fb_author','value'=>$author,'compare'=>'LIKE'];
        if ($isbn !== '') $meta[] = ['key'=>'_fb_isbn','value'=>$isbn,'compare'=>'LIKE'];
        if ($publisher !== '') $meta[] = ['key'=>'_fb_publisher','value'=>$publisher,'compare'=>'LIKE'];
        if ($format !== '') $meta[] = ['key'=>'_fb_format','value'=>$format,'compare'=>'LIKE'];
        if ($min_price !== '') $meta[] = ['key'=>'_fb_price','value'=>$min_price,'compare'=>'>=','type'=>'NUMERIC'];
        if ($max_price !== '') $meta[] = ['key'=>'_fb_price','value'=>$max_price,'compare'=>'<=','type'=>'NUMERIC'];
        if ($availability === 'available') $meta[] = ['key'=>'_fb_stock','value'=>1,'compare'=>'>=','type'=>'NUMERIC'];
        if ($availability === 'sold') $meta[] = ['key'=>'_fb_stock','value'=>0,'compare'=>'=','type'=>'NUMERIC'];
        if ($meta) $args['meta_query'] = $meta;
        if ($sort === 'price_low' || $sort === 'price_high') {
            $args['meta_key'] = '_fb_price'; $args['orderby'] = 'meta_value_num'; $args['order'] = $sort === 'price_low' ? 'ASC' : 'DESC';
        } elseif ($sort === 'title') { $args['orderby'] = 'title'; $args['order'] = 'ASC'; }
        elseif ($sort === 'popular') { $args['meta_key'] = '_fb_view_count'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'DESC'; }
        else { $args['orderby'] = 'date'; $args['order'] = 'DESC'; }
        $query = new WP_Query($args);
        if (class_exists('FB_Discovery')) FB_Discovery::record_search($term, (int)$query->found_posts, ['category'=>$category,'condition'=>$condition,'language'=>$language,'author'=>$author,'isbn'=>$isbn,'publisher'=>$publisher,'format'=>$format]);
        ob_start();
        echo '<section class="fb-storefront">';
        if ($atts['show_filters'] === 'yes') self::render_filters($term, $category, $condition, $language, $availability, $sort, $author, $isbn, $publisher, $format, $min_price, $max_price);
        echo '<div class="fb-storefront-results"><p class="fb-storefront-count">'.esc_html(sprintf(_n('%s book found','%s books found',(int)$query->found_posts,'freebooks-core'),number_format_i18n($query->found_posts))).'</p>';
        if ($query->have_posts()) {
            echo '<div class="fb-store-grid">';
            while ($query->have_posts()) { $query->the_post(); echo self::card(get_the_ID()); }
            echo '</div>';
            if ($query->max_num_pages > 1) {
                $base = remove_query_arg('fb_shop_page');
                echo '<nav class="fb-store-pagination" aria-label="Catalogue pages">';
                for ($i=1; $i <= (int)$query->max_num_pages; $i++) {
                    $url = add_query_arg('fb_shop_page', $i, $base);
                    echo '<a '.($i===$page?'aria-current="page" class="is-current"':'').' href="'.esc_url($url).'">'.esc_html($i).'</a>';
                }
                echo '</nav>';
            }
        } else echo '<div class="fb-store-empty"><h3>No books found</h3><p>Try another search or remove a filter.</p><a href="'.esc_url(remove_query_arg(['fb_shop_q','fb_shop_cat','fb_shop_condition','fb_shop_language','fb_shop_author','fb_shop_isbn','fb_shop_publisher','fb_shop_format','fb_shop_min_price','fb_shop_max_price','fb_shop_stock','fb_shop_sort','fb_shop_page'])).'">Clear filters</a></div>';
        echo '</div></section>';
        wp_reset_postdata();
        return ob_get_clean();
    }

    private static function render_filters($term, $category, $condition, $language, $availability, $sort, $author='', $isbn='', $publisher='', $format='', $min_price='', $max_price='') {
        echo '<form class="fb-store-filters" method="get">';
        foreach ($_GET as $key=>$value) {
            if (strpos((string)$key, 'fb_shop_') === 0 || is_array($value)) continue;
            echo '<input type="hidden" name="'.esc_attr(sanitize_key($key)).'" value="'.esc_attr(sanitize_text_field(wp_unslash($value))).'">';
        }
        echo '<label><span>Search</span><input type="search" name="fb_shop_q" value="'.esc_attr($term).'" placeholder="Book title, author or keyword"></label>';
        echo '<label><span>Category</span>';
        wp_dropdown_categories(['taxonomy'=>'fb_item_category','name'=>'fb_shop_cat','show_option_all'=>'All categories','hide_empty'=>false,'selected'=>$category,'value_field'=>'term_id']);
        echo '</label><label><span>Condition</span><select name="fb_shop_condition"><option value="">Any condition</option>';
        foreach (['New','Like New','Good','Fair','Used'] as $v) echo '<option value="'.esc_attr($v).'" '.selected($condition,$v,false).'>'.esc_html($v).'</option>';
        echo '</select></label><label><span>Language</span><input name="fb_shop_language" value="'.esc_attr($language).'" placeholder="e.g. Hindi"></label>';
        echo '<label><span>Availability</span><select name="fb_shop_stock"><option value="">All items</option><option value="available" '.selected($availability,'available',false).'>In stock</option><option value="sold" '.selected($availability,'sold',false).'>Sold out</option></select></label>';
        echo '<label><span>Sort by</span><select name="fb_shop_sort">';
        foreach (['newest'=>'Newest','popular'=>'Most viewed','title'=>'Title A–Z','price_low'=>'Price: low to high','price_high'=>'Price: high to low'] as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($sort,$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label><button type="submit">Apply filters</button><a class="fb-store-clear" href="'.esc_url(remove_query_arg(['fb_shop_q','fb_shop_cat','fb_shop_condition','fb_shop_language','fb_shop_author','fb_shop_isbn','fb_shop_publisher','fb_shop_format','fb_shop_min_price','fb_shop_max_price','fb_shop_stock','fb_shop_sort','fb_shop_page'])).'">Clear</a></form>';
    }

    private static function card($id) {
        $price = (float)get_post_meta($id,'_fb_price',true);
        $stock = max(0,(int)get_post_meta($id,'_fb_stock',true));
        $condition = (string)get_post_meta($id,'_fb_condition',true);
        $author = (string)get_post_meta($id,'_fb_author',true);
        $language = (string)get_post_meta($id,'_fb_language',true);
        $product_id = absint(get_post_meta($id,'_fb_wc_product_id',true));
        $product = $product_id && function_exists('wc_get_product') ? wc_get_product($product_id) : false;
        $buy = $product && $product->is_purchasable() && $product->is_in_stock() ? '<a class="fb-store-buy" href="'.esc_url($product->add_to_cart_url()).'">Add to cart</a>' : '<span class="fb-store-unavailable">'.($stock>0?'Available soon':'Currently unavailable').'</span>';
        $image = has_post_thumbnail($id) ? get_the_post_thumbnail($id,'medium_large',['class'=>'fb-store-image','loading'=>'lazy']) : '<div class="fb-store-image-placeholder" aria-hidden="true">📚</div>';
        $html = '<article class="fb-store-card"><a class="fb-store-card-image" href="'.esc_url(get_permalink($id)).'">'.$image.'</a><div class="fb-store-card-body"><p class="fb-store-eyebrow">'.esc_html($condition ?: 'Pre-loved book').'</p><h3><a href="'.esc_url(get_permalink($id)).'">'.esc_html(get_the_title($id)).'</a></h3>';
        if ($author) $html .= '<p class="fb-store-author">by '.esc_html($author).'</p>';
        if ($language) $html .= '<p class="fb-store-language">'.esc_html($language).'</p>';
        $html .= '<div class="fb-store-card-bottom"><strong>'.($price>0 ? (function_exists('wc_price')?wp_kses_post(wc_price($price)):'₹'.esc_html(number_format_i18n($price,2))) : 'Price to be confirmed').'</strong>'.$buy.'</div>';
        $html .= self::wishlist_form($id).'</div></article>';
        return $html;
    }

    private static function wishlist_form($id) {
        $saved = false;
        if (is_user_logged_in()) $saved = in_array((int)$id,array_map('absint',(array)get_user_meta(get_current_user_id(),'_fbgm_wishlist',true)),true);
        else $saved = in_array((int)$id,array_map('absint',explode(',',sanitize_text_field(wp_unslash($_COOKIE['fbgm_wishlist']??'')))),true);
        return '<form class="fb-store-wishlist" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fbgm_wishlist"><input type="hidden" name="book_id" value="'.absint($id).'"><input type="hidden" name="return" value="'.esc_url(get_permalink($id)).'">'.wp_nonce_field('fbgm_wishlist_'.$id,'_wpnonce',true,false).'<button type="submit" aria-label="'.($saved?'Remove from wishlist':'Add to wishlist').'">'.($saved?'♥ Saved':'♡ Wishlist').'</button></form>';
    }

    public static function detail_shortcode($atts = []) {
        $atts = shortcode_atts(['id'=>0],$atts,'freebooks_book_detail');
        $id = absint($atts['id']) ?: get_the_ID();
        if (!$id || get_post_type($id)!==FREEBOOKS_ITEM_CPT || get_post_status($id)!=='publish') return '<p>Book details are not available.</p>';
        return self::detail_markup($id);
    }

    public static function append_single_details($content) {
        if (is_admin() || !is_singular(FREEBOOKS_ITEM_CPT) || !in_the_loop() || !is_main_query()) return $content;
        if (class_exists('FB_Discovery')) FB_Discovery::record_view(get_the_ID());
        return $content . self::detail_markup(get_the_ID()) . do_shortcode('[freebooks_recommended_books current_id="'.absint(get_the_ID()).'"]');
    }

    private static function detail_markup($id) {
        $fields = ['author'=>'Author','publisher'=>'Publisher','isbn'=>'ISBN','language'=>'Language','condition'=>'Condition'];
        $price=(float)get_post_meta($id,'_fb_price',true); $stock=max(0,(int)get_post_meta($id,'_fb_stock',true));
        $product_id=absint(get_post_meta($id,'_fb_wc_product_id',true));
        $product=$product_id && function_exists('wc_get_product') ? wc_get_product($product_id) : false;
        $html='<section class="fb-book-detail"><div class="fb-book-detail-main"><h2>Book details</h2><dl>';
        foreach($fields as $key=>$label){$v=get_post_meta($id,'_fb_'.$key,true);if($v!=='')$html.='<div><dt>'.esc_html($label).'</dt><dd>'.esc_html($v).'</dd></div>';}
        $html.='<div><dt>Availability</dt><dd>'.($stock>0?esc_html($stock.' in stock'):'Currently unavailable').'</dd></div></dl></div><aside class="fb-book-purchase"><span>Price</span><strong>'.($price>0?(function_exists('wc_price')?wp_kses_post(wc_price($price)):'₹'.esc_html(number_format_i18n($price,2))):'Price to be confirmed').'</strong>';
        if($product && $product->is_purchasable() && $product->is_in_stock()) $html.='<a class="fb-store-buy" href="'.esc_url($product->add_to_cart_url()).'">Add to cart</a>';
        else $html.='<p class="fb-store-unavailable">This item is not currently available to purchase.</p>';
        $html.=self::wishlist_form($id).'</aside></section>';
        $terms=get_the_terms($id,'fb_item_category');
        if($terms && !is_wp_error($terms)){$html.='<p class="fb-book-categories">Categories: ';$links=[];foreach($terms as $t)$links[]='<a href="'.esc_url(get_term_link($t)).'">'.esc_html($t->name).'</a>';$html.=implode(', ',$links).'</p>';}
        return $html;
    }

    public static function customer_links_shortcode() {
        if (!function_exists('wc_get_page_permalink')) return '<p>Customer account and checkout pages will appear when WooCommerce is configured.</p>';
        $links=[];
        foreach(['myaccount'=>'My account','cart'=>'Cart','checkout'=>'Checkout'] as $page=>$label){$url=wc_get_page_permalink($page);if($url)$links[]='<a class="fb-customer-link" href="'.esc_url($url).'">'.esc_html($label).'</a>';}
        return '<nav class="fb-customer-links" aria-label="Customer links">'.implode('',$links).'</nav>';
    }
}
