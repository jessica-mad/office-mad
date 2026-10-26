<?php
/** The office page is a private area: the site's marketing trackers are taken out and it is not indexed. */
class PortalPrivacyTest extends MADOF_TestCase {

    public function test_tracker_scripts_and_snippets_are_removed_and_ours_kept(){
        $html = '<head><script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
            . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);} gtag('js', new Date());</script>"
            . "<script>!function(f,b,e,v,n,t,s){}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js'); fbq('init','1');</script>"
            . '<noscript><img src="https://www.facebook.com/tr?id=1&ev=PageView"></noscript>'
            . '<script src="https://a.omappapi.com/app/js/api.min.js"></script><script src="https://bzr.openai.com/x.js"></script>'
            . '<script type="module" src="/wp-content/plugins/office-mad/assets/game/portal.js" id="mad-oficina-portal-js-module"></script>'
            . '<script>var theme = 1;</script></head>';
        $out = \MAD_Oficina\Portal\Privacy::strip($html);
        foreach ( ['googletagmanager', 'fbq(', 'facebook.com/tr', 'omappapi', 'bzr.openai'] as $t ) $this->assertStringNotContainsString($t, $out);
        $this->assertStringContainsString('portal.js', $out, 'never the office itself');
        $this->assertStringContainsString('var theme = 1', $out, 'nor the theme');
    }

    public function test_enqueued_trackers_are_dequeued(){
        wp_enqueue_script('pys', 'https://example.org/pixelyoursite/public.js');
        wp_enqueue_script('gtm4wp-x', 'https://example.org/gtm.js');
        wp_enqueue_script('metricool-tracker', 'https://tracker.metricool.com/resources/be.js');
        wp_enqueue_script('theme-js', 'https://example.org/theme.js');
        wp_enqueue_style('optinmonster-css', 'https://example.org/om.css');
        \MAD_Oficina\Portal\Privacy::dequeue();
        $this->assertSame(['theme-js'], array_values(array_intersect(wp_scripts()->queue, ['pys', 'gtm4wp-x', 'metricool-tracker', 'theme-js'])));
        $this->assertNotContains('optinmonster-css', wp_styles()->queue);
    }
}
