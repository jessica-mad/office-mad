<?php
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Meetings\Cartero;
use MAD_Oficina\Meetings\Hooks;

/**
 * «El cartero»: when the Apps Script last came by, its code version and what it did.
 */
class CarteroTest extends MADOF_TestCase {

    public function set_up(){
        parent::set_up();
        update_option(MAD_Oficina_Core::option_key('meetings'), ['hook_secret' => Crypto::encrypt('test-secret-123')]);
        $GLOBALS['wp_rest_server'] = null;
    }

    private function hook($route, $body, $version = null){
        $req = new WP_REST_Request('POST', '/mad-oficina/v1' . $route);
        $raw = wp_json_encode($body);
        $ts = (string) time();
        $req->set_header('X-Mad-Timestamp', $ts);
        $req->set_header('X-Mad-Signature', Hooks::sign('test-secret-123', $ts, 'POST', '/mad-oficina/v1' . $route, $raw));
        if ( $version ) $req->set_header('X-Mad-Version', $version);
        $req->set_header('Content-Type', 'application/json'); $req->set_body($raw);
        return rest_do_request($req);
    }

    public function test_never_then_old_code_then_reporting(){
        $this->assertSame('never', Cartero::status()['state']);
        $this->assertStringContainsString('no ha pasado nunca', Cartero::render());

        // An old Code.gs: no version, no report.
        $this->assertSame(200, $this->hook('/hooks/tick', [])->get_status());
        $st = Cartero::status();
        $this->assertSame(['ok', false], [$st['state'], $st['version_ok']]);
        $this->assertStringContainsString('Code.gs antiguo', Cartero::render());

        // The new one tells what it did.
        $this->hook('/hooks/tick', ['report' => ['seconds' => 41, 'meetings' => 1, 'gmail' => 3, 'drive' => ['files' => 120, 'sent' => 25, 'pending' => 95, 'errors' => 1],
            'orders_done' => 2, 'orders_failed' => 0, 'errors' => ['No he podido entregar «<b>x</b>.pdf»: 500']]], Cartero::VERSION);
        $st = Cartero::status();
        $this->assertTrue($st['version_ok']);
        $this->assertSame(25, $st['last_run']['drive']['sent']);
        $this->assertSame('No he podido entregar «x.pdf»: 500', $st['errors'][0]['msg'], 'sanitized');
        $html = Cartero::render();
        $this->assertStringContainsString('Funciona', $html);
        $this->assertStringContainsString('120 archivos en las carpetas, 25 enviados', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);

        // A bad signature is not «seen».
        update_option(Cartero::OPTION, ['last_seen' => time() - 3600, 'version' => Cartero::VERSION]);
        $req = new WP_REST_Request('POST', '/mad-oficina/v1/hooks/tick');
        $req->set_header('X-Mad-Timestamp', (string) time()); $req->set_header('X-Mad-Signature', 'nope');
        $this->assertSame(401, rest_do_request($req)->get_status());
        $this->assertSame('late', Cartero::status()['state']);
        $this->assertStringContainsString('Activadores', Cartero::render());
    }
}
