<?php
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Core\Repo\Clients;
use MAD_Oficina\Visitors\Leads;

/**
 * Public reception: visitor briefs, email confirmation, Turnstile, limits,
 * «Nuevo encargo» from clients and turning a lead into a client.
 */
class VisitorsTest extends MADOF_TestCase {

    private $turnstile_ok = true;
    private $created = [];

    public function set_up(){
        parent::set_up();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(1, 250); // fresh rate-limit bucket per test
        reset_phpmailer_instance();
        add_filter('pre_http_request', [$this, 'fake_turnstile'], 10, 3);
        add_action('madoficina_lead_created', [$this, 'on_created']);
        wp_set_current_user(0);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake_turnstile']);
        remove_action('madoficina_lead_created', [$this, 'on_created']);
        delete_option(MAD_Oficina_Core::option_key('visitors'));
        parent::tear_down();
    }

    public function on_created($id){ $this->created[] = $id; }

    public function fake_turnstile($pre, $args, $url){
        if ( $url !== MAD_Oficina\Visitors\Guard::TURNSTILE_VERIFY ) return $pre;
        return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => $this->turnstile_ok && $args['body']['response'] === 'good-token'])];
    }

    private function post($route, array $body){
        $req = new WP_REST_Request('POST', '/mad-oficina/v1' . $route);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return rest_get_server()->dispatch($req);
    }

    private function brief(array $over = []){
        return $over + ['name' => 'Marta Ruiz', 'email' => 'marta@tallerazul.example', 'company' => 'Taller Azul', 'website' => 'tallerazul.example',
            'services' => ['web', 'ads', 'hack'], 'message' => 'Queremos renovar la tienda online y empezar con anuncios.', 'timeline' => '1-3m', 'budget' => '1-3k', 'consent' => 1];
    }

    private function token_from_mail(){
        $mail = tests_retrieve_phpmailer_instance()->get_sent(0);
        $this->assertNotEmpty($mail, 'confirmation email sent');
        preg_match('#lead=(\d+)&token=([a-f0-9]+)#', html_entity_decode($mail->body), $m);
        return [(int) $m[1], $m[2], $mail];
    }

    public function test_visitor_brief_waits_for_email_confirmation(){
        $res = $this->post('/public/brief', $this->brief());
        $this->assertSame(200, $res->get_status());
        $this->assertTrue($res->get_data()['verify']);

        [$id, $token, $mail] = $this->token_from_mail();
        $this->assertSame('marta@tallerazul.example', $mail->to[0][0]);
        $lead = Leads::get($id);
        $this->assertNull($lead['verified_at']);
        $this->assertSame(['web', 'ads'], $lead['services'], 'unknown options are dropped');
        $this->assertSame('https://tallerazul.example', $lead['website']);
        $this->assertNotEmpty($lead['consent_at']);
        $this->assertSame(64, strlen($lead['ip_hash']));
        $this->assertSame([], Leads::all(), 'unconfirmed leads are not listed');
        $this->assertSame([], $this->created, 'Jessica is not told yet');

        $this->assertWPError(Leads::verify($id, 'wrong-token'));
        $this->assertNotWPError(Leads::verify($id, $token));
        $this->assertCount(1, Leads::all());
        $this->assertWPError(Leads::verify($id, $token), 'single use');
    }

    public function test_consent_honeypot_and_validation(){
        $this->assertSame(400, $this->post('/public/brief', $this->brief(['consent' => 0]))->get_status());
        $this->assertSame(400, $this->post('/public/brief', $this->brief(['email' => 'no-es-un-email']))->get_status());
        $this->assertSame(400, $this->post('/public/brief', $this->brief(['message' => 'hola']))->get_status());

        $bot = $this->post('/public/brief', $this->brief(['fax' => 'http://spam.example']));
        $this->assertSame(200, $bot->get_status(), 'bots get a normal answer');
        global $wpdb;
        $this->assertSame(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . MAD_Oficina\Core\Schema::table('leads')), '…and nothing is stored');
        $this->assertEmpty(tests_retrieve_phpmailer_instance()->get_sent(0));
    }

    public function test_turnstile_is_checked_when_configured(){
        update_option(MAD_Oficina_Core::option_key('visitors'), ['turnstile_site' => 'site', 'turnstile_secret' => Crypto::encrypt('secret')]);
        $this->assertSame(400, $this->post('/public/brief', $this->brief())->get_status());
        $this->assertSame(400, $this->post('/public/brief', $this->brief(['turnstile' => 'bad-token']))->get_status());
        $this->assertSame(200, $this->post('/public/brief', $this->brief(['turnstile' => 'good-token']))->get_status());
    }

    public function test_briefs_are_rate_limited(){
        for ( $i = 0; $i < 3; $i++ ) $this->assertSame(200, $this->post('/public/brief', $this->brief(['email' => "p{$i}@x.example"]))->get_status());
        $this->assertSame(429, $this->post('/public/brief', $this->brief(['email' => 'otra@x.example']))->get_status());
    }

    public function test_client_brief_uses_the_session_client_and_needs_no_confirmation(){
        $mine = $this->make_client('AAA', 'Cliente A');
        $other = $this->make_client('BBB');
        wp_set_current_user($this->make_client_user($mine));
        $res = $this->post('/portal/brief', ['message' => 'Necesitamos un vídeo para Navidad.', 'services' => ['video'], 'client' => $other, 'client_id' => $other]);
        $this->assertSame(200, $res->get_status());
        $this->assertFalse($res->get_data()['verify']);
        $lead = Leads::all()[0];
        $this->assertSame($mine, $lead['client_id']);
        $this->assertSame('portal', $lead['source']);
        $this->assertSame('Cliente A', $lead['company']);
        $this->assertSame([$lead['id']], $this->created, 'Jessica is told straight away');

        wp_set_current_user($this->make_admin());
        $this->assertSame(403, $this->post('/portal/brief', ['message' => 'Prueba desde gerencia.'])->get_status());
        wp_set_current_user(0);
        $this->assertSame(401, $this->post('/portal/brief', ['message' => 'Sin sesión no se puede.'])->get_status());
    }

    public function test_lead_becomes_a_client_with_its_contact(){
        $this->make_client('TA', 'Ya existe');
        $id = Leads::create(['name' => 'Marta Ruiz', 'email' => 'marta@tallerazul.example', 'company' => 'Taller Azul', 'verified_at' => gmdate('Y-m-d H:i:s')]);
        $client_id = Leads::convert_to_client($id);
        $client = Clients::get($client_id);
        $this->assertSame('Taller Azul', $client['name']);
        $this->assertSame('TA2', $client['code'], 'a free code');
        $this->assertSame(['tallerazul.example'], $client['domains']);
        $this->assertSame('marta@tallerazul.example', Clients::contacts($client_id)[0]['emails'][0]['email']);
        $this->assertSame('client', Leads::get($id)['status']);
        $this->assertSame($client_id, Leads::convert_to_client($id), 'idempotent');

        $gmail = Leads::create(['name' => 'Pablo', 'email' => 'pablo@gmail.com', 'verified_at' => gmdate('Y-m-d H:i:s')]);
        $this->assertSame([], Clients::get(Leads::convert_to_client($gmail))['domains'], 'webmail domains are not client domains');

        Clients::delete($client_id);
        $this->assertNull(Leads::get($id), 'erasing a client erases its leads');
    }

    public function test_unconfirmed_leads_are_purged(){
        $old = Leads::create(['name' => 'A', 'email' => 'a@x.example']);
        $new = Leads::create(['name' => 'B', 'email' => 'b@x.example']);
        $ok = Leads::create(['name' => 'C', 'email' => 'c@x.example', 'verified_at' => gmdate('Y-m-d H:i:s')]);
        global $wpdb;
        $wpdb->update(MAD_Oficina\Core\Schema::table('leads'), ['created_at' => gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS)], ['id' => $old]);
        $wpdb->update(MAD_Oficina\Core\Schema::table('leads'), ['created_at' => gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS)], ['id' => $ok]);
        Leads::purge_unverified(7);
        $this->assertNull(Leads::get($old));
        $this->assertNotNull(Leads::get($new));
        $this->assertNotNull(Leads::get($ok));
    }

    public function test_visitors_get_the_open_reception_without_nonce_or_client_data(){
        $html = do_shortcode('[mad_oficina]');
        $this->assertStringContainsString('id="mad-oficina"', $html);
        preg_match('/data-config="([^"]+)"/', $html, $m);
        $config = json_decode(html_entity_decode($m[1]), true);
        $this->assertTrue($config['visitor']);
        $this->assertSame('', $config['nonce']);
        $this->assertArrayHasKey('recepcionista', $config['characters']);
        $this->assertArrayNotHasKey('clients', $config);
    }
}
