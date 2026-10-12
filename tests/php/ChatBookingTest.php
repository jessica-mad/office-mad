<?php
use MAD_Oficina\Core\Approvals;
use MAD_Oficina\Core\Crypto;
use MAD_Oficina\Core\Schema;
use MAD_Oficina\Visitors\Leads;

/**
 * The first meeting arranged inside the visitor chat: real slots under the
 * receptionist's reply (filtered by the days the visitor mentions), then
 * name/email in the chat, with the conversation token instead of Turnstile.
 */
class ChatBookingTest extends MADOF_TestCase {

    private $answer = [];
    private $tz;

    public function set_up(){
        parent::set_up();
        if ( ! defined('MAD_OFICINA_ANTHROPIC_KEY') ) define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-test');
        update_option('timezone_string', 'Europe/Madrid');
        $this->tz = wp_timezone();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(1, 250);
        reset_phpmailer_instance();
        update_option(MAD_Oficina_Core::option_key('visitors'), ['chat_enabled' => true, 'knowledge' => 'MAD hace webs.', 'turnstile_secret' => Crypto::encrypt('s')]);
        $google = MAD_Oficina_Core::instance()->get_module('google')->oauth();
        update_option(MAD_Oficina_Core::option_key('google'), [
            'refresh_token' => Crypto::encrypt('r'), 'access_token' => Crypto::encrypt('a'), 'expires_at' => time() + 3600, 'scopes' => $google->required_scopes(),
        ]);
        add_filter('pre_http_request', [$this, 'fake'], 10, 3);
        wp_set_current_user(0);
    }

    public function tear_down(){
        remove_filter('pre_http_request', [$this, 'fake']);
        delete_option(MAD_Oficina_Core::option_key('visitors'));
        parent::tear_down();
    }

    public function fake($pre, $args, $url){
        if ( $url === MAD_Oficina\Visitors\Guard::TURNSTILE_VERIFY ) return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => $args['body']['response'] === 'good'])];
        if ( strpos($url, 'freeBusy') !== false ) return ['response' => ['code' => 200], 'body' => wp_json_encode(['calendars' => ['primary' => ['busy' => []]]])];
        if ( strpos($url, 'api.anthropic.com') === false ) return $pre;
        return ['response' => ['code' => 200], 'body' => wp_json_encode([
            'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'content' => [['type' => 'text', 'text' => wp_json_encode($this->answer + ['reply' => 'Te dejo aquí abajo algunos huecos.', 'suggest' => 'none', 'windows' => [], 'topic' => ''])]],
        ])];
    }

    private function post($route, array $body){
        $req = new WP_REST_Request('POST', '/mad-oficina/v1' . $route);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return rest_get_server()->dispatch($req);
    }

    private function next_weekday($name){
        return (new DateTimeImmutable('next ' . $name, $this->tz))->modify('+7 days')->format('Y-m-d');
    }

    public function test_slots_appear_in_the_chat_filtered_by_the_days_mentioned(){
        $thursday = $this->next_weekday('thursday');
        $this->answer = ['suggest' => 'first_meeting', 'windows' => [['date' => $thursday, 'from' => '15:00', 'to' => '20:00']], 'topic' => 'Tienda online para una panadería'];
        $d = $this->post('/public/chat', ['message' => 'Me va bien el jueves por la tarde', 'turnstile' => 'good'])->get_data();
        $this->assertSame('first_meeting', $d['suggest']);
        $this->assertNotEmpty($d['slots']);
        $this->assertArrayNotHasKey('slots_note', $d);
        $this->assertGreaterThan(1, count($d['slots']), 'several times on the day asked for');
        foreach ( $d['slots'] as $s ) {
            $this->assertSame($thursday, wp_date('Y-m-d', $s['start'], $this->tz));
            $this->assertGreaterThanOrEqual('15:00', wp_date('H:i', $s['start'], $this->tz));
            $this->assertSame(30 * 60, $s['end'] - $s['start']);
        }
    }

    public function test_nearest_slots_when_the_days_mentioned_are_full(){
        $sunday = $this->next_weekday('sunday');
        $this->answer = ['suggest' => 'first_meeting', 'windows' => [['date' => $sunday, 'from' => '09:00', 'to' => '20:00']]];
        $d = $this->post('/public/chat', ['message' => 'El domingo', 'turnstile' => 'good'])->get_data();
        $this->assertNotEmpty($d['slots']);
        $this->assertArrayHasKey('slots_note', $d);
    }

    public function test_no_slots_without_interest(){
        $d = $this->post('/public/chat', ['message' => '¿Qué hacéis?', 'turnstile' => 'good'])->get_data();
        $this->assertArrayNotHasKey('slots', $d);
    }

    public function test_booking_from_the_chat_uses_the_conversation(){
        $this->answer = ['suggest' => 'first_meeting', 'topic' => 'Tienda online para una panadería'];
        $d = $this->post('/public/chat', ['message' => 'Quiero hablar con Jessica', 'turnstile' => 'good'])->get_data();
        $start = $d['slots'][0]['start'];

        // No Turnstile token: the chat token is enough.
        $res = $this->post('/public/meeting', ['chat' => $d['token'], 'start' => $start, 'name' => 'Lúa', 'email' => 'lua@panaderia.example', 'consent' => 1]);
        $this->assertSame(200, $res->get_status());
        $lead = Leads::all('unverified')[0];
        $this->assertSame('Tienda online para una panadería', $lead['message'], 'topic summarised by the receptionist');
        $this->assertStringContainsString('charla', $lead['notes']);
        global $wpdb;
        $this->assertStringContainsString('Petición de reunión enviada', (string) $wpdb->get_var('SELECT body FROM ' . Schema::table('messages') . " WHERE thread LIKE 'visitor:%' ORDER BY id DESC LIMIT 1"));
        $this->assertSame([], Approvals::pending(), 'still waits for the email confirmation');

        // A forged chat token falls back to Turnstile.
        $this->assertSame(400, $this->post('/public/meeting', ['chat' => 'x.1.y', 'start' => $d['slots'][1]['start'], 'name' => 'B', 'email' => 'b@x.example', 'consent' => 1])->get_status());
    }
}
