<?php
if (!defined('ABSPATH')) exit;
class FB_Catalog {
    public static function register_post_type() {
        register_post_type(FREEBOOKS_ITEM_CPT,[
            'labels'=>['name'=>'Book Catalogue','singular_name'=>'Catalogue Item','add_new_item'=>'Add Catalogue Item','edit_item'=>'Edit Catalogue Item'],
            'public'=>true,'show_in_rest'=>true,'has_archive'=>true,'rewrite'=>['slug'=>'books'],
            'supports'=>['title','editor','thumbnail','excerpt'],'menu_icon'=>'dashicons-book',
        ]);
        register_taxonomy('fb_item_category',[FREEBOOKS_ITEM_CPT],[
            'labels'=>['name'=>'Item Categories','singular_name'=>'Item Category'],
            'public'=>true,'show_in_rest'=>true,'hierarchical'=>true,'rewrite'=>['slug'=>'book-category']
        ]);
    }
    public static function save_fields($post_id) {
        if (!isset($_POST['fb_item_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fb_item_nonce'])),'fb_item_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post',$post_id)) return;
        foreach (['price','stock','condition','isbn','author','publisher','language','shelf_code','donation_reference','edition','publication_year','pages','format','subtitle','series','subject'] as $key) {
            if (!isset($_POST['fb_'.$key])) continue;
            $v=wp_unslash($_POST['fb_'.$key]);
            if ($key==='stock') $v=max(0,absint($v));
            elseif ($key==='price') $v=max(0,(float)$v);
            else $v=sanitize_text_field($v);
            update_post_meta($post_id,'_fb_'.$key,$v);
        }
    }
    public static function metabox() {
        add_meta_box('fb_item_data','FreeBooks inventory details',[self::class,'render_metabox'],FREEBOOKS_ITEM_CPT,'normal','high');
    }
    public static function render_metabox($post) {
        wp_nonce_field('fb_item_save','fb_item_nonce');
        $fields=['price'=>'Price (INR)','stock'=>'Stock quantity','condition'=>'Condition','isbn'=>'ISBN','author'=>'Author','publisher'=>'Publisher','language'=>'Language','shelf_code'=>'Shelf / storage code','donation_reference'=>'Donation reference','edition'=>'Edition','publication_year'=>'Publication year','pages'=>'Page count','format'=>'Format (paperback, hardcover, etc.)','subtitle'=>'Subtitle','series'=>'Series / collection','subject'=>'Subject / keywords'];
        echo '<table class="form-table">';
        foreach($fields as $key=>$label) {
            $v=get_post_meta($post->ID,'_fb_'.$key,true);
            echo '<tr><th><label for="fb_'.esc_attr($key).'">'.esc_html($label).'</label></th><td><input class="regular-text" id="fb_'.esc_attr($key).'" name="fb_'.esc_attr($key).'" value="'.esc_attr($v).'" /></td></tr>';
        }
        echo '</table><p>Catalogue publishing is manual. Confirm condition, price and stock before publishing.</p>';
    }
}
add_action('add_meta_boxes',[FB_Catalog::class,'metabox']);
add_action('save_post_'.FREEBOOKS_ITEM_CPT,[FB_Catalog::class,'save_fields']);
add_action('rest_api_init',function(){
    register_rest_field(FREEBOOKS_ITEM_CPT,'freebooks_inventory',[
        'get_callback'=>function($obj){
            $id=(int)$obj['id'];
            return [
                'price'=>(float)get_post_meta($id,'_fb_price',true),
                'stock'=>(int)get_post_meta($id,'_fb_stock',true),
                'condition'=>(string)get_post_meta($id,'_fb_condition',true),
                'isbn'=>(string)get_post_meta($id,'_fb_isbn',true),
                'author'=>(string)get_post_meta($id,'_fb_author',true),
                'publisher'=>(string)get_post_meta($id,'_fb_publisher',true),
                'language'=>(string)get_post_meta($id,'_fb_language',true),
                'shelf_code'=>(string)get_post_meta($id,'_fb_shelf_code',true),
                'edition'=>(string)get_post_meta($id,'_fb_edition',true),
                'publication_year'=>(string)get_post_meta($id,'_fb_publication_year',true),
                'pages'=>(int)get_post_meta($id,'_fb_pages',true),
                'format'=>(string)get_post_meta($id,'_fb_format',true),
                'subtitle'=>(string)get_post_meta($id,'_fb_subtitle',true),
                'series'=>(string)get_post_meta($id,'_fb_series',true),
                'subject'=>(string)get_post_meta($id,'_fb_subject',true)
            ];
        },
        'schema'=>['description'=>'FreeBooks inventory fields','type'=>'object','context'=>['view','edit']]
    ]);
});
