<?php
if (!defined('ABSPATH')) exit;

/** Catalogue discovery, recommendations, recently viewed books and search analytics. */
class FB_Discovery {
    const SEARCH_OPTION = 'fb_discovery_search_analytics';
    const RECENT_COOKIE = 'fb_recent_books';

    public static function init() {
        add_action('template_redirect',[self::class,'maybe_record_view'],8);
        add_shortcode('freebooks_recently_viewed',[self::class,'recently_viewed_shortcode']);
        add_shortcode('freebooks_recommended_books',[self::class,'recommended_shortcode']);
        add_action('admin_menu',[self::class,'admin_menu'],70);
        add_action('admin_post_fb_discovery_export',[self::class,'export_csv']);
        add_action('rest_api_init',[self::class,'register_routes']);
    }

    public static function maybe_record_view() {
        if(is_admin()||!is_singular(FREEBOOKS_ITEM_CPT))return;
        $id=get_queried_object_id();
        if(!$id||get_post_status($id)!=='publish')return;
        self::record_view($id);
    }

    public static function record_view($id) {
        $id=absint($id);
        if(!$id||get_post_type($id)!==FREEBOOKS_ITEM_CPT||get_post_status($id)!=='publish')return;
        $recent=[];
        if(!empty($_COOKIE[self::RECENT_COOKIE])) {
            $raw=sanitize_text_field(wp_unslash($_COOKIE[self::RECENT_COOKIE]));
            $recent=array_values(array_filter(array_map('absint',explode(',',$raw))));
        }
        $key='fb_viewed_'.$id;
        if(!in_array($id,$recent,true) && !get_transient($key.'_'.self::visitor_key())) {
            $count=(int)get_post_meta($id,'_fb_view_count',true);
            update_post_meta($id,'_fb_view_count',$count+1);
            set_transient($key.'_'.self::visitor_key(),1,DAY_IN_SECONDS);
        }
        $recent=array_values(array_diff($recent,[$id]));
        array_unshift($recent,$id);
        $recent=array_slice($recent,0,12);
        if(!headers_sent())setcookie(self::RECENT_COOKIE,implode(',',$recent),[
            'expires'=>time()+30*DAY_IN_SECONDS,'path'=>COOKIEPATH?:'/',
            'secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'
        ]);
        $_COOKIE[self::RECENT_COOKIE]=implode(',',$recent);
    }

