<?php
if (!defined('ABSPATH')) exit;
class FB_Impact {
    public static function init() {
        add_action('init',[self::class,'register_story_type']);
        add_action('rest_api_init',[self::class,'register_rest_routes']);
        add_action('admin_init',[self::class,'register_settings']);
        add_action('admin_menu',[self::class,'admin_menu']);
        add_shortcode('freebooks_impact_dashboard',[self::class,'impact_shortcode']);
        add_shortcode('freebooks_impact_stories',[self::class,'stories_shortcode']);
        add_action('add_meta_boxes',[self::class,'story_meta_box']);
        add_action('save_post_fb_impact_story',[self::class,'save_story_consent']);
    }
    public static function register_rest_routes() {
        register_rest_route('freebooks/v1','/impact/stories',[
            'methods'=>'GET',
            'callback'=>[self::class,'public_stories'],
            'permission_callback'=>'__return_true',
        ]);
    }
    public static function public_stories($request) {
        $limit=min(12,max(1,absint($request->get_param('per_page')?:6)));
        $q=new WP_Query([
            'post_type'=>'fb_impact_story','post_status'=>'publish','posts_per_page'=>$limit,
            'meta_key'=>'_fbim_permission_confirmed','meta_value'=>'1','orderby'=>'date','order'=>'DESC'
        ]);
        $items=[];
        foreach($q->posts as $post) {
            $items[]=[
                'id'=>$post->ID,
                'title'=>get_the_title($post),
                'excerpt'=>wp_strip_all_tags(get_the_excerpt($post)?:wp_trim_words($post->post_content,35)),
                'date'=>get_the_date('c',$post),
                'image'=>get_the_post_thumbnail_url($post,'large')?:null,
            ];
        }
        return rest_ensure_response(['stories'=>$items]);
    }
    public static function register_story_type() {
        register_post_type('fb_impact_story',['labels'=>['name'=>'Impact Stories','singular_name'=>'Impact Story','add_new_item'=>'Add Impact Story'],'public'=>true,'show_in_rest'=>true,'has_archive'=>false,'rewrite'=>['slug'=>'impact-story'],'supports'=>['title','editor','thumbnail','excerpt'],'menu_icon'=>'dashicons-heart']);
    }
    public static function register_settings(){register_setting('fb_impact_settings_group','fb_impact_metrics',['sanitize_callback'=>[self::class,'sanitize_metrics']]);}
    public static function sanitize_metrics($v) {
        $v=is_array($v)?$v:[];$o=[];
        foreach(['students_supported','volunteers','community_partners'] as $k)$o[$k]=max(0,absint($v[$k]??0));
        $o['operating_cost_note']=sanitize_text_field($v['operating_cost_note']??'');return $o;
    }
    public static function admin_menu(){add_options_page('FreeBooks Impact','FreeBooks Impact','manage_options','fb-impact-settings',[self::class,'settings_page']);}
    /** Public, non-identifying figures for the Next.js frontend. Values are not independently audited. */
    public static function public_metrics() {
        $metrics = self::metrics();
        $manual = (array)get_option('fb_impact_metrics', []);
        foreach (['students_supported','volunteers','community_partners'] as $key) {
            if (!array_key_exists($key, $manual)) $metrics[$key] = null;
        }
        $metrics['verified'] = false;
        $metrics['note'] = 'Operational counts are calculated from FreeBooks records. Foundation-entered figures are published as supplied and are not independently audited.';
        return $metrics;
    }

