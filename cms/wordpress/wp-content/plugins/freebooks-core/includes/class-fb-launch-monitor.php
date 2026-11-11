<?php
if (!defined('ABSPATH')) exit;

/**
 * Launch monitoring and public-frontend SEO handoff.
 * The WordPress installation is a noindex CMS origin; public SEO belongs to Next.js.
 */
class FB_Launch_Monitor {
    const LOG_OPTION = 'fb_launch_monitor_history';
    const CRON_HOOK = 'fb_launch_monitor_weekly';

    public static function init() {
        add_action('init',[self::class,'schedule']);
        add_action(self::CRON_HOOK,[self::class,'run_checks']);
        add_action('admin_menu',[self::class,'menu'],60);
        add_action('admin_post_fb_launch_monitor_run',[self::class,'manual_run']);
        add_action('rest_api_init',[self::class,'register_routes']);
        add_filter('site_status_tests',[self::class,'site_health_tests']);
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time()+3600,'weekly',self::CRON_HOOK);
    }

    public static function register_routes() {
        register_rest_route('freebooks/v1','/frontend-config',[
            'methods'=>'GET',
            'callback'=>[self::class,'frontend_config'],
            'permission_callback'=>'__return_true'
        ]);
    }

    /** Public values only: never return the revalidation secret or private settings. */
    public static function frontend_config($request) {
        $settings=(array)get_option('freebooks_settings',[]);
        $turnstile_site_key=(!empty($settings['turnstile_site_key']) && !empty($settings['turnstile_secret_key'])) ? sanitize_text_field((string)$settings['turnstile_site_key']) : '';
        $public_settings=(array)get_option('freebooks_settings',[]);
        $contact=[
            'foundationName'=>sanitize_text_field((string)($public_settings['foundation_name']??'SamagraShriSai Foundation')),
            'phone'=>sanitize_text_field((string)($public_settings['contact_phone']??'')),
            'email'=>sanitize_email((string)($public_settings['contact_email']??'')),
            'address'=>sanitize_textarea_field((string)($public_settings['contact_address']??'')),
            'whatsapp'=>esc_url_raw((string)($public_settings['whatsapp']??'')),
            'facebook'=>esc_url_raw((string)($public_settings['facebook']??'')),
            'instagram'=>esc_url_raw((string)($public_settings['instagram']??'')),
            'youtube'=>esc_url_raw((string)($public_settings['youtube']??'')),
            'linkedin'=>esc_url_raw((string)($public_settings['linkedin']??'')),
        ];
        $data=[
            'turnstileSiteKey'=>$turnstile_site_key,
            'frontendUrl'=>esc_url_raw((string)get_option('freebooks_next_url','')),
            'sitemapUrl'=>esc_url_raw((string)get_option('freebooks_public_sitemap_url','')),
            'contact'=>$contact,
            'impact'=>class_exists('FB_Impact') ? FB_Impact::public_metrics() : [],
            'analytics'=>[
                'provider'=>'google-analytics-4',
                'measurementId'=>sanitize_text_field((string)get_option('freebooks_ga4_measurement_id','')),
                'consentRequired'=>get_option('freebooks_analytics_consent_required','1')==='1'
            ],
            'verification'=>[
                'google'=>sanitize_text_field((string)get_option('freebooks_gsc_verification','')),
                'bing'=>sanitize_text_field((string)get_option('freebooks_bing_verification',''))
            ],
            'cms'=>['indexing'=>'disabled','sitemap'=>'disabled']
        ];
        return new WP_REST_Response($data,200,['Cache-Control'=>'public, max-age=300']);
    }

    public static function menu() {
        add_submenu_page('edit.php?post_type='.FREEBOOKS_DONATION_CPT,'SEO, performance & monitoring','SEO & monitoring','manage_options','freebooks-seo-monitor',[self::class,'page']);
    }

    private static function check($key,$label,$ok,$detail,$severity='warning') {
        return ['key'=>$key,'label'=>$label,'ok'=>(bool)$ok,'detail'=>$detail,'severity'=>$severity];
    }

    public static function diagnostics() {
        global $wpdb;
        $checks=[];
        $checks[]=self::check('php','PHP version',version_compare(PHP_VERSION,'8.1','>='),'Running PHP '.PHP_VERSION.'. PHP 8.1 or newer is required.','critical');
        $checks[]=self::check('wp','WordPress version',version_compare(get_bloginfo('version'),'6.3','>='),'WordPress '.get_bloginfo('version').'. Keep WordPress and extensions updated.');
        $checks[]=self::check('db', 'Database connection', (bool)$wpdb->get_var('SELECT 1'), 'A lightweight database connection query was executed.','critical');
        $checks[]=self::check('https','CMS HTTPS',is_ssl(),'Production CMS should use HTTPS. Local development may use HTTP.','critical');
        $checks[]=self::check('wc','WooCommerce',class_exists('WooCommerce'),'WooCommerce '.(class_exists('WooCommerce')? 'is active':'is not active').'. Required for store orders and checkout.');
        $checks[]=self::check('frontend','Public frontend URL',(bool)get_option('freebooks_next_url'),'Configure the production Next.js URL under Settings → FreeBooks settings.','critical');
        $sitemap=(string)get_option('freebooks_public_sitemap_url','');
        $checks[]=self::check('sitemap','Public sitemap URL',$sitemap!=='' && wp_http_validate_url($sitemap),$sitemap?:'Add the Next.js sitemap URL, for example https://freebook.co.in/sitemap.xml.');
        $ga=(string)get_option('freebooks_ga4_measurement_id','');
        $checks[]=self::check('analytics','GA4 measurement ID',(bool)preg_match('/^G-[A-Z0-9]{4,20}$/',$ga),$ga?:'Add GA4 Measurement ID after creating the analytics property. Consent must be enforced in the public frontend.');
        $checks[]=self::check('consent','Analytics consent gate',get_option('freebooks_analytics_consent_required','1')==='1','Consent requirement is '.(get_option('freebooks_analytics_consent_required','1')==='1'?'enabled':'disabled').'. Confirm the Next.js consent manager actually enforces it.');
        $checks[]=self::check('gsc','Google Search Console verification',(bool)get_option('freebooks_gsc_verification'),'Add the verification token after creating the Search Console property.');
        $checks[]=self::check('bing','Bing Webmaster verification',(bool)get_option('freebooks_bing_verification'),'Add the verification token if Bing Webmaster Tools will be used.');
        $checks[]=self::check('cms_noindex','CMS indexing protection',true,'This plugin marks the WordPress CMS frontend noindex/nofollow and disables its sitemap. Submit only the public Next.js sitemap.');
        $checks[]=self::check('permalinks','CMS permalinks',(bool)get_option('permalink_structure'),'Pretty permalinks should be enabled for WordPress REST routes and admin-linked content.');
        $checks[]=self::check('mail','Mail configuration',is_email(get_option('admin_email')),'Admin email: '.(string)get_option('admin_email').'. This does not prove SMTP delivery; send test emails.');
        $free= function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
        $checks[]=self::check('disk','Server disk space',$free===false || $free>1024*1024*1024,$free===false?'Disk free space could not be measured.':size_format($free).' free. Keep at least 1 GB available.','critical');
        $checks[]=self::check('cron','WP-Cron',defined('DISABLE_WP_CRON') ? !DISABLE_WP_CRON : true,defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON?'DISABLE_WP_CRON is enabled; configure a real server cron for scheduled tasks.':'WP-Cron is not disabled. Confirm scheduled events execute on the host.');
        return $checks;
    }

    public static function run_checks() {
        $checks=self::diagnostics();
        $rows=get_option(self::LOG_OPTION,[]);
        if(!is_array($rows))$rows=[];
        $rows[]=['time'=>current_time('mysql',true),'version'=>FREEBOOKS_CORE_VERSION,'checks'=>$checks,'failed'=>count(array_filter($checks,function($c){return !$c['ok'];}))];
        update_option(self::LOG_OPTION,array_slice($rows,-30),false);
        return $checks;
    }

    public static function manual_run() {
        if(!current_user_can('manage_options')||!check_admin_referer('fb_launch_monitor_run'))wp_die('Not authorised.',403);
        self::run_checks();
        wp_safe_redirect(add_query_arg('fb_monitor_ran','1',admin_url('edit.php?post_type='.FREEBOOKS_DONATION_CPT.'&page=freebooks-seo-monitor')));
        exit;
    }

    public static function page() {
        if(!current_user_can('manage_options'))return;
        $history=(array)get_option(self::LOG_OPTION,[]);
        $last=$history?end($history):null;
        $checks=$last['checks']??self::diagnostics();
        $failed=count(array_filter($checks,function($c){return !$c['ok'];}));
        echo '<div class="wrap"><h1>SEO, performance & launch monitoring</h1>';
        if(isset($_GET['fb_monitor_ran']))echo '<div class="notice notice-success"><p>Monitoring checks completed.</p></div>';
        echo '<p><strong>'.esc_html(count($checks)-$failed).' / '.esc_html(count($checks)).' checks passed.</strong> '.($last?'Last run: '.esc_html($last['time']).' UTC':'No saved run yet; run the checks below.').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="fb_launch_monitor_run">';
        wp_nonce_field('fb_launch_monitor_run');submit_button('Run checks now','primary','submit',false);echo '</form>';
        echo '<h2>CMS and public frontend checks</h2><table class="widefat striped"><thead><tr><th>Area</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        foreach($checks as $c)echo '<tr><td><strong>'.esc_html($c['label']).'</strong></td><td>'.($c['ok']?'<span style="color:#176b55;font-weight:700">PASS</span>':'<span style="color:#9a5c30;font-weight:700">REVIEW</span>').'</td><td>'.esc_html($c['detail']).'</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Public frontend integration</h2><p>Public configuration endpoint: <code>'.esc_html(rest_url('freebooks/v1/frontend-config')).'</code></p>';
        echo '<p>This endpoint exposes only the public frontend URL, public sitemap URL, GA4 measurement ID, consent requirement and search-engine verification tokens. It never exposes the revalidation secret.</p>';
        echo '<h2>SEO ownership</h2><ul><li>WordPress CMS: noindex/nofollow; WordPress sitemap disabled.</li><li>Next.js public site: owns canonical URLs, title/description, Open Graph, JSON-LD, robots.txt and XML sitemap.</li><li>Keep cart, checkout, account, search-filter combinations and private portals out of the public sitemap.</li><li>Keep sold single-copy catalogue pages live with a clear sold-out state if they have useful content.</li></ul>';
        echo '<h2>Performance acceptance targets</h2><ul><li>Core Web Vitals: LCP ≤ 2.5 seconds, INP ≤ 200 ms, CLS ≤ 0.1 at the 75th percentile.</li><li>Mobile Lighthouse target: 90+ for Performance, Accessibility, Best Practices and SEO where practical.</li><li>Use responsive WebP/AVIF images, explicit width/height, lazy-load below-the-fold images, preload only the true hero image, and avoid loading analytics before consent.</li><li>Use CDN/page caching and static generation or ISR for public content; do not depend on a persistent worker or Redis.</li></ul>';
        echo '<h2>Recent monitoring runs</h2><table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Version</th><th>Flagged checks</th></tr></thead><tbody>';
        foreach(array_slice(array_reverse($history),0,15) as $r)echo '<tr><td>'.esc_html($r['time']??'').'</td><td>'.esc_html($r['version']??'').'</td><td>'.absint($r['failed']??0).'</td></tr>';
        echo '</tbody></table><p class="description">Weekly checks run through WP-Cron when the site receives traffic. Configure a server cron on low-traffic production hosting and verify the event runs. Monitoring is diagnostic; it does not measure real-user Core Web Vitals or guarantee uptime.</p></div>';
    }

    public static function site_health_tests($tests) {
        $tests['direct']['freebooks_launch_monitor']=[
            'label'=>'FreeBooks launch configuration',
            'test'=>function(){
                $checks=self::diagnostics();$failed=array_values(array_filter($checks,function($c){return !$c['ok'];}));
                if(!$failed)return ['label'=>'FreeBooks launch configuration','status'=>'good','badge'=>['label'=>'FreeBooks','color'=>'green'],'description'=>'The configured CMS and public frontend checks passed. Manual acceptance tests are still required.','actions'=>''];
                $names=implode(', ',array_map(function($c){return $c['label'];},array_slice($failed,0,5)));
                return ['label'=>'FreeBooks launch configuration','status'=>'recommended','badge'=>['label'=>'FreeBooks','color'=>'blue'],'description'=>'Review these configuration items: '.esc_html($names).'.','actions'=>''];
            }
        ];
        return $tests;
    }
}
