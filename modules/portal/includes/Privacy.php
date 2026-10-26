<?php
namespace MAD_Oficina\Portal;

if ( ! defined('ABSPATH') ) exit;

/**
 * /oficina is a private area for clients: the site's marketing trackers (Meta pixel from
 * PixelYourSite, GTM/GA4, Metricool, OptinMonster, an OpenAI script) do not load there,
 * and search engines are told not to index it.
 */
final class Privacy {

    /** Handles and addresses of those trackers. */
    const HANDLES = '/^(pys|pixelyoursite|pixel-your-site|gtm4wp|google_gtagjs|google-tag-manager|gtag|ga4|metricool|optinmonster|omapi|om-|monsterinsights)/i';
    const SOURCES = '#(googletagmanager\\.com|google-analytics\\.com|connect\\.facebook\\.net|facebook\\.com/tr|pixelyoursite|metricool|omappapi\\.com|optinmonster|opmnstr\\.com|bzr\\.openai\\.com)#i';
    /** What their inline snippets call. */
    const CALLS = '#(fbq\\(|gtag\\(|googletagmanager|dataLayer\\.push|metricool|OptinMonster|omapi|bzr\\.openai)#i';

    public static function is_office_page(){
        if ( ! is_singular() ) return false;
        $post = get_post();
        return $post && has_shortcode((string) $post->post_content, 'mad_oficina');
    }

    public static function apply(){
        add_filter('wp_robots', fn($robots) => array_merge($robots, ['noindex' => true, 'nofollow' => true]), PHP_INT_MAX);
        foreach ( ['wp_enqueue_scripts' => PHP_INT_MAX, 'wp_print_styles' => 0, 'wp_print_scripts' => 0, 'wp_print_footer_scripts' => 0] as $hook => $prio ) add_action($hook, [self::class, 'dequeue'], $prio);
        // What the tracker plugins print straight into the page (inline snippets, <noscript> pixels), out too.
        ob_start([self::class, 'strip']);
    }

    public static function dequeue(){
        foreach ( ['script' => wp_scripts(), 'style' => wp_styles()] as $kind => $deps ) {
            foreach ( (array) $deps->queue as $handle ) {
                $src = (string) ($deps->registered[$handle]->src ?? '');
                if ( ! preg_match(self::HANDLES, $handle) && ! ($src !== '' && preg_match(self::SOURCES, $src)) ) continue;
                $kind === 'script' ? wp_dequeue_script($handle) : wp_dequeue_style($handle);
            }
        }
    }

    /** Removes the <script> and <noscript> blocks that load or call a tracker; never the office's own. */
    public static function strip($html){
        return preg_replace_callback('#<(script|noscript)\\b[^>]*>.*?</\\1>#is', function($m){
            if ( stripos($m[0], 'mad-oficina') !== false ) return $m[0];
            return preg_match(self::SOURCES, $m[0]) || preg_match(self::CALLS, $m[0]) ? '' : $m[0];
        }, (string) $html);
    }
}