    private static function metrics() {
        $manual=(array)get_option('fb_impact_metrics',[]);
        $donation_ids=get_posts(['post_type'=>FREEBOOKS_DONATION_CPT,'post_status'=>'private','numberposts'=>-1,'fields'=>'ids']);
        $received=0;$listed=0;$sold=0;
        foreach($donation_ids as $id){$s=get_post_meta($id,'_fb_status',true);if(in_array($s,['received','listed','sold'],true))$received++;if(in_array($s,['listed','sold'],true))$listed++;if($s==='sold')$sold++;}
        $items=wp_count_posts(FREEBOOKS_ITEM_CPT);
        return [
            'books_listed'=>(int)($items->publish??0),
            'donations_received'=>$received,
            'donations_listed'=>$listed,
            'donations_sold'=>$sold,
            'students_supported'=>absint($manual['students_supported']??0),
            'volunteers'=>absint($manual['volunteers']??0),
            'community_partners'=>absint($manual['community_partners']??0),
            'operating_cost_note'=>sanitize_text_field($manual['operating_cost_note']??'')
        ];
    }
    public static function settings_page() {
        if(!current_user_can('manage_options'))return;$m=(array)get_option('fb_impact_metrics',[]);
        echo '<div class="wrap"><h1>FreeBooks Trust & Impact</h1><p>Enter only figures verified by the Foundation. Catalogue and donation workflow figures are calculated from the website records.</p><form method="post" action="options.php">';
        settings_fields('fb_impact_settings_group');
        $fields=['students_supported'=>'Students supported','volunteers'=>'Active volunteers','community_partners'=>'Community partners'];
        echo '<table class="form-table">';
        foreach($fields as $k=>$label)echo '<tr><th><label for="fbim_'.$k.'">'.esc_html($label).'</label></th><td><input type="number" min="0" id="fbim_'.$k.'" name="fb_impact_metrics['.$k.']" value="'.esc_attr($m[$k]??'0').'"></td></tr>';
        echo '<tr><th><label for="fbim_cost">Operating cost disclosure note</label></th><td><input class="regular-text" id="fbim_cost" name="fb_impact_metrics[operating_cost_note]" value="'.esc_attr($m['operating_cost_note']??'').'"></td></tr></table>';
        submit_button('Save verified impact figures');echo '</form><h2>Current recorded figures</h2><pre>'.esc_html(wp_json_encode(self::metrics(),JSON_PRETTY_PRINT)).'</pre><p>These are operational counts and manually entered figures, not an independent audit.</p></div>';
    }
    public static function impact_shortcode() {
        $m=self::metrics();$cards=['Books listed'=>$m['books_listed'],'Donations received'=>$m['donations_received'],'Donations with listed items'=>$m['donations_listed'],'Donations marked sold'=>$m['donations_sold'],'Students supported'=>$m['students_supported'],'Active volunteers'=>$m['volunteers'],'Community partners'=>$m['community_partners']];
        ob_start(); ?>
        <section class="fb-core-impact" aria-label="FreeBooks impact figures">
          <div class="fb-core-impact-intro"><span>FREEBOOKS · TRANSPARENCY</span><h2>Books create opportunities</h2><p>These figures reflect records currently maintained by FreeBooks and verified figures entered by the Foundation.</p></div>
          <div class="fb-core-impact-grid"><?php foreach($cards as $label=>$value): ?><div class="fb-core-impact-card"><strong><?php echo esc_html(number_format_i18n($value)); ?></strong><span><?php echo esc_html($label); ?></span></div><?php endforeach; ?></div>
          <?php if($m['operating_cost_note']): ?><p><?php echo esc_html($m['operating_cost_note']); ?></p><?php endif; ?>
          <p class="fb-core-muted">Figures are operational website records and Foundation-entered values; they are not independently audited.</p>
        </section>
        <?php return ob_get_clean();
    }
    public static function story_meta_box(){add_meta_box('fbim_consent','Publication permission',[self::class,'consent_box'],'fb_impact_story','side','high');}
    public static function consent_box($post) {
        wp_nonce_field('fbim_consent_save','fbim_consent_nonce');$ok=get_post_meta($post->ID,'_fbim_permission_confirmed',true);
        echo '<label><input type="checkbox" name="fbim_permission_confirmed" value="1" '.checked($ok,'1',false).'> I confirm documented permission to publish this story and any included image.</label><p>Do not publish identifying details or images of minors without appropriate guardian consent.</p>';
    }
    public static function save_story_consent($post_id) {
        if(!isset($_POST['fbim_consent_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fbim_consent_nonce'])),'fbim_consent_save'))return;
        if((defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||!current_user_can('edit_post',$post_id))return;
        update_post_meta($post_id,'_fbim_permission_confirmed',isset($_POST['fbim_permission_confirmed'])?'1':'0');
    }
    public static function stories_shortcode($atts=[]) {
        $atts=shortcode_atts(['limit'=>6],$atts);$q=new WP_Query(['post_type'=>'fb_impact_story','post_status'=>'publish','posts_per_page'=>min(20,max(1,absint($atts['limit']))),'meta_key'=>'_fbim_permission_confirmed','meta_value'=>'1']);
        ob_start();echo '<div class="fb-core-grid">';
        while($q->have_posts()){$q->the_post();echo '<article class="fb-core-card">';if(has_post_thumbnail())echo get_the_post_thumbnail(get_the_ID(),'medium');echo '<h3><a href="'.esc_url(get_permalink()).'">'.esc_html(get_the_title()).'</a></h3><p>'.esc_html(wp_trim_words(get_the_excerpt()?:get_the_content(),28)).'</p></article>';}
        wp_reset_postdata();if(!$q->found_posts)echo '<p>Impact stories will appear here when approved for publication.</p>';echo '</div>';return ob_get_clean();
    }
}