    private static function visitor_key() {
        $visitor=sanitize_text_field(wp_unslash($_COOKIE['fb_discovery_visitor']??''));
        if(!preg_match('/^[a-f0-9]{32}$/',$visitor)) {
            $visitor=wp_generate_password(32,false,false);
            $visitor=preg_replace('/[^a-f0-9]/','',strtolower($visitor));
            if(strlen($visitor)<32)$visitor=substr(hash('sha256',wp_generate_password(48,true,true)),0,32);
            if(!headers_sent())setcookie('fb_discovery_visitor',$visitor,['expires'=>time()+DAY_IN_SECONDS,'path'=>COOKIEPATH?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
            $_COOKIE['fb_discovery_visitor']=$visitor;
        }
        return substr(hash_hmac('sha256',$visitor,wp_salt('auth')),0,24);
    }

    public static function record_search($term,$results,$filters=[]) {
        $term=sanitize_text_field((string)$term);
        if($term==='' && empty(array_filter($filters)))return;
        $key=strtolower(trim($term));
        if($key==='')$key='[filters only]';
        $rows=get_option(self::SEARCH_OPTION,[]);
        if(!is_array($rows))$rows=[];
        $hash=hash('sha256',$key);
        if(!isset($rows[$hash]))$rows[$hash]=['term'=>$key,'searches'=>0,'zero_results'=>0,'last_seen'=>'','filters'=>[]];
        $rows[$hash]['searches']++;
        if((int)$results===0)$rows[$hash]['zero_results']++;
        $rows[$hash]['last_seen']=current_time('mysql',true);
        foreach($filters as $name=>$value)if($value!==''&&$value!==0)$rows[$hash]['filters'][sanitize_key($name)]=sanitize_text_field((string)$value);
        uasort($rows,function($a,$b){return (int)$b['searches']<=>(int)$a['searches'];});
        if(count($rows)>500)$rows=array_slice($rows,0,500,true);
        update_option(self::SEARCH_OPTION,$rows,false);
    }

    public static function recently_viewed_shortcode($atts=[]) {
        $atts=shortcode_atts(['limit'=>8],$atts,'freebooks_recently_viewed');
        $ids=array_values(array_filter(array_map('absint',explode(',',sanitize_text_field(wp_unslash($_COOKIE[self::RECENT_COOKIE]??''))))));
        $ids=array_slice($ids,0,max(1,min(12,absint($atts['limit']))));
        if(!$ids)return '<section class="fb-discovery-recent"><h2>Recently viewed</h2><p>Books you view will appear here.</p></section>';
        $q=new WP_Query(['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>'publish','post__in'=>$ids,'orderby'=>'post__in','posts_per_page'=>count($ids)]);
        return self::render_cards($q,'Recently viewed books');
    }

    public static function recommended_shortcode($atts=[]) {
        $atts=shortcode_atts(['current_id'=>0,'limit'=>4],$atts,'freebooks_recommended_books');
        $current=absint($atts['current_id'])?:get_the_ID();
        if(!$current||get_post_type($current)!==FREEBOOKS_ITEM_CPT)return '';
        $terms=wp_get_post_terms($current,'fb_item_category',['fields'=>'ids']);
        $args=['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>'publish','posts_per_page'=>max(1,min(8,absint($atts['limit']))),'post__not_in'=>[$current],'meta_key'=>'_fb_view_count','orderby'=>'meta_value_num','order'=>'DESC'];
        if($terms&&!is_wp_error($terms))$args['tax_query']=[['taxonomy'=>'fb_item_category','field'=>'term_id','terms'=>$terms]];
        $q=new WP_Query($args);
        if(!$q->have_posts()) {
            $args['tax_query']=[];$args['orderby']='date';$args['meta_key']='';
            $q=new WP_Query($args);
        }
        return self::render_cards($q,'You may also like');
    }

    private static function render_cards($q,$heading) {
        if(!$q->have_posts())return '';
        ob_start();
        echo '<section class="fb-discovery-section"><h2>'.esc_html($heading).'</h2><div class="fb-store-grid">';
        while($q->have_posts()) {
            $q->the_post();
            $id=get_the_ID();
            $img=has_post_thumbnail($id)?get_the_post_thumbnail($id,'medium',['loading'=>'lazy']):'<div class="fb-store-image-placeholder" aria-hidden="true">📚</div>';
            $author=get_post_meta($id,'_fb_author',true);
            echo '<article class="fb-store-card"><a class="fb-store-card-image" href="'.esc_url(get_permalink($id)).'">'.$img.'</a><div class="fb-store-card-body"><h3><a href="'.esc_url(get_permalink($id)).'">'.esc_html(get_the_title($id)).'</a></h3>';
            if($author)echo '<p>'.esc_html($author).'</p>';
            echo '<a href="'.esc_url(get_permalink($id)).'">View book</a></div></article>';
        }
        wp_reset_postdata();
        echo '</div></section>';
        return ob_get_clean();
    }

    public static function register_routes() {
        register_rest_route('freebooks/v1','/catalogue/search',[
            'methods'=>'GET','callback'=>[self::class,'rest_search'],'permission_callback'=>'__return_true',
            'args'=>['q'=>['sanitize_callback'=>'sanitize_text_field'],'isbn'=>['sanitize_callback'=>'sanitize_text_field'],'limit'=>['default'=>10,'sanitize_callback'=>'absint']]
        ]);
        register_rest_route('freebooks/v1','/catalogue/isbn/(?P<isbn>[0-9Xx-]{10,17})',[
            'methods'=>'GET','callback'=>[self::class,'rest_isbn'],'permission_callback'=>'__return_true'
        ]);
    }

    public static function rest_search($request) {
        $term=sanitize_text_field((string)$request->get_param('q'));
        $isbn=sanitize_text_field((string)$request->get_param('isbn'));
        $limit=max(1,min(20,absint($request->get_param('limit')?:10)));
        $args=['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>'publish','posts_per_page'=>$limit,'s'=>$term,'no_found_rows'=>true];
        if($isbn!=='')$args['meta_query']=[['key'=>'_fb_isbn','value'=>$isbn,'compare'=>'LIKE']];
        $q=new WP_Query($args);$items=[];
        foreach($q->posts as $post) {
            $id=$post->ID;
            $items[]=['id'=>$id,'title'=>get_the_title($id),'url'=>get_permalink($id),'isbn'=>(string)get_post_meta($id,'_fb_isbn',true),'author'=>(string)get_post_meta($id,'_fb_author',true),'price'=>(float)get_post_meta($id,'_fb_price',true),'stock'=>(int)get_post_meta($id,'_fb_stock',true),'image'=>get_the_post_thumbnail_url($id,'medium')?:null];
        }
        return new WP_REST_Response(['count'=>count($items),'items'=>$items],200,['Cache-Control'=>'public, max-age=60']);
    }

    public static function rest_isbn($request) {
        $isbn=preg_replace('/[^0-9Xx]/','',(string)$request['isbn']);
        $q=new WP_Query(['post_type'=>FREEBOOKS_ITEM_CPT,'post_status'=>'publish','posts_per_page'=>10,'meta_query'=>[['key'=>'_fb_isbn','value'=>$isbn,'compare'=>'LIKE']]]);
        $items=[];
        foreach($q->posts as $post)$items[]=['id'=>$post->ID,'title'=>get_the_title($post->ID),'url'=>get_permalink($post->ID),'isbn'=>(string)get_post_meta($post->ID,'_fb_isbn',true),'author'=>(string)get_post_meta($post->ID,'_fb_author',true),'stock'=>(int)get_post_meta($post->ID,'_fb_stock',true)];
        return ['isbn'=>$isbn,'found'=>count($items)>0,'count'=>count($items),'items'=>$items];
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type='.FREEBOOKS_ITEM_CPT,'Discovery analytics','Discovery analytics','manage_options','fb-discovery-analytics',[self::class,'analytics_page']);
    }

    public static function analytics_page() {
        if(!current_user_can('manage_options'))return;
        $rows=get_option(self::SEARCH_OPTION,[]);if(!is_array($rows))$rows=[];
        uasort($rows,function($a,$b){return (int)($b['searches']??0)<=>(int)($a['searches']??0);});
        $zero=array_filter($rows,function($r){return (int)($r['zero_results']??0)>0;});
        echo '<div class="wrap"><h1>Book discovery analytics</h1><p>Search terms are stored as operational site-search analytics for administrators. Do not enter personal data into the site search box. Data is capped at 500 distinct terms.</p>';
        echo '<p><strong>Distinct search groups:</strong> '.absint(count($rows)).' &nbsp; <strong>Terms with zero-result searches:</strong> '.absint(count($zero)).'</p>';
        echo '<table class="widefat striped"><thead><tr><th>Search term</th><th>Searches</th><th>Zero results</th><th>Last searched (UTC)</th></tr></thead><tbody>';
        foreach(array_slice($rows,0,200) as $r)echo '<tr><td>'.esc_html($r['term']??'').'</td><td>'.absint($r['searches']??0).'</td><td>'.absint($r['zero_results']??0).'</td><td>'.esc_html($r['last_seen']??'').'</td></tr>';
        if(!$rows)echo '<tr><td colspan="4">No search activity recorded yet.</td></tr>';
        echo '</tbody></table><h2>Catalogue improvement opportunities</h2><p>Review frequent zero-result searches and add matching catalogue entries, improve ISBN/author metadata, or create a book request response. Search analytics do not prove demand or availability.</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=fb_discovery_export'),'fb_discovery_export')).'">Export search analytics CSV</a></p></div>';
    }

    public static function export_csv() {
        if(!current_user_can('manage_options')||!check_admin_referer('fb_discovery_export'))wp_die('Not authorised.',403);
        $rows=get_option(self::SEARCH_OPTION,[]);if(!is_array($rows))$rows=[];
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=freebooks-search-analytics-'.gmdate('Y-m-d').'.csv');
        $out=fopen('php://output','w');fputcsv($out,['Search term','Searches','Zero results','Last searched UTC']);
        foreach($rows as $r){$v=(string)($r['term']??'');if(preg_match('/^[\s]*[=+@\-]/u',$v))$v="'".$v;fputcsv($out,[$v,$r['searches']??0,$r['zero_results']??0,$r['last_seen']??'']);}
        fclose($out);exit;
    }
}
